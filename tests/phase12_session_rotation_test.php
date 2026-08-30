<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Helpers/Http.php';
require dirname(__DIR__).'/src/Services/Security.php';
require dirname(__DIR__).'/src/Services/Auth.php';

use CloudHub\Services\Auth;

/**
 * Periodic session rotation used session_regenerate_id(true), which deletes the
 * predecessor file immediately. The file list fires many parallel requests (one
 * per image thumbnail, plus media streams), so a sibling still queued on the old
 * ID found nothing, started an empty session under use_strict_mode and returned
 * a surprise 401.
 *
 * Rotation now leaves the predecessor readable for a short grace period.
 *
 * Every session operation runs before anything is printed: PHP refuses to start
 * a session once output has been flushed, so results are collected first and
 * reported at the end.
 */
$savePath = sys_get_temp_dir().'/cloudhub-sess-'.bin2hex(random_bytes(5));
mkdir($savePath, 0775, true);
ini_set('session.save_path', $savePath);
ini_set('session.gc_probability', '0');

$config = [
    'session_idle_seconds' => 3600,
    'session_absolute_seconds' => 43200,
    'session_rotate_seconds' => 300,
    'session_samesite' => 'Lax',
    'https_enabled' => false,
    'trust_proxy' => false,
];

/**
 * Run one "request": present a session ID, start the session, then close it.
 *
 * A real request carries the ID in a cookie, but PHP only consults the cookie
 * when no ID is set in the process, and this test replays several requests in
 * one process. Setting it explicitly is how a fresh request presenting that
 * cookie behaves -- use_strict_mode still decides whether the ID is accepted.
 */
$request = function (?string $cookieId) use ($config): array {
    $_SESSION = [];
    if ($cookieId === null) unset($_COOKIE['cloudhub_session']);
    else { $_COOKIE['cloudhub_session'] = $cookieId; session_id($cookieId); }
    Auth::startSession($config);
    $result = ['id' => session_id(), 'user' => Auth::user()];
    session_write_close();
    return $result;
};
$sessionFile = fn(string $id): string => $savePath.'/sess_'.$id;

// --- exercise -----------------------------------------------------------
// A logged-in session whose rotation is already due.
$first = $request(null);
$predecessor = $first['id'];
$_COOKIE['cloudhub_session'] = $predecessor;
session_id($predecessor);
Auth::startSession($config);
$_SESSION['user_id'] = 11; $_SESSION['username'] = 'tester'; $_SESSION['role'] = 'admin';
$_SESSION['rotated_at'] = time() - 4000;
session_write_close();

$rotated = $request($predecessor);          // this request rotates
$successor = $rotated['id'];
$predecessorRaw = is_file($sessionFile($predecessor)) ? (string)file_get_contents($sessionFile($predecessor)) : '';
$successorRaw = is_file($sessionFile($successor)) ? (string)file_get_contents($sessionFile($successor)) : '';

$inFlight = $request($predecessor);         // a sibling still holding the old ID
$noReRotate = $request($predecessor);

// Retire the predecessor by letting its grace lapse.
$_COOKIE['cloudhub_session'] = $predecessor;
session_id($predecessor);
Auth::startSession($config);
$_SESSION['obsolete_after'] = time() - 1;
session_write_close();
$expired = $request($predecessor);

$successorStillGood = $request($successor);

array_map('unlink', glob($savePath.'/sess_*') ?: []);
@rmdir($savePath);

// --- report -------------------------------------------------------------
$checks = [
    'rotation issues a new session ID' => $successor !== $predecessor && $successor !== '',
    'rotation keeps the caller authenticated' => ($rotated['user']['id'] ?? null) === 11,
    'the predecessor file is not deleted' => $predecessorRaw !== '',
    'the predecessor is stamped obsolete' => str_contains($predecessorRaw, 'obsolete_after'),
    'the successor is not stamped obsolete' => $successorRaw !== '' && !str_contains($successorRaw, 'obsolete_after'),
    'an in-flight request on the old ID stays authenticated' =>
        ($inFlight['user']['id'] ?? null) === 11 && $inFlight['id'] === $predecessor,
    'the old ID does not rotate again' => $noReRotate['id'] === $predecessor,
    'the predecessor is retired once the grace lapses' =>
        $expired['user'] === null && $expired['id'] !== $predecessor,
    'the successor still authenticates' => ($successorStillGood['user']['id'] ?? null) === 11,
];

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
