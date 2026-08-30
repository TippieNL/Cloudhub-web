<?php
declare(strict_types=1);

/**
 * Account management.
 *
 * Authorization has always enforced viewer/editor/admin, but nothing could
 * create an account with a role other than admin: tools/create-admin.php
 * hard-coded role='admin' and there was no user API at all, so half the
 * authorization system was unreachable in practice.
 *
 * The route behaviour was exercised against a live server; these assertions
 * pin the parts that must not silently regress.
 */
$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$repo = (string)file_get_contents($root.'/src/Repositories/UserRepository.php');
$auth = (string)file_get_contents($root.'/src/Services/Auth.php');
$cli = (string)file_get_contents($root.'/tools/create-admin.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$view = (string)file_get_contents($root.'/views/pages/app.php');

$checks = [];

// --- the gap this closes ------------------------------------------------
$checks['the CLI can create a non-admin account'] =
    !str_contains($cli, "VALUES(?,?,1,'admin')") && str_contains($cli, 'Authorization::ADMIN');
$checks['the CLI still defaults to admin'] = str_contains($cli, "\$argv[2] ?? Authorization::ADMIN");
$checks['the CLI rejects an unknown role'] = str_contains($cli, 'Invalid role: choose viewer, editor or admin');

// --- authorization ------------------------------------------------------
foreach (["'/api/users' && \$method === 'GET'", "'/api/users' && \$method === 'POST'"] as $route) {
    $at = strpos($index, $route);
    $checks["$route is admin-gated"] = $at !== false && str_contains(substr($index, $at, 220), 'Authorization::requireAdmin()');
}
$checks['the by-id route is admin-gated'] =
    (bool)preg_match('#\^/api/users/\(\\\\d\+\)\$#', $index)
    && str_contains($index, "in_array(\$method, ['GET', 'PATCH', 'DELETE'], true)) api_try(function()use(\$m, \$method) {\n        Authorization::requireAdmin();");
$checks['changing your own password needs only a session'] = (function () use ($index): bool {
    $at = strpos($index, "'/api/users/me/password' && \$method === 'POST'");
    return $at !== false && str_contains(substr($index, $at, 200), 'Authorization::requireRead()');
})();
// A viewer must be able to rotate their own password; the blanket write check
// on POST refused it until this route was exempted.
$checks['self-service password is exempt from the write check'] =
    str_contains($index, "\$writeExemptPost = ['/api/files/download-zip', '/api/thumbnail/video', '/api/users/me/password'];");
$checks['the exempt list still verifies CSRF'] =
    (bool)preg_match('/Auth::verifyCsrf\(\);.*?\$writeExemptPost/s', $index);

// --- lock-out guards ----------------------------------------------------
$checks['you cannot delete your own account'] = str_contains($index, 'You cannot delete your own account');
$checks['you cannot drop your own admin access'] = str_contains($index, 'You cannot remove your own administrator access');
$checks['the last administrator is protected'] =
    substr_count($index, 'This is the last administrator; promote another account first') === 2;
$checks['the last-admin count excludes the account being changed'] =
    str_contains($index, '$users->activeAdminCount($id)') && str_contains($repo, 'AND id <> ?');
$checks['a wrong current password is refused'] = str_contains($index, 'The current password is incorrect');
$checks['passwords have a shared minimum length'] =
    str_contains($index, 'const USER_PASSWORD_MIN_LENGTH = 12;')
    && substr_count($index, 'USER_PASSWORD_MIN_LENGTH, 4096') === 3;
$checks['usernames are constrained'] = str_contains($index, 'A username may contain letters, digits and . _ @ - only');

// --- hashes never leave the repository ----------------------------------
$checks['reads select an explicit column list'] =
    str_contains($repo, "private const PUBLIC_COLUMNS = 'id, username, role, is_active, created_at, last_login_at'")
    && !preg_match('/SELECT \*\s+FROM users/i', $repo);
$checks['no response shape carries a hash'] = !str_contains($repo, "'passwordHash'") && !str_contains($repo, 'password_hash\'] =');
$checks['hashing is shared with login'] =
    str_contains($auth, 'public static function hashPassword') && str_contains($repo, 'Auth::hashPassword');
$checks['Argon2id is still preferred'] = str_contains($auth, 'PASSWORD_ARGON2ID');

// --- role changes reaching a live session -------------------------------
$checks['sessions revalidate the account'] = str_contains($auth, 'private static function revalidateAccount');
$checks['revalidation is throttled, not per-request'] =
    str_contains($auth, 'ACCOUNT_RECHECK_SECONDS') && str_contains($auth, "\$now-\$last<self::ACCOUNT_RECHECK_SECONDS)return;");
$checks['a disabled account loses its session'] =
    (bool)preg_match('/if\(\$status===null\|\|!\$status\[.isActive.\]\)\{\s*self::destroySession\(\);/', $auth);
$checks['a database outage does not sign everyone out'] =
    str_contains($auth, 'account revalidation skipped');

// --- UI -----------------------------------------------------------------
$checks['a Users panel exists'] = str_contains($view, 'id="users-page"') && str_contains($app, 'async function users()');
$checks['the Users nav entry is admin-only'] =
    str_contains($view, 'id="nav-users"') && str_contains($app, "\$('#nav-users').hidden = S.role !== 'admin';");
// Assert membership, not the shape of the condition: the page-route list has
// since become an in_array() and gains entries as pages are added.
$checks['/users is a page route'] =
    (bool)preg_match("/in_array\(\\\$path, \[(.*?)\], true\)/", $index, $pages)
    && str_contains($pages[1], "'/users'")
    && str_contains($app, "(p === '/users')");
$checks['every user can change their password'] =
    str_contains($view, 'id="password-overlay"') && str_contains($app, "api('/api/users/me/password'");

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
