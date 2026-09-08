<?php
declare(strict_types=1);

/**
 * Sessions that do not end on a timer, and the ones that still must end.
 *
 * Driven through the real Auth::startSession() in subprocesses against real
 * session files, because the interesting cases are about what happens to a
 * session that already exists and is old -- which cannot be observed by
 * reading the source.
 */
$root = dirname(__DIR__);
$checks = [];

function rmrf34(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf34($p.'/'.$n); @rmdir($p); }
}

$base = sys_get_temp_dir().'/cloudhub-p34-'.bin2hex(random_bytes(5));
mkdir($base.'/sessions', 0775, true);

/*
 * A driver that plants a session of a chosen age, starts a session over it and
 * reports what survived. CLI has no cookies, so the session id is passed in
 * directly -- what is under test is the expiry logic, not cookie transport.
 */
file_put_contents($base.'/driver.php', <<<'PHP'
<?php
require getenv('ROOT').'/src/Services/Security.php';
require getenv('ROOT').'/src/Services/Auth.php';
use CloudHub\Services\Auth;

$config = json_decode($argv[1], true);
$ageSeconds = (int)$argv[2];
$sessionId = $argv[3];

session_save_path(getenv('SESSIONS'));
session_id($sessionId);

// Plant a signed-in session of the requested age.
$then = time() - $ageSeconds;
$planted = 'user_id|i:7;username|s:5:"kevin";created_at|i:'.$then
    .';last_seen_at|i:'.$then.';csrf|s:4:"aaaa";rotated_at|i:'.$then.';';
file_put_contents(rtrim(getenv('SESSIONS'), '/').'/sess_'.$sessionId, $planted);

Auth::startSession($config);

echo json_encode([
    'signedIn' => isset($_SESSION['user_id']),
    'lastSeen' => $_SESSION['last_seen_at'] ?? null,
    'gcMaxlifetime' => (int)ini_get('session.gc_maxlifetime'),
    'cookieLifetime' => (int)session_get_cookie_params()['lifetime'],
]);
PHP);

/** Run the driver with a config and a planted session age. */
$run = static function(array $overrides, int $ageSeconds) use ($base, $root): array {
    $config = array_merge([
        'session_idle_seconds' => 0,
        'session_absolute_seconds' => 0,
        'session_lifetime_days' => 30,
        'session_samesite' => 'Lax',
        'https_enabled' => false,
        'require_https' => false,
        'trust_proxy' => false,
        'app_url' => '',
    ], $overrides);

    $id = bin2hex(random_bytes(13));
    // stderr is discarded on purpose: with no database in this harness, Auth's
    // account revalidation logs "revalidation skipped" every run. That is the
    // documented fail-open behaviour, not a fault, and merging it into stdout
    // only corrupts the JSON being parsed.
    $out = shell_exec(
        'ROOT='.escapeshellarg($root).' SESSIONS='.escapeshellarg($base.'/sessions').' '
        .escapeshellarg(PHP_BINARY).' '.escapeshellarg($base.'/driver.php').' '
        .escapeshellarg((string)json_encode($config)).' '.escapeshellarg((string)$ageSeconds).' '
        .escapeshellarg($id).' 2>/dev/null'
    );
    return json_decode(trim((string)$out), true) ?: ['_raw' => $out];
};

// --- nothing expires by default -------------------------------------------

$week = 7 * 86400;
$old = $run([], $week);
$checks['a week-old session survives with expiry off'] = ($old['signedIn'] ?? false) === true;
if (($old['signedIn'] ?? null) !== true) echo '       raw: '.substr(json_encode($old), 0, 300).PHP_EOL;

$veryOld = $run([], 400 * 86400);
$checks['and so does one more than a year old'] = ($veryOld['signedIn'] ?? false) === true;

/*
 * The floor must not resurrect the timeout. max(300, 0) is 300, so reading the
 * configured value after the floor would have turned "never" into a
 * five-minute idle timeout -- the exact opposite of what was asked for.
 */
$justOverFiveMinutes = $run([], 400);
$checks['zero is not floored back up to 300 seconds'] = ($justOverFiveMinutes['signedIn'] ?? false) === true;

// --- the capability is off, not broken ------------------------------------

$idleOut = $run(['session_idle_seconds' => 600], 1200);
$checks['a configured idle timeout still expires a session'] = ($idleOut['signedIn'] ?? true) === false;

$idleAlive = $run(['session_idle_seconds' => 600], 60);
$checks['and leaves a fresh one alone'] = ($idleAlive['signedIn'] ?? false) === true;

$absoluteOut = $run(['session_absolute_seconds' => 3600], 7200);
$checks['a configured absolute timeout still expires a session'] = ($absoluteOut['signedIn'] ?? true) === false;

// A positive value below the floor is still floored, as it always was.
$floored = $run(['session_idle_seconds' => 10], 120);
$checks['a positive value below the floor is still floored to 300'] = ($floored['signedIn'] ?? false) === true;

// --- last_seen_at has no writer when it has no reader ---------------------

// Left at the value it was planted with -- a week ago -- rather than moved to
// now, which is what a stamp would do.
$checks['last_seen_at is not stamped while expiry is off'] =
    isset($old['lastSeen']) && $old['lastSeen'] <= time() - $week + 30;
$checks['and is stamped when an idle timeout is set'] =
    isset($idleAlive['lastSeen']) && $idleAlive['lastSeen'] >= time() - 120;

// --- PHP is no longer reaping the file first ------------------------------

$checks['gc_maxlifetime is set from the window, not left at the default'] =
    ($old['gcMaxlifetime'] ?? 0) === 30 * 86400;
$checks['an absolute timeout governs it instead when configured'] =
    ($absoluteOut['gcMaxlifetime'] ?? 0) === 3600;

// --- the cookie survives the browser closing ------------------------------

$checks['the cookie is no longer a browser-session cookie'] = ($old['cookieLifetime'] ?? 0) > 0;
$checks['its lifetime matches the window'] = ($old['cookieLifetime'] ?? 0) === 30 * 86400;

// --- what must still end a session ----------------------------------------

$auth = (string)file_get_contents($root.'/src/Services/Auth.php');
// An account an administrator disabled is signed out at the next request. That
// is not a timer and must survive this change.
$checks['a revoked account is still signed out'] =
    str_contains($auth, 'if($status===null||!$status[\'isActive\']){')
    && str_contains($auth, 'self::destroySession();');
$checks['explicit logout still ends the session'] = str_contains($auth, 'public static function logout()');
// Rotation and its grace window are untouched.
$checks['session-ID rotation is untouched'] = str_contains($auth, "\$rotate=max(300,(int)(\$config['session_rotate_seconds']??900));");
$checks['the rotation grace window is untouched'] = str_contains($auth, "isset(\$_SESSION['obsolete_after'])");

// --- the switch is documented ---------------------------------------------

$env = (string)file_get_contents($root.'/.env.example');
$checks['the .env ships with sign-out disabled'] =
    str_contains($env, 'SESSION_IDLE_SECONDS=0') && str_contains($env, 'SESSION_ABSOLUTE_SECONDS=0');
$checks['and explains how to turn it back on'] = str_contains($env, 'Set either to a number of seconds to turn it back');
$checks['the window is documented'] = str_contains($env, 'SESSION_LIFETIME_DAYS=30');

rmrf34($base);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
