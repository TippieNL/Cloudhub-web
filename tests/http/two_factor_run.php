<?php
declare(strict_types=1);

/**
 * SMS two-step verification over real HTTP, against a real database.
 *
 *   php tests/http/two_factor_run.php
 *
 * Needs the MySQL/MariaDB database .env points at, migrated with
 * php database/migrate.php. It creates its own accounts (tf<random>_...) and
 * deletes them when it is done, and starts what it talks to:
 *
 *   - CloudHub itself, on PHP's built-in server with four workers so requests
 *     can race, configured for SMS_DRIVER=webhook in production mode;
 *   - tests/http/sms_gateway.php, a stand-in gateway the webhook driver posts
 *     to, which records each message (that is how codes are read here) and can
 *     be told to fail, refuse, stall or answer nonsense;
 *   - two more CloudHubs: one with no SMS gateway, one asked for the
 *     development outbox while in production.
 *
 * tests/phase54_two_factor_test.php covers the same rules in one process,
 * deterministically; this covers what only a deployment has: cookies, CSRF,
 * sessions on other devices, WebDAV, the gateway over the network, races.
 */
require dirname(__DIR__, 2).'/config/bootstrap.php';
require __DIR__.'/Client.php';

use CloudHub\Helpers\Db;
use CloudHub\Repositories\UserRepository;
use CloudHub\Tests\Http\Client;
use CloudHub\Tests\Http\Response;

$root = dirname(__DIR__, 2);
$work = sys_get_temp_dir().'/cloudhub-2fa-http-'.bin2hex(random_bytes(4));
mkdir($work.'/gateway', 0775, true);
mkdir($work.'/sessions', 0775, true);

/* ---- accounts of our own -------------------------------------------------- */

$db = Db::connection();
try {
    $db->query('SELECT two_factor_enabled_at FROM users WHERE 1 = 0');
} catch (PDOException) {
    fwrite(STDERR, "The database has no two-step verification columns: run php database/migrate.php first.\n");
    exit(1);
}
$tag = 'tf'.bin2hex(random_bytes(3));
$repo = new UserRepository($db);
$accounts = [
    'editor' => [$tag.'_ed', 'editor-pass-12345', 'editor'],
    'viewer' => [$tag.'_vi', 'viewer-pass-12345', 'viewer'],
    'admin' => [$tag.'_ad', 'admin-pass-123456', 'admin'],
    'second' => [$tag.'_se', 'second-pass-12345', 'editor'],
];
$ids = [];
foreach ($accounts as $key => [$name, $password, $role]) $ids[$key] = $repo->create($name, $password, $role)['id'];
// Numbers of our own as well, so the per-number limit starts empty each run.
$phone = static fn(): string => '+3197'.random_int(10000000, 99999999);

/* ---- servers of our own --------------------------------------------------- */

$freePort = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
};
$processes = [];
$serve = static function (array $command, array $env, string $log) use (&$processes, $root): void {
    $process = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), $env));
    if (!is_resource($process)) { fwrite(STDERR, "Could not start ".implode(' ', $command)."\n"); exit(1); }
    $processes[] = $process;
};
$up = static function (string $url): bool {
    for ($i = 0; $i < 80; $i++) {
        if (@file_get_contents($url) !== false) return true;
        usleep(100_000);
    }
    return false;
};
$gatewayPort = $freePort();
$serve([PHP_BINARY, '-S', '127.0.0.1:'.$gatewayPort, __DIR__.'/sms_gateway.php'], ['SMS_GATEWAY_DIR' => $work.'/gateway'], $work.'/gateway.log');

$common = [
    'APP_ENV' => 'production', 'APP_URL' => 'https://cloud.example.test', 'TRASH_ENABLED' => 'false',
    'TWO_FACTOR_SECRET' => bin2hex(random_bytes(32)), 'SMS_TIMEOUT_SECONDS' => '2',
    // The hourly limits are proven in tests/phase54_two_factor_test.php with a
    // clock it controls. Here every request comes from 127.0.0.1 and this
    // suite sends more than five codes to one account, so they are raised
    // rather than throttle rows being deleted from what may be a real database.
    'TWO_FACTOR_SMS_PER_HOUR' => '100', 'TWO_FACTOR_FAILURES_PER_HOUR' => '100',
    'TWO_FACTOR_SMS_IP_PER_HOUR' => '1000', 'TWO_FACTOR_IP_FAILURES_PER_HOUR' => '1000', 'LOGIN_RATE_IP_ATTEMPTS' => '1000',
];
$appPort = $freePort();
$serve([PHP_BINARY, '-d', 'session.save_path='.$work.'/sessions', '-S', '127.0.0.1:'.$appPort, 'router.php'], $common + [
    'SMS_DRIVER' => 'webhook', 'SMS_WEBHOOK_URL' => 'http://127.0.0.1:'.$gatewayPort.'/', 'SMS_WEBHOOK_TOKEN' => 'test-hook-token',
    'SMS_FROM' => 'CloudHubTest', 'PHP_CLI_SERVER_WORKERS' => '4',
], $work.'/app.log');
$quietPort = $freePort();
$serve([PHP_BINARY, '-S', '127.0.0.1:'.$quietPort, 'router.php'], $common + ['SMS_DRIVER' => ''], $work.'/quiet.log');
$outboxPort = $freePort();
$serve([PHP_BINARY, '-S', '127.0.0.1:'.$outboxPort, 'router.php'], $common + ['SMS_DRIVER' => 'log'], $work.'/outbox.log');

register_shutdown_function(static function () use (&$processes, $db, $ids, $work): void {
    foreach ($processes as $process) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    foreach ($ids as $id) {
        foreach (['DELETE FROM two_factor_challenges WHERE user_id = ?', 'DELETE FROM two_factor_recovery_codes WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
            try { $db->prepare($sql)->execute([$id]); } catch (Throwable) {}
        }
    }
    foreach (array_merge(glob($work.'/*/*') ?: [], glob($work.'/*') ?: []) as $path) is_dir($path) ? @rmdir($path) : @unlink($path);
    @rmdir($work);
});

$base = 'http://127.0.0.1:'.$appPort;
$quiet = 'http://127.0.0.1:'.$quietPort;
$outbox = 'http://127.0.0.1:'.$outboxPort;
foreach ([$base, $quiet, $outbox, 'http://127.0.0.1:'.$gatewayPort] as $server) {
    $probe = str_ends_with($server, (string)$gatewayPort) ? $server.'/' : $server.'/?route='.rawurlencode('/api/auth/status');
    if (!$up($probe)) { fwrite(STDERR, "$server never answered.\n"); exit(1); }
}
// The probe above was a message too.
@unlink($work.'/gateway/received.jsonl');

/* ---- the harness ---------------------------------------------------------- */

$passed = 0;
$failures = [];
$currentCase = '';

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failures, $currentCase;
    if ($ok) { $passed++; return; }
    $failures[] = $currentCase.': '.$what.($detail !== '' ? ' -- '.$detail : '');
}

function scenario(string $name, callable $body): void
{
    global $currentCase, $failures;
    $currentCase = $name;
    try { $body(); } catch (Throwable $e) { $failures[] = $name.': threw '.get_class($e).' -- '.$e->getMessage(); }
    echo '  '.$name.PHP_EOL;
}

function gateway(string $mode): void
{
    global $work;
    file_put_contents($work.'/gateway/mode', $mode);
}

/** @return list<array> every message the gateway was handed, oldest first */
function texts(): array
{
    global $work;
    $lines = @file($work.'/gateway/received.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_map(static fn(string $l): array => json_decode($l, true) ?: [], $lines);
}

function last_text(): array
{
    $all = texts();
    return $all ? $all[count($all) - 1] : [];
}

function code_of(array $text): string
{
    return preg_match('/^(\d{6}) /', (string)($text['body']['message'] ?? ''), $m) ? $m[1] : '';
}

/**
 * Make the session re-read its account on the next request rather than up to
 * a minute later, by editing what the server keeps in the session file.
 */
function recheck_now(Client $client): void
{
    global $work;
    $file = $work.'/sessions/sess_'.$client->cookie('cloudhub_session');
    $data = (string)@file_get_contents($file);
    file_put_contents($file, preg_replace('/account_checked_at\|i:\d+;/', 'account_checked_at|i:1;', $data));
}

/** Sign in to an account with two-step verification on, all the way. */
function sign_in_with_code(Client $client, string $user, string $password): Response
{
    $login = $client->signIn($user, $password);
    if ($login->errorCode() !== 'TWO_FACTOR_REQUIRED') return $login;
    $client->post('/api/auth/two-factor/send');
    return $client->post('/api/auth/two-factor/verify', ['code' => code_of(last_text())]);
}

/** Turn it on for a signed-in client, through the API. @return array the confirm answer */
function enable(Client $client, string $password, string $number): array
{
    $client->post('/api/users/me/two-factor/start', ['action' => 'phone', 'currentPassword' => $password, 'phone' => $number]);
    return $client->post('/api/users/me/two-factor/confirm', ['code' => code_of(last_text())])->json ?? [];
}

[$editor, $editorPass] = $accounts['editor'];
[$viewer, $viewerPass] = $accounts['viewer'];
[$admin, $adminPass] = $accounts['admin'];
[$second, $secondPass] = $accounts['second'];
$editorPhone = $phone();
$recovery = [];
gateway('ok');
echo "CloudHub two-step verification over HTTP (app :$appPort, gateway :$gatewayPort)".PHP_EOL;

/* ---- nothing changes for accounts without it -------------------------------- */

scenario('an account without two-step verification signs in exactly as before', function () use ($base, $editor, $editorPass) {
    $c = new Client($base);
    $status = $c->get('/api/auth/status');
    check('status: signed out, a token, nothing about a second step', $status->status === 200
        && ($status->json['authenticated'] ?? null) === false && !array_key_exists('twoFactor', $status->json ?? []) && $c->csrfToken() !== '');
    $bad = $c->signIn($editor, 'not-the-password-123');
    check('a wrong password is refused as before', $bad->status === 401 && $bad->errorCode() === 'UNAUTHORIZED'
        && ($bad->json['error']['message'] ?? '') === 'Invalid username or password', $bad->describe());
    $before = count(texts());
    $r = $c->signIn($editor, $editorPass);
    check('the right one signs in, with the same answer as before', $r->status === 200 && ($r->json['success'] ?? false) === true
        && ($r->json['user']['username'] ?? '') === $editor && isset($r->json['csrfToken']) && !isset($r->json['twoFactor']), $r->describe());
    check('and no text is sent', count(texts()) === $before);
    check('the session works', $c->get('/api/files/list', ['path' => '/'])->status === 200);
});

/*
 * Names without an extension, on purpose. Under PHP's built-in server a URL
 * ending in one -- /webdav/notes.txt, a share link /share/TOKEN.txt -- arrives
 * with SCRIPT_NAME set to that path, and Http::basePath() strips it: the
 * request answers "Not found". That predates two-step verification (the
 * original code does the same) and only affects the development server, which
 * this suite runs on; Cloudhub-2's router.php carries the fix.
 */
$stored = '/tf-http-'.bin2hex(random_bytes(4));
$storedBytes = "two-step verification suite\n".str_repeat('0123456789', 100);

scenario('uploads, share links, WebDAV and the password work as before', function () use ($base, $editor, $editorPass, $stored, $storedBytes) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $init = $c->post('/api/uploads/init', ['targetPath' => '/', 'name' => ltrim($stored, '/'), 'size' => strlen($storedBytes),
        'uploadId' => 'tfhttp'.bin2hex(random_bytes(8)), 'conflict' => 'overwrite']);
    $id = (string)($init->json['id'] ?? '');
    check('an upload starts', $init->ok() && $id !== '', $init->describe());
    check('takes its bytes', $c->putChunk($id, 0, $storedBytes)->ok());
    check('and completes', $c->post('/api/uploads/complete', ['id' => $id])->ok());
    $down = $c->get('/api/files/download', ['path' => $stored]);
    check('the file comes back unchanged', $down->body === $storedBytes, strlen($down->body).' bytes');

    $share = $c->post('/api/shares/create', ['filePath' => $stored]);
    check('a share link is made', $share->ok() && str_contains((string)($share->json['url'] ?? ''), '/share/'), $share->describe());
    $public = (new Client($base))->fetchUrl((string)($share->json['url'] ?? ''));
    check('and serves the file to someone with no account', $public->status === 200 && $public->body === $storedBytes, (string)$public->status);
    check('the link is revoked', $c->delete('/api/shares/revoke', ['token' => (string)($share->json['token'] ?? '')])->ok());

    $list = $c->dav('PROPFIND', '/webdav/', ['Depth: 1']);
    check('WebDAV lists the folder', $list->status === 207 && str_contains($list->body, rawurlencode(ltrim($stored, '/'))), (string)$list->status);
    $davName = '/tf-dav-'.bin2hex(random_bytes(4));
    check('WebDAV stores a file', in_array($c->dav('PUT', '/webdav'.$davName, ['Content-Type: text/plain'], 'over webdav')->status, [200, 201, 204], true));
    check('and serves it', $c->dav('GET', '/webdav'.$davName)->body === 'over webdav');
    check('and deletes it', in_array($c->dav('DELETE', '/webdav'.$davName)->status, [200, 204], true));

    $changed = $c->post('/api/users/me/password', ['currentPassword' => $editorPass, 'newPassword' => $editorPass.'-new']);
    check('the password can be changed', $changed->ok(), $changed->describe());
    check('and signs in afterwards', (new Client($base))->signIn($editor, $editorPass.'-new')->ok());
    check('and changed back', $c->post('/api/users/me/password', ['currentPassword' => $editorPass.'-new', 'newPassword' => $editorPass])->ok());
    check('the test file is removed again', $c->delete('/api/files/delete', ['path' => $stored])->ok());
    $out = $c->post('/api/auth/logout');
    check('signing out works', $out->ok() && $c->get('/api/files/list', ['path' => '/'])->status === 401);
});

/* ---- turning it on ------------------------------------------------------------ */

$early = new Client($base);
$early->signIn($editor, $editorPass);
$owner = new Client($base);

scenario('turning it on takes the password and the new number\'s code', function () use ($owner, $editor, $editorPass, $editorPhone, &$recovery) {
    $owner->signIn($editor, $editorPass);
    $overview = $owner->get('/api/users/me/two-factor');
    check('it starts off, and the server can send texts', ($overview->json['enabled'] ?? null) === false && ($overview->json['available'] ?? null) === true, $overview->describe());
    $noToken = $owner->postWithoutCsrf('/api/users/me/two-factor/start', ['action' => 'phone', 'currentPassword' => $editorPass, 'phone' => $editorPhone]);
    check('without the CSRF token it is refused', $noToken->status === 419, $noToken->describe());
    $crossSite = $owner->post('/api/users/me/two-factor/start', ['action' => 'phone', 'currentPassword' => $editorPass, 'phone' => $editorPhone], ['Sec-Fetch-Site: cross-site']);
    check('from another site it is refused', $crossSite->status === 403 && $crossSite->errorCode() === 'CROSS_SITE_REQUEST', $crossSite->describe());
    $wrong = $owner->post('/api/users/me/two-factor/start', ['action' => 'phone', 'currentPassword' => 'not-the-password-1', 'phone' => $editorPhone]);
    check('with the wrong password it is refused', $wrong->status === 403, $wrong->describe());
    $local = $owner->post('/api/users/me/two-factor/start', ['action' => 'phone', 'currentPassword' => $editorPass, 'phone' => '06 1234 5678']);
    check('a number without its country code is refused', $local->status === 422, $local->describe());

    $before = count(texts());
    $start = $owner->post('/api/users/me/two-factor/start', ['action' => 'phone', 'currentPassword' => $editorPass,
        'phone' => substr($editorPhone, 0, 3).' '.substr($editorPhone, 3, 2).' '.substr($editorPhone, 5)]);
    check('a code goes to the number', $start->ok() && ($start->json['sent'] ?? false) === true && count(texts()) === $before + 1, $start->describe());
    $text = last_text();
    check('to the gateway, as JSON, with its bearer token', ($text['authorization'] ?? '') === 'Bearer test-hook-token'
        && str_starts_with((string)($text['contentType'] ?? ''), 'application/json') && ($text['body']['to'] ?? '') === $editorPhone
        && ($text['body']['from'] ?? '') === 'CloudHubTest');
    check('saying what it is for, with the origin-bound line', str_contains((string)($text['body']['message'] ?? ''), 'to confirm this number for two-step verification')
        && str_ends_with((string)($text['body']['message'] ?? ''), '@cloud.example.test #'.code_of($text)));
    check('the answer shows only the last two digits', ($start->json['phoneEnding'] ?? '') === substr($editorPhone, -2)
        && !str_contains($start->body, substr($editorPhone, 3)));
    $wrongCode = $owner->post('/api/users/me/two-factor/confirm', ['code' => code_of($text) === '000000' ? '000001' : '000000']);
    check('a wrong code is refused, with the tries left', $wrongCode->status === 422 && ($wrongCode->json['error']['details']['attemptsLeft'] ?? null) === 4, $wrongCode->describe());
    $done = $owner->post('/api/users/me/two-factor/confirm', ['code' => code_of($text)]);
    check('the right one turns it on', ($done->json['done'] ?? false) === true && ($done->json['enabled'] ?? false) === true, $done->describe());
    $recovery = $done->json['recoveryCodes'] ?? [];
    check('with ten recovery codes, shown this once', count($recovery) === 10);
    $overview = $owner->get('/api/users/me/two-factor');
    check('it reads as on, without the number', ($overview->json['enabled'] ?? null) === true && ($overview->json['recoveryCodesLeft'] ?? null) === 10
        && !str_contains($overview->body, substr($editorPhone, 3)));
});

scenario('sessions that only ever proved the password end; the one that turned it on does not', function () use ($early, $owner) {
    recheck_now($early);
    recheck_now($owner);
    $ended = $early->get('/api/files/list', ['path' => '/']);
    check('a session signed in earlier with the password alone is signed out', $ended->status === 401, $ended->describe());
    check('the one that proved the phone carries on', $owner->get('/api/files/list', ['path' => '/'])->status === 200);
});

/* ---- signing in with it ----------------------------------------------------------- */

scenario('the password alone no longer signs in, anywhere', function () use ($base, $editor, $editorPass, $editorPhone) {
    $c = new Client($base);
    $c->get('/api/auth/status');
    $cookieBefore = $c->cookie('cloudhub_session');
    $before = count(texts());
    $login = $c->post('/api/auth/login', ['username' => $editor, 'password' => $editorPass]);
    check('the right password is answered 401 TWO_FACTOR_REQUIRED', $login->status === 401 && $login->errorCode() === 'TWO_FACTOR_REQUIRED', $login->describe());
    check('in the error envelope an older client shows as is', is_string($login->json['error']['message'] ?? null) && ($login->json['success'] ?? null) === false);
    check('with what the code step needs, and only the last two digits', ($login->json['twoFactor']['phoneEnding'] ?? '') === substr($editorPhone, -2)
        && ($login->json['twoFactor']['codeLength'] ?? 0) === 6 && !str_contains($login->body, substr($editorPhone, 3)));
    check('no text is sent until it is asked for', count(texts()) === $before);
    check('the session id was replaced', $c->cookie('cloudhub_session') !== null && $c->cookie('cloudhub_session') !== $cookieBefore);

    foreach ([
        'the file list' => fn() => $c->get('/api/files/list', ['path' => '/']),
        'a download' => fn() => $c->get('/api/files/download', ['path' => '/']),
        'an upload' => fn() => $c->post('/api/uploads/init', ['targetPath' => '/', 'name' => 'x.txt', 'size' => 1, 'uploadId' => 'tfhttpxxxxxxxxxx1']),
        'a share link' => fn() => $c->post('/api/shares/create', ['filePath' => '/x.txt']),
        'the password change' => fn() => $c->post('/api/users/me/password', ['currentPassword' => $editorPass, 'newPassword' => $editorPass.'x']),
        'the two-step settings' => fn() => $c->get('/api/users/me/two-factor'),
        'turning two-step off' => fn() => $c->post('/api/users/me/two-factor/start', ['action' => 'disable', 'currentPassword' => $editorPass]),
        'signing out' => fn() => $c->post('/api/auth/logout'),
        'WebDAV' => fn() => $c->dav('PROPFIND', '/webdav/', ['Depth: 1']),
        'the player' => fn() => $c->get('/play', ['path' => '/x.mp4']),
    ] as $what => $request) {
        $r = $request();
        check("while waiting for the code, $what is refused", $r->status === 401, $what.': '.$r->describe());
    }
    $status = $c->get('/api/auth/status');
    check('status: not signed in, but waiting for a code', ($status->json['authenticated'] ?? null) === false && isset($status->json['twoFactor'])
        && array_key_exists('user', $status->json ?? []) && $status->json['user'] === null);
});

scenario('signing in with the texted code', function () use ($base, $editor, $editorPass, $editorPhone) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $noToken = $c->postWithoutCsrf('/api/auth/two-factor/send');
    check('asking for a code needs the CSRF token', $noToken->status === 419, $noToken->describe());
    $crossSite = $c->post('/api/auth/two-factor/send', [], ['Sec-Fetch-Site: cross-site']);
    check('and is refused from another site', $crossSite->status === 403, $crossSite->describe());
    $before = count(texts());
    $send = $c->post('/api/auth/two-factor/send');
    check('a code is sent', $send->ok() && ($send->json['sent'] ?? false) === true && count(texts()) === $before + 1, $send->describe());
    $text = last_text();
    check('to the account\'s number, as a sign-in code', ($text['body']['to'] ?? '') === $editorPhone
        && str_contains((string)($text['body']['message'] ?? ''), 'sign-in code'));
    $again = $c->post('/api/auth/two-factor/send');
    check('asking again at once is refused, with Retry-After', $again->status === 429 && $again->errorCode() === 'TWO_FACTOR_RESEND_COOLDOWN'
        && (int)$again->header('Retry-After') > 0 && count(texts()) === $before + 1, $again->describe());
    $noTokenVerify = $c->postWithoutCsrf('/api/auth/two-factor/verify', ['code' => code_of($text)]);
    check('checking a code needs the CSRF token too', $noTokenVerify->status === 419);
    $bad = $c->post('/api/auth/two-factor/verify', ['code' => code_of($text) === '111111' ? '111112' : '111111']);
    check('a wrong code is refused', $bad->status === 422 && $bad->errorCode() === 'TWO_FACTOR_CODE_INVALID', $bad->describe());
    check('and still nothing is reachable', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    $cookieBefore = $c->cookie('cloudhub_session');
    $ok = $c->post('/api/auth/two-factor/verify', ['code' => code_of($text)]);
    check('the right code signs in', $ok->status === 200 && ($ok->json['success'] ?? false) === true && ($ok->json['user']['username'] ?? '') === $editor, $ok->describe());
    check('with another new session id', $c->cookie('cloudhub_session') !== $cookieBefore);
    check('and everything works', $c->get('/api/files/list', ['path' => '/'])->status === 200
        && $c->dav('PROPFIND', '/webdav/', ['Depth: 1'])->status === 207);
    $replay = $c->post('/api/auth/two-factor/verify', ['code' => code_of($text)]);
    check('the code cannot be used again', $replay->status === 401, $replay->describe());
    $elsewhere = new Client($base);
    $elsewhere->get('/api/auth/status');
    $stolen = $elsewhere->post('/api/auth/two-factor/verify', ['code' => code_of($text)]);
    check('nor by a session that never gave the password', $stolen->status === 401 && $elsewhere->get('/api/files/list', ['path' => '/'])->status === 401);
});

scenario('expired, exhausted and replaced codes', function () use ($base, $db, $editor, $editorPass, $ids) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $c->post('/api/auth/two-factor/send');
    $code = code_of(last_text());
    $db->prepare("UPDATE two_factor_challenges SET expires_at = ? WHERE user_id = ? AND purpose = 'login'")
        ->execute([gmdate('Y-m-d H:i:s', time() - 1), $ids['editor']]);
    $late = $c->post('/api/auth/two-factor/verify', ['code' => $code]);
    check('a code past its time is refused', $late->status === 410 && $late->errorCode() === 'TWO_FACTOR_CODE_EXPIRED', $late->describe());

    $c2 = new Client($base);
    $c2->signIn($editor, $editorPass);
    $c2->post('/api/auth/two-factor/send');
    $code = code_of(last_text());
    $wrong = $code === '222222' ? '222223' : '222222';
    $answers = [];
    for ($i = 0; $i < 6; $i++) $answers[] = $c2->post('/api/auth/two-factor/verify', ['code' => $wrong])->errorCode();
    check('five wrong guesses use the code up', $answers[4] === 'TWO_FACTOR_CODE_INVALID' && $answers[5] === 'TWO_FACTOR_CODE_EXHAUSTED', implode(',', $answers));
    $right = $c2->post('/api/auth/two-factor/verify', ['code' => $code]);
    check('after which the right one is refused too', $right->status === 410 && $c2->get('/api/files/list', ['path' => '/'])->status === 401, $right->describe());
    check('each wrong guess was counted', (int)$db->query("SELECT COUNT(*) FROM security_events WHERE event_type = 'auth.two_factor' AND outcome = 'failure' AND user_id = ".(int)$ids['editor'])->fetchColumn() >= 5);

    $first = new Client($base);
    $first->signIn($editor, $editorPass);
    $first->post('/api/auth/two-factor/send');
    $firstCode = code_of(last_text());
    $later = new Client($base);
    $later->signIn($editor, $editorPass);
    $replaced = $first->post('/api/auth/two-factor/verify', ['code' => $firstCode]);
    check('a later sign-in replaces an earlier one\'s code', $replaced->status === 410 && $first->get('/api/files/list', ['path' => '/'])->status === 401, $replaced->describe());
});

scenario('a code for one purpose is no good for another', function () use ($base, $editor, $editorPass) {
    $signedIn = new Client($base);
    sign_in_with_code($signedIn, $editor, $editorPass);
    $change = $signedIn->post('/api/users/me/two-factor/start', ['action' => 'recovery', 'currentPassword' => $editorPass]);
    check('a change texts its own code', ($change->json['sent'] ?? false) === true, $change->describe());
    $changeCode = code_of(last_text());
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $r = $c->post('/api/auth/two-factor/verify', ['code' => $changeCode]);
    check('which does not sign anyone in', $r->status !== 200 && $c->get('/api/files/list', ['path' => '/'])->status === 401, $r->describe());
    $signedIn->post('/api/users/me/two-factor/cancel');
    check('and a cancelled change\'s code confirms nothing', $signedIn->post('/api/users/me/two-factor/confirm', ['code' => $changeCode])->status === 409);
});

scenario('recovery codes sign in once each', function () use ($base, $editor, $editorPass, &$recovery) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $before = count(texts());
    $r = $c->post('/api/auth/two-factor/verify', ['recoveryCode' => strtoupper(str_replace('-', ' ', (string)($recovery[0] ?? '')))]);
    check('a recovery code, typed loosely, signs in', $r->status === 200 && ($r->json['recoveryCodesLeft'] ?? null) === 9, $r->describe());
    check('with no text sent', count(texts()) === $before);
    $c2 = new Client($base);
    $c2->signIn($editor, $editorPass);
    $again = $c2->post('/api/auth/two-factor/verify', ['recoveryCode' => (string)($recovery[0] ?? '')]);
    check('the same one is refused the second time', $again->status === 422 && $again->errorCode() === 'TWO_FACTOR_RECOVERY_INVALID', $again->describe());
    $both = $c2->post('/api/auth/two-factor/verify', ['code' => '123456', 'recoveryCode' => (string)($recovery[1] ?? '')]);
    check('a code and a recovery code together is refused', $both->status === 422);
    $c2->post('/api/auth/two-factor/cancel');
    check('cancelling ends the wait', $c2->post('/api/auth/two-factor/verify', ['recoveryCode' => (string)($recovery[1] ?? '')])->status === 401);
});

scenario('two requests with the right code at once: one signs in', function () use ($base, $editor, $editorPass) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $c->post('/api/auth/two-factor/send');
    $answers = $c->parallelPost('/api/auth/two-factor/verify', ['code' => code_of(last_text())], 5);
    $statuses = array_map(static fn(Response $r): int => $r->status, $answers);
    check('exactly one of five racing requests succeeds', count(array_filter($statuses, static fn(int $s): bool => $s === 200)) === 1, implode(',', $statuses));
});

/* ---- the gateway misbehaving ------------------------------------------------------ */

scenario('a gateway that fails, refuses, stalls or mumbles never lets anyone in', function () use ($base, $db, $editor, $editorPass, $ids, $root) {
    // config/bootstrap.php sends PHP's error log to logs/php-error.log; what
    // this scenario adds to it is read at the end.
    $errorLog = $root.'/logs/php-error.log';
    clearstatcache();
    $logFrom = is_file($errorLog) ? (int)filesize($errorLog) : 0;
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    // Each try waits out the resend cooldown rather than sixty real seconds.
    $clearCooldown = static function () use ($db, $ids): void {
        $db->prepare("UPDATE two_factor_challenges SET sent_at = NULL WHERE purpose = 'login' AND user_id = ?")->execute([$ids['editor']]);
    };
    gateway('fail');
    $down = $c->post('/api/auth/two-factor/send');
    check('an outage is a 503 that says so, with a wait', $down->status === 503 && $down->errorCode() === 'SMS_UNAVAILABLE' && (int)$down->header('Retry-After') > 0, $down->describe());
    check('and the session is still signed out', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    gateway('reject');
    $clearCooldown();
    $refused = $c->post('/api/auth/two-factor/send');
    check('a refused number is a 422 that says so', $refused->status === 422 && $refused->errorCode() === 'SMS_REJECTED', $refused->describe());
    gateway('slow');
    $clearCooldown();
    $started = microtime(true);
    $slow = $c->post('/api/auth/two-factor/send');
    check('a gateway past SMS_TIMEOUT_SECONDS is given up on', $slow->status === 503 && microtime(true) - $started < 3.8, $slow->describe());
    sleep(3);   // the stand-in finishes its stall before it can answer again
    gateway('garbage');
    $clearCooldown();
    $odd = $c->post('/api/auth/two-factor/send');
    check('a 2xx that is not JSON is accepted, as the webhook contract says', $odd->ok(), $odd->describe());
    gateway('ok');
    check('none of it signed the session in', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    $log = (string)@file_get_contents($GLOBALS['work'].'/app.log');
    check('no number reached the server output', !preg_match('/\+319\d{8}/', $log));
    // The operator is told the gateway failed -- which also proves this is the
    // log PHP writes to -- and never told the number.
    $errors = (string)@file_get_contents($errorLog, false, null, $logFrom);
    check('the error log says the text was not sent, and why', str_contains($errors, 'two-step code not sent: webhook: HTTP 500'));
    check('but not to which number', !preg_match('/\+319\d{8}/', $errors));
});

scenario('a server with no SMS gateway asks for a recovery code instead', function () use ($quiet, $editor, $editorPass, &$recovery) {
    $c = new Client($quiet);
    $login = $c->signIn($editor, $editorPass);
    check('the password still only gets as far as the code', $login->status === 401 && ($login->json['twoFactor']['smsAvailable'] ?? null) === false, $login->describe());
    $send = $c->post('/api/auth/two-factor/send');
    check('no code can be sent', $send->status === 503 && $send->errorCode() === 'SMS_NOT_CONFIGURED', $send->describe());
    check('nothing is reachable', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    $r = $c->post('/api/auth/two-factor/verify', ['recoveryCode' => (string)($recovery[2] ?? '')]);
    check('a recovery code still signs in', $r->status === 200, $r->describe());
    $overview = $c->get('/api/users/me/two-factor');
    check('and the settings say texts cannot be sent', ($overview->json['smsAvailable'] ?? null) === false && ($overview->json['enabled'] ?? null) === true);
});

scenario('the development outbox is refused in production', function () use ($outbox, $root, $editor, $editorPass) {
    $file = $root.'/logs/sms-outbox.log';
    $existed = is_file($file);
    $c = new Client($outbox);
    $c->signIn($editor, $editorPass);
    $send = $c->post('/api/auth/two-factor/send');
    check('SMS_DRIVER=log with APP_ENV=production sends nothing', $send->status === 503 && $send->errorCode() === 'SMS_NOT_CONFIGURED', $send->describe());
    check('and writes no code to a file', $existed || !is_file($file));
});

/* ---- an older client ------------------------------------------------------------- */

scenario('a client that knows nothing of two-step verification', function () use ($base, $editor, $editorPass) {
    // What the Android app shipped before this did: POST login, and read
    // `success` and `csrfToken` from a 2xx, or the error message from anything else.
    $before = count(texts());
    $c = new Client($base);
    $r = $c->post('/api/auth/login', ['username' => $editor, 'password' => $editorPass]);
    check('is not told it succeeded', !$r->ok() && ($r->json['success'] ?? false) === false);
    check('gets a message it can show', str_contains((string)($r->json['error']['message'] ?? ''), 'two-step verification'));
    check('costs no text message', count(texts()) === $before);
    check('and is not signed in', $c->get('/api/files/list', ['path' => '/'])->status === 401);
});

/* ---- changing it ------------------------------------------------------------------- */

scenario('turning it off needs the current phone, or a recovery code', function () use ($base, $second, $secondPass, $phone) {
    $c = new Client($base);
    $c->signIn($second, $secondPass);
    $number = $phone();
    $on = enable($c, $secondPass, $number);
    check('it is on', ($on['enabled'] ?? false) === true);
    $codes = $on['recoveryCodes'] ?? [];
    $start = $c->post('/api/users/me/two-factor/start', ['action' => 'disable', 'currentPassword' => $secondPass]);
    check('turning it off texts the current phone', ($start->json['stage'] ?? '') === 'current' && ($start->json['sent'] ?? false) === true
        && (last_text()['body']['to'] ?? '') === $number && str_contains((string)(last_text()['body']['message'] ?? ''), 'to turn off two-step verification'), $start->describe());
    $off = $c->post('/api/users/me/two-factor/confirm', ['code' => code_of(last_text())]);
    check('whose code turns it off', ($off->json['enabled'] ?? null) === false, $off->describe());
    check('and the phone is told', str_contains((string)(last_text()['body']['message'] ?? ''), 'was turned off'));
    $plain = new Client($GLOBALS['base']);
    check('the password alone signs in again', $plain->signIn($second, $secondPass)->ok());

    $on = enable($c, $secondPass, $number);
    $codes = $on['recoveryCodes'] ?? [];
    $before = count(texts());
    $lost = $c->post('/api/users/me/two-factor/start', ['action' => 'disable', 'currentPassword' => $secondPass, 'method' => 'recovery']);
    check('with the phone lost, nothing is texted', ($lost->json['sent'] ?? true) === false && count(texts()) === $before, $lost->describe());
    $off = $c->post('/api/users/me/two-factor/confirm', ['recoveryCode' => (string)($codes[0] ?? '')]);
    check('and a recovery code turns it off', ($off->json['enabled'] ?? null) === false, $off->describe());
});

scenario('an administrator can reset it, and only that way round', function () use ($base, $editor, $viewer, $viewerPass, $admin, $adminPass, $editorPhone, $ids) {
    $v = new Client($base);
    $v->signIn($viewer, $viewerPass);
    $refused = $v->delete('/api/users/'.$ids['editor'].'/two-factor', ['currentPassword' => $viewerPass]);
    check('a viewer cannot reset anyone\'s', $refused->status === 403, $refused->describe());
    $a = new Client($base);
    check('the administrator signs in', $a->signIn($admin, $adminPass)->ok());
    $list = $a->get('/api/users');
    $row = array_values(array_filter($list->json ?? [], static fn(array $u): bool => $u['username'] === $editor))[0] ?? [];
    check('the Users list says who has it on', ($row['twoFactorEnabled'] ?? null) === true && !str_contains($list->body, substr($editorPhone, 3)));
    $self = $a->delete('/api/users/'.$ids['admin'].'/two-factor', ['currentPassword' => $adminPass]);
    check('not for their own account', $self->status === 409, $self->describe());
    $wrong = $a->delete('/api/users/'.$ids['editor'].'/two-factor', ['currentPassword' => 'not-the-password-1']);
    check('not without their own password', $wrong->status === 403, $wrong->describe());
    $before = count(texts());
    $reset = $a->delete('/api/users/'.$ids['editor'].'/two-factor', ['currentPassword' => $adminPass]);
    check('a reset turns it off', $reset->ok(), $reset->describe());
    check('and texts the owner', count(texts()) === $before + 1 && (last_text()['body']['to'] ?? '') === $editorPhone
        && str_contains((string)(last_text()['body']['message'] ?? ''), 'An administrator turned off'));
    $events = $a->get('/api/security/events', ['limit' => 200])->body;
    check('and is in the audit trail, with no code or number in it', str_contains($events, 'two_factor.admin_reset')
        && !str_contains($events, substr($editorPhone, 3)));
});

scenario('after the reset the password alone signs in again', function () use ($base, $editor, $editorPass) {
    $c = new Client($base);
    $r = $c->signIn($editor, $editorPass);
    check('as it did before it was turned on', $r->status === 200 && ($r->json['success'] ?? false) === true, $r->describe());
});

scenario('the command-line reset, for when nobody can do it from the web', function () use ($base, $root, $db, $second, $secondPass, $phone, $ids) {
    $c = new Client($base);
    $c->signIn($second, $secondPass);
    check('it is on', (enable($c, $secondPass, $phone())['enabled'] ?? false) === true);
    $run = static function (string $user) use ($root): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/reset-two-factor.php').' '.escapeshellarg($user).' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    };
    [$code, $out] = $run($second);
    check('the tool turns it off', $code === 0 && str_contains($out, 'is off for'), $out);
    $row = $db->prepare('SELECT two_factor_phone, two_factor_enabled_at FROM users WHERE id = ?');
    $row->execute([$ids['second']]);
    $state = $row->fetch();
    $left = $db->prepare('SELECT COUNT(*) FROM two_factor_recovery_codes WHERE user_id = ?');
    $left->execute([$ids['second']]);
    check('taking the number and the recovery codes with it', $state['two_factor_phone'] === null && $state['two_factor_enabled_at'] === null
        && (int)$left->fetchColumn() === 0);
    check('after which the password alone signs in', (new Client($base))->signIn($second, $secondPass)->ok());
    [$code, $out] = $run($second);
    check('running it again says so, and succeeds', $code === 0 && str_contains($out, 'already off'), $out);
    [$code] = $run('no-such-account-'.bin2hex(random_bytes(3)));
    check('an unknown account is an error', $code === 1);
});

foreach (array_merge(texts(), [['body' => ['message' => 'end']]]) as $text) {
    // Every code the gateway saw, to look for in places codes must never be.
    $GLOBALS['allCodes'][] = code_of($text);
}
scenario('codes and numbers stay out of the logs and the trail', function () use ($root, $db, $editorPhone) {
    $codes = array_values(array_filter($GLOBALS['allCodes'] ?? []));
    $logs = (string)@file_get_contents($root.'/logs/php-error.log');
    $trail = json_encode($db->query("SELECT context_json FROM security_events WHERE event_type LIKE '%two_factor%' OR event_type LIKE 'auth.%' ORDER BY id DESC LIMIT 500")->fetchAll());
    check('there were codes to look for', count($codes) >= 10);
    check('no number in the error log', !str_contains($logs, substr($editorPhone, 3)));
    check('no code in the error log', array_filter($codes, static fn(string $c): bool => (bool)preg_match('/(?<![0-9A-Za-z])'.$c.'(?![0-9A-Za-z])/', $logs)) === []);
    check('no code in the audit trail', array_filter($codes, static fn(string $c): bool => str_contains((string)$trail, '"'.$c.'"')) === []);
    check('no number in the audit trail', !str_contains((string)$trail, substr($editorPhone, 3)));
});

/* ---- report ----------------------------------------------------------------- */

$texts = count(texts());
echo PHP_EOL.$passed.' checks passed, '.count($failures).' failed ('.$texts.' text messages through the stand-in gateway).'.PHP_EOL;
foreach ($failures as $failure) echo '  FAIL '.$failure.PHP_EOL;
exit($failures ? 1 : 0);
