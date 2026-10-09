<?php
declare(strict_types=1);

/**
 * Turn two-step verification off for an account, from the server.
 *
 *   php tools/reset-two-factor.php <username>
 *
 * For when nobody can do it from the web: the owner has lost access to both
 * their mailbox and their recovery codes and no other administrator can reset
 * it from the Users screen -- typically because it is the only
 * administrator's own account. Whoever can run this already holds the
 * server, so it asks for nothing more. The address, the recovery codes and
 * any code in flight are removed, the account signs in with its password
 * alone until its owner turns two-step verification on again, and the reset
 * goes in the audit trail.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/config/bootstrap.php';

use CloudHub\Helpers\Db;
use CloudHub\Repositories\TwoFactorRepository;
use CloudHub\Repositories\UserRepository;
use CloudHub\Services\AuditLog;

$username = trim((string)($argv[1] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Usage: php tools/reset-two-factor.php <username>\n");
    exit(1);
}

$db = Db::connection();
$account = (new UserRepository($db))->findByUsername($username);
if ($account === null) {
    fwrite(STDERR, "No account is named $username.\n");
    exit(1);
}
$twoFactor = new TwoFactorRepository($db);
if (!$twoFactor->schemaReady()) {
    fwrite(STDERR, "This database needs the two-step verification update first: run php database/migrate.php.\n");
    exit(1);
}
if (!$twoFactor->requiredFor($account['id'])) {
    fwrite(STDOUT, "Two-step verification is already off for $username.\n");
    exit(0);
}

$twoFactor->disable($account['id']);
AuditLog::write($db, 'two_factor.admin_reset', 'success', ['target' => $username, 'via' => 'command line']);
fwrite(STDOUT, "Two-step verification is off for $username, who signs in with their password alone until it is turned on again.\n");
