<?php
declare(strict_types=1);

namespace CloudHub\Repositories;

use PDO;
use PDOException;

/**
 * Storage for two-step verification by email: an account's address and
 * switch, the codes waiting to be entered, and recovery codes.
 *
 * Every change that matters is a compare-and-set -- an UPDATE or DELETE whose
 * WHERE names the state it expects, and which counts only if it changed a row
 * -- so two requests racing to use one code cannot both succeed, and parallel
 * guesses cannot overspend a code's attempts, with no lock the database has to
 * support. Times are UTC 'Y-m-d H:i:s' strings produced here, never by the
 * database, so the SQLite the tests run against agrees with MySQL and a test
 * can move the clock.
 *
 * MySQL's rowCount() counts rows changed rather than rows matched; each update
 * below sets a value the row cannot already hold (a fresh code hash, a higher
 * attempt count, a first enablement), so the count means what it says.
 */
final class TwoFactorRepository
{
    public const PURPOSES = ['login', 'confirm', 'email'];

    /** @var \Closure(): int */
    private \Closure $clock;
    private ?bool $ready = null;

    public function __construct(private readonly PDO $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    private function stamp(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at);
    }

    private static function time(?string $stamp): ?int
    {
        if ($stamp === null || $stamp === '') return null;
        $at = strtotime($stamp.' UTC');
        return $at === false ? null : $at;
    }

    /**
     * Whether a failure means "this database has not been migrated yet".
     *
     * Only that reads as "nobody has two-step verification on" -- nobody can
     * have turned it on without the columns. Any other database error must
     * propagate, or an outage would wave a sign-in through on its password.
     */
    public static function missingSchema(PDOException $e): bool
    {
        if (in_array((string)$e->getCode(), ['42S22', '42S02'], true)) return true;
        return (bool)preg_match('/no such (?:column|table)|Unknown column|Base table or view not found/i', $e->getMessage());
    }

    /** Whether the columns and tables two-step verification needs exist. */
    public function schemaReady(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $this->db->query('SELECT two_factor_email, two_factor_enabled_at FROM users WHERE 1 = 0');
            $this->db->query('SELECT id FROM two_factor_challenges WHERE 1 = 0');
            $this->db->query('SELECT id FROM two_factor_recovery_codes WHERE 1 = 0');
            return $this->ready = true;
        } catch (PDOException $e) {
            if (!self::missingSchema($e)) throw $e;
            return $this->ready = false;
        }
    }

    /**
     * An account's two-step state, or null when the account does not exist.
     *
     * Whether it is on rests on two_factor_enabled_at alone, which databases
     * set up for text-message codes have as well. One that has not had the
     * email column added yet still asks those accounts for a code -- which
     * then only a recovery code can answer -- instead of letting them in on
     * the password: a missing column narrows what can be read, never what is
     * required.
     *
     * @return array{enabled: bool, email: ?string, enabledAt: ?int}|null
     */
    public function state(int $userId): ?array
    {
        $row = null;
        foreach (['two_factor_email, two_factor_enabled_at',
                  'NULL AS two_factor_email, two_factor_enabled_at',
                  'NULL AS two_factor_email, NULL AS two_factor_enabled_at'] as $columns) {
            try {
                $stmt = $this->db->prepare("SELECT $columns FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                break;
            } catch (PDOException $e) {
                if (!self::missingSchema($e)) throw $e;
            }
        }
        if (!$row) return null;
        $email = $row['two_factor_email'];
        return [
            'enabled' => $row['two_factor_enabled_at'] !== null,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'enabledAt' => self::time($row['two_factor_enabled_at']),
        ];
    }

    /** Whether signing in to this account takes a code as well as the password. */
    public function requiredFor(int $userId): bool
    {
        return (bool)($this->state($userId)['enabled'] ?? false);
    }

    /** @return array<int, true> ids of the accounts that have it on, for the Users screen */
    public function enabledUserIds(): array
    {
        try {
            $ids = $this->db->query('SELECT id FROM users WHERE two_factor_enabled_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            if (!self::missingSchema($e)) throw $e;
            return [];
        }
        return array_fill_keys(array_map('intval', $ids ?: []), true);
    }

    /**
     * Point the account's two-step verification at $email, turning it on if
     * it was off. Returns whether this call turned it on.
     *
     * The off-to-on step is a compare-and-set, so two confirmations racing to
     * turn it on agree on which one did -- and only that one hands out
     * recovery codes.
     */
    public function enable(int $userId, string $email): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET two_factor_email = ?, two_factor_enabled_at = ? WHERE id = ? AND two_factor_enabled_at IS NULL');
        $stmt->execute([$email, $this->stamp($this->now()), $userId]);
        if ($stmt->rowCount() === 1) return true;
        $this->db->prepare('UPDATE users SET two_factor_email = ? WHERE id = ?')->execute([$email, $userId]);
        return false;
    }

    /** Turn it off: the address, its recovery codes and any code in flight all go. */
    public function disable(int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE users SET two_factor_email = NULL, two_factor_enabled_at = NULL WHERE id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM two_factor_recovery_codes WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM two_factor_challenges WHERE user_id = ?')->execute([$userId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ---- codes waiting to be entered --------------------------------------- */

    /**
     * Open a challenge for this account and purpose, replacing any earlier one
     * -- which is what makes an older code stop working. Returns its id.
     *
     * Two at once can deadlock on InnoDB's gap locks or collide on the unique
     * key; the loser simply tries again, and the later one wins either way.
     */
    public function createChallenge(int $userId, string $purpose): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) throw new \InvalidArgumentException('Unknown challenge purpose');
        for ($try = 1; ; $try++) {
            $id = bin2hex(random_bytes(16));
            $this->db->beginTransaction();
            try {
                $this->db->prepare('DELETE FROM two_factor_challenges WHERE user_id = ? AND purpose = ?')->execute([$userId, $purpose]);
                $this->db->prepare('INSERT INTO two_factor_challenges (id, user_id, purpose, attempts, created_at) VALUES (?, ?, ?, 0, ?)')
                    ->execute([$id, $userId, $purpose, $this->stamp($this->now())]);
                $this->db->commit();
                break;
            } catch (PDOException $e) {
                $this->db->rollBack();
                if ($try >= 3 || !in_array((string)$e->getCode(), ['40001', '23000'], true)) throw $e;
            }
        }
        $this->pruneOccasionally();
        return $id;
    }

    /**
     * A challenge by id, or null when it is gone: used, replaced, or never
     * existed. Times come back as Unix seconds.
     */
    public function challenge(string $id): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) return null;
        $stmt = $this->db->prepare('SELECT id, user_id, purpose, code_hash, attempts, created_at, sent_at, expires_at FROM two_factor_challenges WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id' => (string)$row['id'],
            'user_id' => (int)$row['user_id'],
            'purpose' => (string)$row['purpose'],
            'code_hash' => $row['code_hash'] !== null ? (string)$row['code_hash'] : null,
            'attempts' => (int)$row['attempts'],
            'created_at' => self::time($row['created_at']),
            'sent_at' => self::time($row['sent_at']),
            'expires_at' => self::time($row['expires_at']),
        ];
    }

    /**
     * Put a fresh code on a challenge and start its clock -- provided the last
     * code went out at least $cooldown seconds ago. Two requests asking at
     * once send one message between them, not two. The new code replaces the
     * old one, whose attempts no longer matter.
     */
    public function arm(string $id, string $codeHash, int $ttl, int $cooldown): bool
    {
        $now = $this->now();
        $stmt = $this->db->prepare('UPDATE two_factor_challenges SET code_hash = ?, attempts = 0, sent_at = ?, expires_at = ?
            WHERE id = ? AND (sent_at IS NULL OR sent_at <= ?)');
        $stmt->execute([$codeHash, $this->stamp($now), $this->stamp($now + $ttl), $id, $this->stamp($now - $cooldown)]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Spend one attempt on a challenge's code, if it is live and has attempts
     * left. Returns the challenge as it stands afterwards, or null when there
     * was nothing to spend: the code is used, expired, never sent or spent.
     *
     * The attempt is taken before the code is compared, so a burst of
     * parallel guesses can never compare more codes than the limit allows.
     */
    public function spendAttempt(string $id, int $maxAttempts): ?array
    {
        $stmt = $this->db->prepare('UPDATE two_factor_challenges SET attempts = attempts + 1
            WHERE id = ? AND code_hash IS NOT NULL AND expires_at > ? AND attempts < ?');
        $stmt->execute([$id, $this->stamp($this->now()), $maxAttempts]);
        return $stmt->rowCount() === 1 ? $this->challenge($id) : null;
    }

    /**
     * Use the challenge up. True for exactly one caller however many race:
     * the row and its code hash are gone afterwards, so the same code can
     * never be accepted twice.
     */
    public function consume(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM two_factor_challenges WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Abandoned challenges -- a sign-in nobody finished -- are deleted once a
     * day old, sampled the way LoginRateLimiter prunes its table, so nothing
     * needs cron.
     */
    private function pruneOccasionally(): void
    {
        if (random_int(1, 50) !== 1) return;
        try {
            $this->db->prepare('DELETE FROM two_factor_challenges WHERE created_at < ?')->execute([$this->stamp($this->now() - 86400)]);
        } catch (\Throwable $e) {
            error_log('[two-factor] challenge prune skipped: '.$e->getMessage());
        }
    }

    /* ---- recovery codes ---------------------------------------------------- */

    /** Replace every recovery code the account has with these hashes. */
    public function replaceRecoveryCodes(int $userId, array $hashes): void
    {
        $now = $this->stamp($this->now());
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM two_factor_recovery_codes WHERE user_id = ?')->execute([$userId]);
            $insert = $this->db->prepare('INSERT INTO two_factor_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)');
            foreach ($hashes as $hash) $insert->execute([$userId, $hash, $now]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Spend the recovery code with this hash. True for exactly one caller. */
    public function useRecoveryCode(int $userId, string $hash): bool
    {
        $stmt = $this->db->prepare('UPDATE two_factor_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
        $stmt->execute([$this->stamp($this->now()), $userId, $hash]);
        return $stmt->rowCount() === 1;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM two_factor_recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
