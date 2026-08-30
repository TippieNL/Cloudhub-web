<?php
declare(strict_types=1);

/**
 * Create or update an account from the command line.
 *
 *   php tools/create-admin.php <username> [viewer|editor|admin]
 *
 * The role defaults to admin, so the bootstrap command documented in the README
 * is unchanged. It accepts the other roles too, because until the Users screen
 * existed this script was the only way to make an account and it could only
 * ever make administrators -- leaving viewer and editor unreachable.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/config/bootstrap.php';

use CloudHub\Repositories\UserRepository;
use CloudHub\Services\Authorization;

$username = trim((string)($argv[1] ?? 'admin'));
if ($username === '' || strlen($username) > 100) { fwrite(STDERR, "Invalid username\n"); exit(1); }

$role = strtolower(trim((string)($argv[2] ?? Authorization::ADMIN)));
if (!in_array($role, [Authorization::VIEWER, Authorization::EDITOR, Authorization::ADMIN], true)) {
    fwrite(STDERR, "Invalid role: choose viewer, editor or admin\n");
    exit(1);
}

fwrite(STDOUT, 'Password: ');
if (function_exists('shell_exec')) { @shell_exec('stty -echo 2>/dev/null'); }
$password = rtrim((string)fgets(STDIN), "\r\n");
if (function_exists('shell_exec')) { @shell_exec('stty echo 2>/dev/null'); }
fwrite(STDOUT, PHP_EOL);

if (strlen($password) < 12) { fwrite(STDERR, "Password must be at least 12 characters.\n"); exit(1); }

$c = require dirname(__DIR__).'/config/database.php';
$pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$users = new UserRepository($pdo);

$existing = $users->findByUsername($username);
if ($existing === null) {
    $users->create($username, $password, $role);
    fwrite(STDOUT, "Created $username with the $role role.\n");
} else {
    $users->update((int)$existing['id'], ['password' => $password, 'role' => $role, 'isActive' => true]);
    fwrite(STDOUT, "Updated $username: password reset, role set to $role, account enabled.\n");
}
