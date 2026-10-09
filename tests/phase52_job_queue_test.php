<?php
declare(strict_types=1);

/**
 * The background queue: claiming, fencing, recovery, and ownership.
 *
 * Run against SQLite with the jobs table taken from database/schema.sql, so a
 * column the code writes and the schema lacks fails here. The worker itself
 * runs in this process on a temporary store: each job below really copies,
 * extracts or deletes files, and each check looks at the disk.
 *
 * What a real deployment adds -- two worker processes racing on MariaDB, a
 * worker killed with SIGKILL, a browser closed mid-task -- is exercised
 * against a live server; see the README's "Background tasks" section.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CloudHub\\')) return;
    $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, 9)).'.php';
    if (is_file($file)) require $file;
});

use CloudHub\Repositories\JobRepository;
use CloudHub\Services\FileService;
use CloudHub\Services\Jobs\BaseJobType;
use CloudHub\Services\Jobs\ExtractJob;
use CloudHub\Services\Jobs\JobContext;
use CloudHub\Services\Jobs\JobEnvironment;
use CloudHub\Services\Jobs\JobTypes;
use CloudHub\Services\Jobs\PurgeJob;
use CloudHub\Services\Jobs\Worker;
use CloudHub\Services\Jobs\WorkerRegistry;

// Audit and ledger failures are logged, not thrown; keep them out of the output.
ini_set('error_log', sys_get_temp_dir().'/cloudhub-p52-'.getmypid().'.log');

function rmrf52(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf52($p.'/'.$n); @rmdir($p); }
}

$checks = [];
// This installation's own duplicate scan, which nothing here may touch.
$realScan = dirname(__DIR__).'/storage/.cache/duplicates.json';
$realScanBefore = is_file($realScan) ? md5_file($realScan) : null;
$base = sys_get_temp_dir().'/cloudhub-p52-'.bin2hex(random_bytes(5));
$root = $base.'/files';
mkdir($root, 0775, true);
mkdir($base.'/project/storage/.cache', 0775, true);

// --- the database ----------------------------------------------------------------

$schema = (string)file_get_contents(dirname(__DIR__).'/database/schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS jobs \((.*?)\) ENGINE=[^;]*;/s', $schema, $m);
$checks['schema.sql defines the jobs table'] = isset($m[1]);
$ddl = preg_replace(['/^\s*INDEX .*$/m', '/ENUM\([^)]*\)/', '/\bUNSIGNED\b/'], ['', 'TEXT', ''], $m[1] ?? '');
$ddl = rtrim(trim((string)$ddl), ',');
$ddl = preg_replace('/,\s*$/', '', (string)$ddl);

$newDb = static function () use ($ddl): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
    $db->exec('CREATE TABLE jobs ('.$ddl.')');
    $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, role TEXT, is_active INTEGER,
        created_at TEXT, last_login_at TEXT)");
    $db->exec("INSERT INTO users VALUES (1, 'ed', '', 'editor', 1, NULL, NULL), (2, 'vi', '', 'viewer', 1, NULL, NULL),
        (3, 'ad', '', 'admin', 1, NULL, NULL)");
    $db->exec('CREATE TABLE file_metadata (id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL, file_path TEXT NOT NULL,
        original_name TEXT NOT NULL, size INTEGER NOT NULL, mime_type TEXT, uploaded_by INTEGER)');
    $db->exec('CREATE TABLE storage_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, is_default INTEGER)');
    $db->exec('INSERT INTO storage_servers (is_default) VALUES (1)');
    $db->exec('CREATE TABLE security_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, username TEXT, event_type TEXT,
        outcome TEXT, ip_address TEXT, user_agent TEXT, request_id TEXT, context_json TEXT, created_at TEXT)');
    return $db;
};

// --- the repository ----------------------------------------------------------------

$db = $newDb();
$now = 1_900_000_000;
$repo = new JobRepository($db, static function () use (&$now): int { return $now; });
$raised = static function (callable $fn): ?int {
    try { $fn(); return null; } catch (RuntimeException $e) { return (int)$e->getCode(); }
};

$job = $repo->create(1, 'copy', 'Copy', '/', ['paths' => ['/a']]);
$checks['a job is queued pending, with a 32-hex id'] = $job['status'] === 'pending' && JobRepository::validId($job['id']);
$checks['ids that are not 32 lower-case hex name no job'] = !JobRepository::validId('../../etc')
    && !JobRepository::validId(strtoupper($job['id'])) && $repo->find("' OR 1=1 --") === null;
$checks["another account's job is not found"] = $repo->findFor($job['id'], 2) === null && $repo->findFor($job['id'], 1) !== null;

$claimed = $repo->claimNext('cli:host:1:aaaaaa');
$checks['a worker claims it, with a token'] = $claimed !== null && $claimed['status'] === 'processing'
    && JobRepository::validId((string)$claimed['claim_token']) && $claimed['attempts'] === 1;
$checks['no second worker can claim the same job'] = $repo->claimNext('cli:host:2:bbbbbb') === null;

$token = (string)$claimed['claim_token'];
$checks['the claim-holder reports progress'] = $repo->heartbeat($job['id'], $token, 5, 10, 'items', 'x') === false;
$checks['a stale token cannot'] = $repo->heartbeat($job['id'], str_repeat('0', 32), 5, 10, 'items', null) === null;
$checks['nor finish the job'] = $repo->complete($job['id'], str_repeat('0', 32), []) === false && $repo->find($job['id'])['status'] === 'processing';
$checks['nor save its state'] = $repo->saveState($job['id'], str_repeat('0', 32), ['x' => 1]) === false;

$checks["another account cannot cancel it"] = $raised(fn() => $repo->requestCancel($job['id'], 2, true)) === 404;
$checks['cancelling a running job asks it to stop'] = $repo->requestCancel($job['id'], 1, true) === 'cancelling'
    && $repo->heartbeat($job['id'], $token, 6, 10, 'items', null) === true;
$checks['a job that cannot stop part way refuses'] = $raised(fn() => $repo->requestCancel($job['id'], 1, false)) === 409;
$checks['the claim-holder records the cancellation'] = $repo->cancelled($job['id'], $token, ['copied' => 1])
    && $repo->find($job['id'])['status'] === 'cancelled' && $repo->find($job['id'])['claim_token'] === null;

$repo->saveState($job['id'], $token, ['placed' => [0 => '/x']]);
$db->exec("UPDATE jobs SET state = '{\"placed\":{\"0\":\"/x\"}}' WHERE id = '".$job['id']."'");
$checks["another account cannot retry it"] = $repo->retry($job['id'], 2) === false;
$checks['a cancelled job is retried, keeping what it saved'] = $repo->retry($job['id'], 1)
    && ($r = $repo->find($job['id']))['status'] === 'pending' && $r['attempts'] === 0 && $r['state'] === ['placed' => ['/x']]
    && $r['progress_done'] === 0;
$checks['a job that is not finished cannot be removed'] = $repo->remove($job['id'], 1) === false;
$checks['a pending job is cancelled on the spot'] = $repo->requestCancel($job['id'], 1, true) === 'cancelled';
$checks["another account cannot remove it"] = $repo->remove($job['id'], 2) === false;
$checks['its owner can'] = $repo->remove($job['id'], 1) && $repo->find($job['id']) === null;

$locked = $repo->create(1, 'purge', 'Empty', '', []);
$checks['a job that cannot be called off once queued refuses'] = $raised(fn() => $repo->requestCancel($locked['id'], 1, false, false)) === 409;
$db->exec('DELETE FROM jobs');

// Stale recovery, with a clock that moves.
$a = $repo->create(1, 'copy', 'A', '/', []);
$b = $repo->create(1, 'copy', 'B', '/', []);
$c = $repo->create(1, 'copy', 'C', '/', []);
$claims = [];
foreach (['w1', 'w2', 'w3'] as $w) { $got = $repo->claimNext($w); $claims[$got['id']] = $got; }
$ca = $claims[$a['id']];
$db->exec("UPDATE jobs SET attempts = 3 WHERE id = '".$b['id']."'");
$repo->requestCancel($c['id'], 1, true);
$now += 60;
$checks['a job heard from recently is left alone'] = $repo->recoverStale(120, 3) === ['requeued' => [], 'failed' => [], 'cancelled' => []];
$repo->heartbeat($a['id'], (string)$ca['claim_token'], 1, 2, 'items', null, $now + 3600);
$now += 121;
$stale = $repo->recoverStale(120, 3);
$checks['a quiet job goes back to the queue, a spent one fails, a cancelled one ends cancelled'] =
    $stale['requeued'] === [] && $stale['failed'] === [$b['id']] && $stale['cancelled'] === [$c['id']];
$checks['a job whose lease runs on is not taken for dead'] = $repo->find($a['id'])['status'] === 'processing';
$now += 3600;
$checks['until the lease has run out too'] = $repo->recoverStale(120, 3)['requeued'] === [$a['id']];
$checks["and the old claim is dead: the stalled worker's writes change nothing"] =
    $repo->heartbeat($a['id'], (string)$ca['claim_token'], 2, 2, 'items', null) === null
    && $repo->complete($a['id'], (string)$ca['claim_token'], []) === false
    && $repo->find($a['id'])['status'] === 'pending';
$checks['the spent job says why it failed'] = str_contains((string)$repo->find($b['id'])['error'], 'stopped 3 times');

$again = $repo->claimNext('w4');
$checks['a released claim gives its attempt back'] = $again !== null && $again['attempts'] === 2
    && $repo->release($again['id'], (string)$again['claim_token']) && $repo->find($again['id'])['attempts'] === 1;
$checks['statuses() names only recorded jobs'] = $repo->statuses([$a['id'], str_repeat('f', 32), '../x']) === [$a['id'] => 'pending'];

$d1 = $repo->create(1, 'duplicates', 'D1', '/', []);
$d2 = $repo->create(2, 'duplicates', 'D2', '/', []);
$db->exec("UPDATE jobs SET status = 'processing', claim_token = 'x', started_at = '2030-01-01 00:00:01' WHERE id IN ('".$d1['id']."','".$d2['id']."')");
$db->exec("UPDATE jobs SET started_at = '2030-01-01 00:00:00' WHERE id = '".$d2['id']."'");
$checks['two claims on an exclusive type agree on one winner'] = $repo->exclusiveWinner('duplicates') === $d2['id'];

// --- the worker, on a real store ---------------------------------------------------

$config = ['root_dir' => $root, 'read_only' => false, 'allow_overwrite' => true, 'allow_delete' => true,
    'storage_limit_gb' => 0, 'user_quota_gb' => 0, 'usage_cache_seconds' => 300,
    'queue_max_attempts' => 3, 'queue_stale_seconds' => 120, 'queue_retention_hours' => 24,
    'queue_extract_max_files' => 20000, 'queue_extract_max_gb' => 20,
    'duplicate_min_bytes' => 1, 'duplicate_scan_seconds' => 8, 'duplicate_max_files' => 50000];
$fs = new FileService($config);
$db = $newDb();
$env = new JobEnvironment($config, $fs, $db, $base.'/project');
$registry = new WorkerRegistry($base.'/project/storage/.cache/queue');

/** A type the test drives from inside run(): interruption, a lost claim, a crash. */
final class ProbeJob52 extends BaseJobType
{
    public static ?Closure $body = null;
    public function name(): string { return 'probe'; }
    public function writesStore(array $payload): bool { return false; }
    public function prepare(array $params, JobEnvironment $env, array $user): array { return ['label' => 'Probe', 'target' => '/', 'payload' => []]; }
    public function run(JobContext $ctx): array { return (self::$body)($ctx); }
}
$types = new JobTypes(...[...array_map(static fn(string $n) => JobTypes::standard()->get($n),
    ['copy', 'archive', 'extract', 'purge', 'thumbnails', 'duplicates', 'checksum']), new ProbeJob52()]);
$worker = new Worker($env, $types, $registry, 'cli');
$jobs = $env->jobs();
$user = ['id' => 1, 'username' => 'ed', 'role' => 'editor'];
$queue = static function (string $type, array $params, int $owner = 1) use ($types, $env, $jobs, $user): array {
    $prepared = $types->get($type)->prepare($params, $env, $user);
    return $jobs->create($owner, $type, $prepared['label'], $prepared['target'], $prepared['payload']);
};
$run = static function (array $job) use ($worker, $jobs): array {
    $worker->runOnce();
    return $jobs->find($job['id']) ?? [];
};
$tree = static function (string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) $out[] = substr($f->getPathname(), strlen($dir)).($f->isDir() ? '/' : '='.file_get_contents($f->getPathname()));
    sort($out);
    return $out;
};

mkdir($root.'/src/inner', 0775, true);
file_put_contents($root.'/src/a.txt', 'alpha');
file_put_contents($root.'/src/inner/b.txt', str_repeat('b', 20000));
mkdir($root.'/dest', 0775, true);

// Copy.
$copy = $queue('copy', ['paths' => ['/src'], 'destination' => '/dest']);
$checks['a copy is prepared with canonical paths'] = $copy['payload'] === ['paths' => ['/src'], 'destination' => '/dest'];
$done = $run($copy);
$checks['the worker copies the folder'] = $done['status'] === 'completed' && $tree($root.'/dest/src') === $tree($root.'/src');
$checks['and reports it, with progress in bytes'] = $done['result']['items'] === ['/dest/src'] && $done['progress_unit'] === 'bytes'
    && $done['progress_done'] === 20005 && $done['progress_total'] === 20005;
$checks['the copy is charged to whoever queued it'] =
    $db->query("SELECT COUNT(*) FROM file_metadata WHERE uploaded_by = 1 AND file_path LIKE '/dest/src/%'")->fetchColumn() == 2;
$checks['it leaves no staging behind'] = !is_dir($root.'/.jobs/'.$copy['id']);
$checks['the audit trail names the owner'] = $db->query("SELECT username FROM security_events WHERE event_type = 'job.copy'")->fetchColumn() === 'ed';
$checks['and the session is put back as it was'] = !isset($_SESSION);

$again = $run($queue('copy', ['paths' => ['/src'], 'destination' => '/dest']));
$checks['a taken name gets " (2)", never an overwrite'] = $again['result']['items'] === ['/dest/src (2)']
    && $tree($root.'/dest/src') === $tree($root.'/src');

// Resuming: the last attempt chose a name and stopped before recording the item.
file_put_contents($root.'/src/c.txt', 'gamma');
$resume = $queue('copy', ['paths' => ['/src/a.txt', '/src/c.txt'], 'destination' => '/dest']);
file_put_contents($root.'/dest/a-landed.txt', 'alpha');
$db->prepare('UPDATE jobs SET state = ? WHERE id = ?')->execute([json_encode(['placed' => [], 'placing' => ['index' => 0, 'to' => '/dest/a-landed.txt']]), $resume['id']]);
$resumed = $run($resume);
$checks['an attempt that crashed mid-rename is not copied twice'] = $resumed['status'] === 'completed'
    && $resumed['result']['items'] === ['/dest/a-landed.txt', '/dest/c.txt'] && !file_exists($root.'/dest/a.txt');

// Failure, and retry.
$gone = $queue('copy', ['paths' => ['/src/c.txt', '/src/a.txt'], 'destination' => '/src/inner']);
unlink($root.'/src/c.txt');
$failed = $run($gone);
$checks['a missing source fails its item, and the job, by name'] = $failed['status'] === 'failed'
    && str_contains((string)$failed['error'], '1 of 2 items') && $failed['result']['failed'][0]['path'] === '/src/c.txt'
    && $failed['result']['items'] === ['/src/inner/a.txt'];
file_put_contents($root.'/src/c.txt', 'gamma');
$jobs->retry($gone['id'], 1);
$retried = $run($gone);
$checks['a retry copies only what failed'] = $retried['status'] === 'completed'
    && $retried['result']['items'] === ['/src/inner/c.txt', '/src/inner/a.txt'] && !file_exists($root.'/src/inner/a (2).txt');

// Into itself.
$loop = $run($queue('copy', ['paths' => ['/src'], 'destination' => '/src/inner']));
$checks['a folder is not copied into itself'] = $loop['status'] === 'failed' && str_contains((string)$loop['result']['failed'][0]['message'], 'into itself');

// Cancelled while running: nothing half-copied is left.
$cancel = $queue('copy', ['paths' => ['/src'], 'destination' => '/dest']);
ProbeJob52::$body = null;
$db->prepare("UPDATE jobs SET cancel_requested = 1 WHERE id = ?")->execute([$cancel['id']]);
$cancelled = $run($cancel);
$checks['a job cancelled as it starts ends cancelled'] = $cancelled['status'] === 'cancelled' && !file_exists($root.'/dest/src (3)');

// Parameters from a client.
$refusal = static function (string $type, array $params) use ($raised, $types, $env, $user): ?int {
    return $raised(fn() => $types->get($type)->prepare($params, $env, $user));
};
symlink('/etc', $root.'/escape');
$checks['a path that climbs out of the store is refused'] = $refusal('copy', ['paths' => ['/../etc/passwd'], 'destination' => '/dest']) === 400;
$checks['a symlink out of the store is refused'] = $refusal('copy', ['paths' => ['/escape/passwd'], 'destination' => '/dest']) === 403;
$checks["CloudHub's own folders cannot be copied"] = $refusal('copy', ['paths' => ['/.jobs'], 'destination' => '/dest']) === 403
    && $refusal('copy', ['paths' => ['/.trash'], 'destination' => '/dest']) === 403;
$checks['nor copied into'] = $refusal('copy', ['paths' => ['/src'], 'destination' => '/.uploads']) === 403;
$checks['the root itself cannot be copied'] = $refusal('copy', ['paths' => ['/'], 'destination' => '/dest']) === 400;
$checks['a list is required, of strings'] = $refusal('copy', ['paths' => '/src', 'destination' => '/dest']) === 400
    && $refusal('copy', ['paths' => [['/src']], 'destination' => '/dest']) === 422;
$checks['an absolute filesystem path is only a name in the store'] = $refusal('copy', ['paths' => ['/etc/passwd'], 'destination' => '/dest']) === 404;
$checks['an unknown checksum algorithm is refused'] = $refusal('checksum', ['paths' => ['/src/a.txt'], 'algorithm' => 'crc32; rm -rf /']) === 422;

// The owner, asked again when the job runs.
$demoted = $queue('copy', ['paths' => ['/src/a.txt'], 'destination' => '/dest']);
$db->exec('UPDATE users SET role = \'viewer\' WHERE id = 1');
$refused = $run($demoted);
$checks['an account demoted since queueing cannot write through the queue'] = $refused['status'] === 'failed'
    && str_contains((string)$refused['error'], 'no longer has permission');
$db->exec("UPDATE users SET role = 'editor', is_active = 0 WHERE id = 1");
$disabled = $run($queue('copy', ['paths' => ['/src/a.txt'], 'destination' => '/dest']));
$checks['nor can a disabled one'] = $disabled['status'] === 'failed' && str_contains((string)$disabled['error'], 'disabled');
$db->exec('UPDATE users SET is_active = 1 WHERE id = 1');
$readOnly = $queue('copy', ['paths' => ['/src/a.txt'], 'destination' => '/dest']);
$roEnv = new JobEnvironment(['read_only' => true] + $config, new FileService(['read_only' => true] + $config), $db, $base.'/project');
$roWorker = new Worker($roEnv, $types, $registry, 'cli');
$roWorker->runOnce();
$checks['nothing writes while the server is read-only'] = $jobs->find($readOnly['id'])['error'] === 'Server is in read-only mode';
$checks['and nothing is queued to'] = $raised(fn() => $types->get('copy')->prepare(['paths' => ['/src'], 'destination' => '/dest'], $roEnv, $user)) === 403;

// Interrupted, lost, crashed.
ProbeJob52::$body = static function (JobContext $ctx) use ($worker): array { $worker->requestStop(); $ctx->checkpoint(); return []; };
$probe = $queue('probe', []);
$interrupted = $run($probe);
$checks['a worker asked to stop hands its job back, attempt returned'] = $interrupted['status'] === 'pending' && $interrupted['attempts'] === 0;
$worker = new Worker($env, $types, $registry, 'cli');
$run = static function (array $job) use (&$worker, $jobs): array { $worker->runOnce(); return $jobs->find($job['id']) ?? []; };
ProbeJob52::$body = static function (JobContext $ctx) use ($db): array {
    $db->prepare("UPDATE jobs SET claim_token = ? WHERE id = ?")->execute([str_repeat('e', 32), $ctx->id()]);
    $ctx->checkpoint(true);
    return ['never' => true];
};
$lost = $run($probe);
$checks['a worker that lost its claim leaves the job to its new owner'] = $lost['status'] === 'processing'
    && $lost['claim_token'] === str_repeat('e', 32) && $lost['result'] === null;
$db->prepare('DELETE FROM jobs WHERE id = ?')->execute([$probe['id']]);
// Lost without noticing, then an error: the takeover's staging must survive the cleanup.
ProbeJob52::$body = static function (JobContext $ctx) use ($db, $root): array {
    file_put_contents($ctx->staging().'/mine.part', 'x');
    $db->prepare("UPDATE jobs SET claim_token = ?, attempts = 2 WHERE id = ?")->execute([str_repeat('d', 32), $ctx->id()]);
    mkdir($root.'/.jobs/'.$ctx->id().'/attempt-2', 0775, true);
    file_put_contents($root.'/.jobs/'.$ctx->id().'/attempt-2/theirs.part', 'y');
    throw new RuntimeException('Unable to write mine.part; the disk may be full', 507);
};
$overtaken = $queue('probe', []);
$after = $run($overtaken);
$checks["an error after losing the claim deletes nothing of the new owner's"] =
    is_file($root.'/.jobs/'.$overtaken['id'].'/attempt-2/theirs.part') && $after['status'] === 'processing' && $after['error'] === null;
$db->prepare('DELETE FROM jobs WHERE id = ?')->execute([$overtaken['id']]);
rmrf52($root.'/.jobs/'.$overtaken['id']);
ProbeJob52::$body = static function (): array { throw new PDOException('SQLSTATE[HY000]: secret-host.internal:3306 refused'); };
$crashed = $run($queue('probe', []));
$checks['a database error is reported without its detail'] = $crashed['status'] === 'failed'
    && !str_contains((string)$crashed['error'], 'secret-host') && str_contains((string)$crashed['error'], 'database error');
ProbeJob52::$body = static function (): array { throw new TypeError('internal detail'); };
$crashed = $run($queue('probe', []));
$checks['so is any other internal error'] = $crashed['status'] === 'failed' && !str_contains((string)$crashed['error'], 'internal detail');
$unknown = $jobs->create(1, 'format-disk', 'x', '/', []);
$checks['a job of a type this version does not have fails, running nothing'] = $run($unknown)['status'] === 'failed';

// A dead worker's job is taken back at once.
$orphan = $queue('probe', []);
// A pid nothing is running as: pid_max can be far above a million.
for ($deadPid = 999999; WorkerRegistry::pidAlive($deadPid) !== false && $deadPid < 1999999; $deadPid++);
$deadId = 'cli:'.WorkerRegistry::host().':'.$deadPid.':abcdef';
$claimedByDead = $jobs->claimNext($deadId);
$checks['the model: that pid is not running'] = WorkerRegistry::pidAlive($deadPid) === false && $claimedByDead['id'] === $orphan['id'];
$house = $worker->housekeeping(true);
$checks["a dead worker's job goes back to the queue without waiting to go stale"] = in_array($orphan['id'], $house['recovered'], true)
    && $jobs->find($orphan['id'])['status'] === 'pending';
$liveId = 'cli:'.WorkerRegistry::host().':'.getmypid().':abcdef';
$jobs->claimNext($liveId);
$worker->housekeeping(true);
$checks["a live worker's job is not"] = $jobs->find($orphan['id'])['status'] === 'processing';
$db->prepare('DELETE FROM jobs WHERE id = ?')->execute([$orphan['id']]);

// Housekeeping: expired jobs and orphaned folders.
$old = $run($queue('archive', ['paths' => ['/src/a.txt'], 'mode' => 'download']));
$checks['an archive for download waits in the job\'s output'] = $old['status'] === 'completed' && is_file($root.'/.jobs/'.$old['id'].'/output/a.zip')
    && $types->get('archive')->download($old, $env)['name'] === 'a.zip';
$db->prepare("UPDATE jobs SET finished_at = '2001-01-01 00:00:00' WHERE id = ?")->execute([$old['id']]);
mkdir($root.'/.jobs/'.str_repeat('a', 32).'/attempt-1', 0775, true);
touch($root.'/.jobs/'.str_repeat('a', 32), time() - 600);
mkdir($root.'/.jobs/not-a-job', 0775, true);
$house = $worker->housekeeping(true);
$checks['an expired job goes, with its download'] = $house['expired'] >= 1 && $jobs->find($old['id']) === null && !is_dir($root.'/.jobs/'.$old['id']);
$checks['a folder no job owns is cleared'] = $house['orphans'] === 1 && !is_dir($root.'/.jobs/'.str_repeat('a', 32));
$checks['anything else in .jobs is left alone'] = is_dir($root.'/.jobs/not-a-job');
$checks['.jobs is never listed'] = !in_array('.jobs', array_column($fs->list('/'), 'name'), true);

// Archives.
file_put_contents($root.'/src/inner/.hidden', 'h');
symlink($root.'/src/a.txt', $root.'/src/link.txt');
$zipped = $run($queue('archive', ['paths' => ['/src', '/src/a.txt'], 'mode' => 'download', 'name' => 'bundle']));
$names = [];
$za = new ZipArchive();
$za->open($root.'/.jobs/'.$zipped['id'].'/output/bundle.zip');
for ($i = 0; $i < $za->numFiles; $i++) $names[] = $za->getNameIndex($i);
$za->close();
sort($names);
$checks['an archive holds the selection under unique top-level names'] = in_array('src/inner/b.txt', $names, true)
    && in_array('a.txt', $names, true) && in_array('src/a.txt', $names, true);
$checks['and never a symlink'] = !in_array('src/link.txt', $names, true);
$rootZip = $run($queue('archive', ['paths' => ['/src'], 'mode' => 'save', 'destination' => '/dest']));
$checks['a saved archive lands in the store, charged to its owner'] = $rootZip['status'] === 'completed' && is_file($root.'/dest/src.zip')
    && $db->query("SELECT uploaded_by FROM file_metadata WHERE file_path = '/dest/src.zip'")->fetchColumn() == 1;
$checks['a viewer may queue an archive to download but not to save'] =
    $types->get('archive')->capability(['mode' => 'download']) === 'read' && $types->get('archive')->capability(['mode' => 'save']) === 'write';
$extra = [];
$capped = null;
for ($i = 0; $i < 5 && $capped === null; $i++) {
    try { $extra[] = $queue('archive', ['paths' => ['/src/a.txt'], 'mode' => 'download']); }
    catch (RuntimeException $e) { $capped = $e->getCode(); }
}
$checks['an account keeps at most '.\CloudHub\Services\Jobs\ArchiveJob::MAX_KEPT_DOWNLOADS.' archives for download'] = $capped === 429
    && count($extra) + 1 === \CloudHub\Services\Jobs\ArchiveJob::MAX_KEPT_DOWNLOADS
    && $raised(fn() => $queue('archive', ['paths' => ['/src/a.txt'], 'mode' => 'save', 'destination' => '/dest'])) === null;
foreach ($jobs->listFor(1, true) as $pending) { $jobs->requestCancel($pending['id'], 1, true); $jobs->remove($pending['id'], 1); }

// Extraction.
$zip = new ZipArchive();
$zip->open($root.'/good.zip', ZipArchive::CREATE);
$zip->addFromString('docs/readme.txt', 'hello');
$zip->addEmptyDir('empty');
$zip->addFromString('link', '/etc/passwd');
$zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0120777 << 16));
$zip->close();
$extracted = $run($queue('extract', ['path' => '/good.zip']));
$checks['an archive is extracted into a folder named after it'] = $extracted['status'] === 'completed'
    && file_get_contents($root.'/good/docs/readme.txt') === 'hello' && is_dir($root.'/good/empty');
$checks['a symlink entry is skipped, not created'] = !file_exists($root.'/good/link') && !is_link($root.'/good/link') && $extracted['result']['skipped'] === 1;
$checks['what it extracts is charged to its owner'] =
    $db->query("SELECT uploaded_by FROM file_metadata WHERE file_path = '/good/docs/readme.txt'")->fetchColumn() == 1;

$evil = static function (string $name, string $entry) use ($root): void {
    $z = new ZipArchive();
    $z->open($root.'/'.$name, ZipArchive::CREATE);
    $z->addFromString('fine.txt', 'ok');
    $z->addFromString($entry, 'owned');
    $z->close();
};
$evil('slip.zip', '../../slipped.txt');
$evil('abs.zip', '/tmp/cloudhub-p52-abs.txt');
$evil('back.zip', '..\\..\\backslipped.txt');
foreach (['slip', 'abs', 'back'] as $n) {
    $bad = $run($queue('extract', ['path' => '/'.$n.'.zip']));
    $checks["a hostile archive ($n) is refused whole"] = $bad['status'] === 'failed' && str_contains((string)$bad['error'], 'nothing was extracted')
        && !file_exists($root.'/'.$n) && !file_exists($base.'/slipped.txt') && !file_exists($root.'/slipped.txt')
        && !file_exists('/tmp/cloudhub-p52-abs.txt') && !file_exists($base.'/backslipped.txt');
}
$checks['entry names are checked one component at a time'] = ExtractJob::entryParts('a/./b//c') === ['a', 'b', 'c']
    && $raised(fn() => ExtractJob::entryParts('a/../../b')) === 422 && $raised(fn() => ExtractJob::entryParts("C:\\x")) === 422
    && $raised(fn() => ExtractJob::entryParts("a\x01b")) === 422;
$zip = new ZipArchive();
$zip->open($root.'/big.zip', ZipArchive::CREATE);
$zip->addFromString('zeros.bin', str_repeat("\0", 2 * 1024 * 1024));
$zip->close();
$tiny = new JobEnvironment(['queue_extract_max_gb' => 0.001] + $config, $fs, $db, $base.'/project');
$tinyWorker = new Worker($tiny, $types, $registry, 'cli');
$big = $queue('extract', ['path' => '/big.zip']);
$tinyWorker->runOnce();
$checks['an archive larger than the limit is refused before anything is written'] =
    str_contains((string)$jobs->find($big['id'])['error'], 'the most allowed') && !is_dir($root.'/big') && !is_dir($root.'/good (2)');
$checks['a file that is not an archive is refused when queued'] = $refusal('extract', ['path' => '/src/a.txt']) === 415;

// Deleting for good.
mkdir($root.'/doomed/deep/er', 0775, true);
for ($i = 0; $i < 30; $i++) file_put_contents($root.'/doomed/deep/er/f'.$i, 'x');
file_put_contents($base.'/outside-the-store.txt', 'keep me');
symlink($base.'/outside-the-store.txt', $root.'/doomed/keep-target');
$purgeId = JobRepository::newId();
$hold = PurgeJob::holdingDir($env, $purgeId);
rename($root.'/doomed', $hold.'/doomed');
$purge = $jobs->create(1, 'purge', 'Delete "doomed"', '', ['entries' => 1, 'files' => 30], $purgeId);
$purged = $run($purge);
$checks['a deletion job deletes what was moved into it'] = $purged['status'] === 'completed' && !is_dir($root.'/.jobs/'.$purgeId)
    && $purged['result']['files'] === 31 && file_get_contents($base.'/outside-the-store.txt') === 'keep me';
$checks['a client cannot queue one'] = !$types->get('purge')->clientCreatable()
    && $refusal('purge', []) === 400;

// Checksums.
$sum = $run($queue('checksum', ['paths' => ['/src/a.txt', '/src/inner/b.txt']]));
$checks['checksums match hash_file()'] = $sum['status'] === 'completed'
    && $sum['result']['files'][0]['hash'] === hash('sha256', 'alpha')
    && $sum['result']['files'][1]['hash'] === hash_file('sha256', $root.'/src/inner/b.txt');
$checks['of files only'] = $refusal('checksum', ['paths' => ['/src']]) === 400;

// Thumbnails.
if (function_exists('imagewebp')) {
    $im = imagecreatetruecolor(64, 48);
    imagepng($im, $root.'/src/photo.png');
    imagedestroy($im);
    $thumbs = $run($queue('thumbnails', ['path' => '/src']));
    $checks['thumbnails are made where the thumbnail route looks'] = $thumbs['status'] === 'completed' && $thumbs['result']['made'] === 1
        && is_file((string)\CloudHub\Services\ImageThumbnailer::cachePath($base.'/project', $root.'/src/photo.png'));
}

// Duplicates, one at a time.
file_put_contents($root.'/src/p1.jpg', str_repeat('p', 4000));
file_put_contents($root.'/src/p2.jpg', str_repeat('p', 4000));
$dupA = $queue('duplicates', ['path' => '/src']);
$dupB = $queue('duplicates', ['path' => '/src'], 3);
$db->prepare("UPDATE jobs SET status = 'processing', claim_token = ?, worker = ?, started_at = '2030-01-01 00:00:00', heartbeat_at = '2099-01-01 00:00:00' WHERE id = ?")
    ->execute([str_repeat('c', 32), $liveId, $dupA['id']]);
$worker->runOnce();
$checks['a second duplicate scan waits while one runs'] = $jobs->find($dupB['id'])['status'] === 'pending';
$db->prepare("UPDATE jobs SET status = 'completed', claim_token = NULL WHERE id = ?")->execute([$dupA['id']]);
$scan = $run($dupB);
$checks['and runs once it has finished'] = $scan['status'] === 'completed' && $scan['result']['groups'] === 1;
$checks["the scan is kept in the worker's project, not this installation's"] = is_file($base.'/project/storage/.cache/duplicates.json')
    && (is_file($realScan) ? md5_file($realScan) : null) === $realScanBefore;

// What an owner is shown.
$shown = $types->present($jobs->find($scan['id']));
$checks['an owner never sees the claim, the worker, the payload or the state'] =
    !array_key_exists('claim_token', $shown) && !array_key_exists('worker', $shown) && !array_key_exists('payload', $shown) && !array_key_exists('state', $shown)
    && $shown['progress']['percent'] === 100 && $shown['canRemove'] && !$shown['canCancel'];

rmrf52($base);
@unlink(sys_get_temp_dir().'/cloudhub-p52-'.getmypid().'.log');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
