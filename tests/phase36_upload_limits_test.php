<?php
declare(strict_types=1);

/**
 * The upload limits: 150 files per batch, 5 GB per file.
 *
 * Both numbers are policy rather than a technical ceiling, and both live in
 * more than one file -- config, the client's fallback literals, .env.example --
 * so the first thing asserted here is that those copies still agree. Drift
 * between them is silent: the dialog would refuse a selection the server would
 * have accepted, or the reverse.
 *
 * Two boundaries the raise crossed get their own coverage: PHP's own
 * max_file_uploads, which binds the legacy multipart route and used to be
 * invisible because the application cap happened to equal it; and the 2 GB
 * signed-integer limit that the old default of 2048 MB sat exactly on.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/UploadService.php';
require dirname(__DIR__).'/src/Helpers/Cache.php';
require dirname(__DIR__).'/src/Services/StorageDiagnostics.php';

use CloudHub\Services\FileService;
use CloudHub\Services\UploadService;

$root = dirname(__DIR__);
$checks = [];

$configSrc = (string)file_get_contents($root.'/config/config.php');
$appJs = (string)file_get_contents($root.'/public/assets/js/app.js');
$envExample = (string)file_get_contents($root.'/.env.example');
$index = (string)file_get_contents($root.'/public/index.php');

// --- the numbers agree wherever they are written -------------------------

/** Pull one env() default out of config/config.php. */
function config_default36(string $src, string $key): ?int {
    return preg_match("/env\('".preg_quote($key, '/')."',\s*(\d+)\)/", $src, $m) === 1 ? (int)$m[1] : null;
}
$cfgFiles = config_default36($configSrc, 'MAX_UPLOAD_FILES');
$cfgMb = config_default36($configSrc, 'MAX_UPLOAD_MB');
$checks['config ships 150 files and 5120 MB'] = $cfgFiles === 150 && $cfgMb === 5120;

// The client's literals are only reached when CLOUDHUB_UPLOAD_LIMITS is absent,
// which is exactly when nobody would notice them disagreeing.
preg_match('/maxFiles:\s*(\d+),\s*maxMb:\s*(\d+)/', $appJs, $jsFallback);
$checks['the client fallback matches config'] =
    (int)($jsFallback[1] ?? 0) === $cfgFiles && (int)($jsFallback[2] ?? 0) === $cfgMb;

preg_match('/^MAX_UPLOAD_FILES=(\d+)$/m', $envExample, $envFiles);
preg_match('/^MAX_UPLOAD_MB=(\d+)$/m', $envExample, $envMb);
$checks['.env.example matches config'] =
    (int)($envFiles[1] ?? 0) === $cfgFiles && (int)($envMb[1] ?? 0) === $cfgMb;
$checks['.env.example documents that 0 means no limit'] =
    str_contains($envExample, '0 means no limit');

// --- the count rule, including 0 meaning no limit ------------------------

// Run the shipped rule rather than a retyped copy of it. Node is not part of
// CI, so its absence is reported instead of failing -- the structural check
// below holds either way.
$nodeRule = null;
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    $probe = <<<'JS'
    const cases = [[150,149],[150,150],[150,151],[0,10000],[0,1],[1,1],[1,2],[undefined,10000]];
    const out = cases.map(([cap, n]) => {
        const uploadUI = { limits: { maxFiles: cap } };
        const files = { length: n };
        const maxFiles = Number(uploadUI.limits.maxFiles) || 0;
        return (maxFiles > 0 && files.length > maxFiles) ? 'refused' : 'allowed';
    });
    console.log(JSON.stringify(out));
JS;
    $tmpJs = sys_get_temp_dir().'/cloudhub-p36-'.bin2hex(random_bytes(4)).'.js';
    file_put_contents($tmpJs, $probe);
    $nodeRule = json_decode(trim((string)@shell_exec(escapeshellarg($node).' '.escapeshellarg($tmpJs).' 2>/dev/null')), true);
    @unlink($tmpJs);
}
if (is_array($nodeRule)) {
    $checks['149 of 150 allowed, 150 allowed, 151 refused'] =
        array_slice($nodeRule, 0, 3) === ['allowed', 'allowed', 'refused'];
    // The whole point of 0: ten thousand files must not be refused.
    $checks['a cap of 0 refuses nothing'] = array_slice($nodeRule, 3, 2) === ['allowed', 'allowed'];
    $checks['a cap of 1 still bounds at 1'] = array_slice($nodeRule, 5, 2) === ['allowed', 'refused'];
    // A client that never received limits must not be capped at NaN.
    $checks['an absent cap is treated as no limit'] = ($nodeRule[7] ?? '') === 'allowed';
} else {
    echo '[note] node is unavailable; the count rule is checked structurally only'.PHP_EOL;
}

// Structural, so the guard cannot be dropped where node is not run.
$checks['the client guards the cap on being positive'] =
    str_contains($appJs, 'const maxFiles = Number(uploadUI.limits.maxFiles) || 0;')
    && str_contains($appJs, 'if (maxFiles > 0 && files.length > maxFiles)')
    // The old unguarded form, which at a cap of 0 refused every selection.
    && !str_contains($appJs, 'if (files.length > uploadUI.limits.maxFiles)');

// --- the help text never reads "up to 0 files" ---------------------------

/** The sentence views/pages/app.php builds, evaluated the same way. */
function upload_help36(int $maxFiles, int $maxMb): string {
    $count = $maxFiles > 0
        ? 'Select up to '.$maxFiles.' file'.($maxFiles === 1 ? '' : 's').'.'
        : 'Select any number of files.';
    $size = $maxMb >= 1024 ? number_format($maxMb / 1024, 0).' GB' : $maxMb.' MB';
    return $count.' Maximum '.$size.' per file.';
}
$appView = (string)file_get_contents($root.'/views/pages/app.php');
$checks['the view builds the sentence rather than inlining a number'] =
    str_contains($appView, '$uploadCountHelp = $maxUploadFiles > 0')
    && str_contains($appView, "'Select any number of files.'")
    && str_contains($appView, '<?= htmlspecialchars($uploadCountHelp, ENT_QUOTES) ?>')
    && !str_contains($appView, "Select up to <?= (int)\$config['max_upload_files'] ?> files");
$checks['the shipped defaults read correctly'] =
    upload_help36(150, 5120) === 'Select up to 150 files. Maximum 5 GB per file.';
$checks['no limit does not read "up to 0 files"'] =
    upload_help36(0, 5120) === 'Select any number of files. Maximum 5 GB per file.';
$checks['a cap of one is not pluralised'] =
    upload_help36(1, 512) === 'Select up to 1 file. Maximum 512 MB per file.';

// --- PHP's own ceiling on the multipart route ----------------------------

// max_file_uploads truncates $_FILES without reporting it, and what the route
// sees has already been truncated -- so reaching the ceiling has to be refused.
$phpMax = max(1, (int)ini_get('max_file_uploads'));
$checks['the multipart route bounds itself by max_file_uploads'] =
    str_contains($index, "\$phpMax = max(1, (int)ini_get('max_file_uploads'));")
    && str_contains($index, 'if (count($names) >= $phpMax) throw new RuntimeException(');
$checks['the application cap is still applied, and 0 disables it'] =
    str_contains($index, '$appMax = (int)$config[\'max_upload_files\'];')
    && str_contains($index, 'if ($appMax > 0 && count($names) > $appMax)')
    // The old form, which at a cap of 0 refused every multipart upload.
    && !str_contains($index, "if (count(\$names) > \$config['max_upload_files'])");
// The bound that actually binds is PHP's, not the application's: with the app
// cap now at 150 and max_file_uploads commonly 20, a request of 30 passes the
// first check and must still be stopped by the second.
$refusesAtCeiling = static function (int $count, int $appMax) use ($phpMax): bool {
    if ($appMax > 0 && $count > $appMax) return true;
    return $count >= $phpMax;
};
$checks['a batch at PHP\'s ceiling is refused even under a higher app cap'] =
    $refusesAtCeiling($phpMax, 150) === true && $refusesAtCeiling($phpMax + 10, 150) === true;
$checks['a batch below it is still accepted'] =
    $phpMax < 2 || $refusesAtCeiling($phpMax - 1, 150) === false;

// --- 5 GB through the real service ---------------------------------------

$base = sys_get_temp_dir().'/cloudhub-p36-'.bin2hex(random_bytes(5));
mkdir($base.'/files', 0775, true);
mkdir($base.'/uploads', 0775, true);
$svcConfig = [
    'root_dir' => $base.'/files', 'read_only' => false, 'allow_overwrite' => true,
    'upload_staging_dir' => $base.'/uploads', 'upload_abandon_hours' => 24,
    'max_upload_mb' => 5120, 'max_upload_files' => 150,
    'upload_chunk_mb' => 8, 'upload_conflict' => 'rename',
];
$_SESSION = ['user_id' => 1];
$uploads = new UploadService($svcConfig, new FileService($svcConfig));

// init() checks the declared size before staging anything, so this costs no
// disk -- which is the only reason a 5 GB case is testable at all. The ids are
// eight characters or more because validId() requires it, and it is checked
// after the size, so a short one fails as 400 rather than the 413 under test.
$fiveGb = 5120 * 1024 * 1024;
$accepted = true;
try { $uploads->init('/', 'big.mkv', $fiveGb, 'p36bigfile', 'rename'); }
catch (Throwable) { $accepted = false; }
$checks['a 5 GB file is accepted at the new limit'] = $accepted;

$refusedCode = 0;
try { $uploads->init('/', 'toobig.mkv', $fiveGb + 1, 'p36toobigfile', 'rename'); }
catch (Throwable $e) { $refusedCode = (int)$e->getCode(); }
$checks['one byte over is refused with 413'] = $refusedCode === 413;

// The old ceiling must no longer refuse: 2 GB + 1 was over the previous limit.
$twoGbPlus = 2048 * 1024 * 1024 + 1;
$overOldLimit = true;
try { $uploads->init('/', 'past-old.mkv', $twoGbPlus, 'p36pastold', 'rename'); }
catch (Throwable) { $overOldLimit = false; }
$checks['a file past the old 2 GB limit is now accepted'] = $overOldLimit;

// 5 GB exceeds a 32-bit integer, so the arithmetic must not have silently
// wrapped on the way to that comparison.
$checks['the limit arithmetic survives past 2^31'] = $fiveGb > 2147483647 && is_int($fiveGb);

// --- a 32-bit build cannot honour the new default ------------------------

$diagSrc = (string)file_get_contents($root.'/src/Services/StorageDiagnostics.php');
$checks['the diagnostic warns when the build cannot address the limit'] =
    str_contains($diagSrc, "if (PHP_INT_SIZE < 8 && (int)\$this->config['max_upload_mb'] > 2047) {")
    && str_contains($diagSrc, '32-bit PHP build, which cannot address files beyond 2 GB');
// It joins the same array the existing ini warnings use, so it reaches both
// runtime.warnings and the top-level problems list without new plumbing.
$checks['it is collected like the other runtime warnings'] =
    (bool)preg_match('/32-bit PHP build.*?\n.*?\n\s*\}\n\n\s*foreach \(\$warnings as \$warning\) \$problems\[\] = \$warning;/s', $diagSrc);
// Run the real report: on a 64-bit runtime the warning must be absent, or
// every install would see it on every check.
$diagConfig = $svcConfig + ['storage_limit_gb' => 0, 'user_quota_gb' => 0];
$report = (new \CloudHub\Services\StorageDiagnostics($diagConfig, $base))->report(0);
$warned = static fn(array $r): bool => (bool)array_filter(
    $r['runtime']['warnings'] ?? [], static fn($w) => str_contains((string)$w, '32-bit PHP build')
);
$checks['the 32-bit warning is absent on a 64-bit build'] = PHP_INT_SIZE < 8 || !$warned($report);
$checks['a real report was produced'] = isset($report['runtime']['warnings']) && is_array($report['runtime']['warnings']);

// The predicate itself, at both integer widths and both sides of the boundary,
// since only one of them can be observed on any given runtime.
$needsWarning = static fn(int $intSize, int $mb): bool => $intSize < 8 && $mb > 2047;
$checks['the rule fires only for a 32-bit build over 2047 MB'] =
    $needsWarning(4, 5120) === true
    && $needsWarning(4, 2047) === false
    && $needsWarning(8, 5120) === false
    && $needsWarning(4, 2048) === true;

// --- docs -----------------------------------------------------------------

$readme = (string)file_get_contents($root.'/README.md');
$checks['the README quotes the shipped numbers'] =
    str_contains($readme, 'MAX_UPLOAD_MB=5120') && str_contains($readme, 'MAX_UPLOAD_FILES=150')
    && !str_contains($readme, 'MAX_UPLOAD_MB=2048');
$checks['the README states 64-bit as a requirement, not a suggestion'] =
    str_contains($readme, '**64-bit PHP build is required**')
    && str_contains($readme, 'tools/storage-check.php');
$checks['the README explains the multipart route\'s separate bound'] =
    str_contains($readme, 'max_file_uploads');

function rmrf36(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf36($p.'/'.$n); @rmdir($p); }
}
rmrf36($base);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
