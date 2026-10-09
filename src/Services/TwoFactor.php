<?php
declare(strict_types=1);

namespace CloudHub\Services;

use CloudHub\Helpers\Http;
use CloudHub\Repositories\TwoFactorRepository;
use CloudHub\Repositories\UserRepository;
use CloudHub\Services\Sms\SmsException;
use CloudHub\Services\Sms\SmsSender;
use PDO;

/**
 * SMS two-step verification.
 *
 * Signing in. Auth::login() checks the password; for an account that has this
 * turned on it leaves the session waiting for a code instead of signing it in
 * (Auth::pendingSecondFactor()). beginLogin() opens the challenge,
 * sendLoginCode() texts a code, and verifyLogin() checks it -- or a recovery
 * code -- and only then does Auth::finishSecondFactor() sign the session in.
 *
 * Changing it. Every change needs the account's password. Turning it on, or
 * moving it to another number, needs the code texted to that number. Turning
 * it off and replacing the recovery codes need the current phone (or a
 * recovery code) as well; so does moving it to another number, unless this
 * session proved the current phone in the last few minutes -- signing in
 * counts -- which is what lets someone who lost their phone sign in with a
 * recovery code and move to a new number without spending a second one.
 * Without that rule "change the number, then turn it off" would make the
 * phone optional for turning it off.
 *
 * Codes are six digits from random_int(), stored only as an HMAC under a
 * server secret, valid for TWO_FACTOR_CODE_TTL_SECONDS, single-use, and good
 * for TWO_FACTOR_MAX_ATTEMPTS guesses; asking for another replaces the last.
 * Every code checked and every text sent also takes a slot from hourly limits
 * per account, per number and per client address (LoginRateLimiter::claim()).
 *
 * Neither the codes nor the numbers are logged: messages, the audit trail and
 * answers name at most a number's last two digits.
 */
final class TwoFactor
{
    public const CODE_LENGTH = 6;

    /** How long a started change waits for its code before it has to be started again. */
    public const ACTION_WINDOW = 900;

    /**
     * A second factor proven in this session this recently covers moving to a
     * new number without proving the current phone again.
     */
    public const RECENT_SECONDS = 600;

    public const RECOVERY_CODE_COUNT = 10;
    private const RECOVERY_LENGTH = 16;
    /** Lower case and digits with the easily confused ones (0 o 1 i l) left out. */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public const ACTIONS = ['phone', 'disable', 'recovery'];

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly TwoFactorRepository $repo,
        private readonly LoginRateLimiter $limiter,
        private readonly ?SmsSender $sms,
        private readonly array $config,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    /** Whether this server can send text messages at all. */
    public function smsAvailable(): bool
    {
        return $this->sms !== null;
    }

    /** What GET /api/users/me/two-factor answers: the account's settings, and what this server can do. */
    public function overview(int $userId): array
    {
        $ready = $this->repo->schemaReady();
        $state = $ready ? $this->repo->state($userId) : null;
        $enabled = (bool)($state['enabled'] ?? false);
        return [
            'available' => $ready && $this->sms !== null,
            'schemaReady' => $ready,
            'smsAvailable' => $this->sms !== null,
            'enabled' => $enabled,
            'phoneEnding' => $enabled && $state['phone'] !== null ? PhoneNumber::ending($state['phone']) : null,
            'enabledAt' => $enabled && $state['enabledAt'] !== null ? gmdate('c', $state['enabledAt']) : null,
            'recoveryCodesLeft' => $enabled ? $this->repo->recoveryCodesLeft($userId) : 0,
            'codeLength' => self::CODE_LENGTH,
        ];
    }

    /* ---- signing in ---------------------------------------------------------- */

    /**
     * Straight after Auth::login() has proven the password of an account that
     * needs a code: open the challenge the code will be checked against --
     * replacing any earlier sign-in's, whose code stops working -- and say
     * what the client needs to ask for it. Nothing is texted yet: a client
     * that cannot take a code never costs a message.
     */
    public function beginLogin(): array
    {
        $pending = Auth::pendingSecondFactor() ?? throw TwoFactorError::signInExpired();
        $userId = (int)$pending['user'];
        $state = $this->repo->state($userId) ?? throw TwoFactorError::signInExpired();
        $id = $this->repo->createChallenge($userId, 'login');
        $_SESSION['two_factor_login']['challenge'] = $id;
        return $this->signInInfo($state, $this->repo->challenge($id));
    }

    /** The second-factor part of /api/auth/status, for a session waiting for its code; null otherwise. */
    public function loginStatus(): ?array
    {
        try {
            [, $state, $challenge] = $this->pendingLogin();
        } catch (TwoFactorError) {
            return null;
        }
        return $this->signInInfo($state, $challenge);
    }

    /** Text a code for the sign-in waiting in this session. */
    public function sendLoginCode(): array
    {
        [$pending, $state, $challenge] = $this->pendingLogin();
        $userId = (int)$pending['user'];
        if ($state['phone'] === null) throw TwoFactorError::smsNotConfigured();
        if ($challenge === null) {
            $_SESSION['two_factor_login']['challenge'] = $id = $this->repo->createChallenge($userId, 'login');
            $challenge = $this->repo->challenge($id) ?? throw TwoFactorError::signInExpired();
        }
        return $this->deliver($challenge, $userId, $state['phone'], 'login', self::actor($pending));
    }

    /**
     * Check the code -- or a recovery code -- for the sign-in waiting in this
     * session, and sign it in.
     *
     * @return array{user: array, method: string, recoveryCodesLeft: ?int}
     */
    public function verifyLogin(?string $code, ?string $recoveryCode): array
    {
        [$pending, , $challenge] = $this->pendingLogin();
        $userId = (int)$pending['user'];
        $method = $recoveryCode !== null ? 'recovery' : 'sms';
        try {
            $this->prove($userId, $challenge['id'] ?? null, $code, $recoveryCode, true);
        } catch (TwoFactorError $e) {
            AuditLog::write($this->db, 'auth.two_factor', $e->countsAsFailure ? 'failure' : 'refused',
                ['method' => $method, 'reason' => $e->errorCode], self::actor($pending));
            throw $e;
        }
        $user = Auth::finishSecondFactor($this->db) ?? throw TwoFactorError::signInExpired();
        return [
            'user' => $user,
            'method' => $method,
            'recoveryCodesLeft' => $method === 'recovery' ? $this->repo->recoveryCodesLeft($userId) : null,
        ];
    }

    /** Give up the sign-in waiting in this session; its code stops working. */
    public function cancelLogin(): void
    {
        $pending = Auth::pendingSecondFactor();
        if ($pending !== null && isset($pending['challenge'])) $this->dropChallenge((string)$pending['challenge'], (int)$pending['user']);
        Auth::abandonSecondFactor();
    }

    /**
     * The sign-in waiting in this session, its account's state, and its
     * challenge (null when it was replaced by a later sign-in or used).
     *
     * @return array{0: array, 1: array, 2: ?array}
     */
    private function pendingLogin(): array
    {
        $pending = Auth::pendingSecondFactor() ?? throw TwoFactorError::signInExpired();
        $userId = (int)$pending['user'];
        $state = $this->repo->state($userId);
        if ($state === null || !$state['enabled']) {
            // Deleted, or reset by an administrator while this sign-in waited:
            // the password alone now decides, so it is asked for again.
            Auth::abandonSecondFactor();
            throw TwoFactorError::signInExpired();
        }
        $challenge = isset($pending['challenge']) ? $this->repo->challenge((string)$pending['challenge']) : null;
        if ($challenge !== null && ($challenge['user_id'] !== $userId || $challenge['purpose'] !== 'login')) $challenge = null;
        return [$pending, $state, $challenge];
    }

    private function signInInfo(array $state, ?array $challenge): array
    {
        $now = $this->now();
        return [
            'method' => 'sms',
            'phoneEnding' => $state['phone'] !== null ? PhoneNumber::ending($state['phone']) : null,
            'codeLength' => self::CODE_LENGTH,
            'smsAvailable' => $this->sms !== null && $state['phone'] !== null,
            'codeSent' => ($challenge['sent_at'] ?? null) !== null,
            'resendIn' => isset($challenge['sent_at']) ? max(0, $challenge['sent_at'] + $this->resendSeconds() - $now) : 0,
            'expiresIn' => isset($challenge['expires_at']) ? max(0, $challenge['expires_at'] - $now) : null,
        ];
    }

    /* ---- changing it ----------------------------------------------------------- */

    /**
     * Start a change: 'phone' turns it on or moves it to a new number,
     * 'disable' turns it off, 'recovery' replaces the recovery codes.
     *
     * $method 'recovery' means the current phone will be answered with a
     * recovery code, so no text is sent to it.
     */
    public function startAction(int $userId, string $username, string $action, string $password, ?string $phoneInput, string $method = 'sms'): array
    {
        if (!in_array($action, self::ACTIONS, true)) throw TwoFactorError::unknownAction();
        if (!$this->repo->schemaReady()) throw TwoFactorError::notMigrated();
        $this->reauthenticate($userId, $username, $password);
        $state = $this->repo->state($userId) ?? throw TwoFactorError::noAction();

        $phone = null;
        if ($action === 'phone') {
            $phone = PhoneNumber::normalize((string)$phoneInput) ?? throw TwoFactorError::phoneInvalid();
            if ($state['enabled'] && $state['phone'] === $phone) throw TwoFactorError::samePhone();
            // A number nobody can text cannot be proven, so it cannot be set.
            if ($this->sms === null) throw TwoFactorError::smsNotConfigured();
        } elseif (!$state['enabled']) {
            throw TwoFactorError::notEnabled();
        }

        $recent = $this->recentlyVerified();
        $proveCurrent = $state['enabled'] && ($action !== 'phone' || !$recent);
        $this->cancelAction($userId);
        $_SESSION['two_factor_action'] = [
            'user' => $userId,
            'action' => $action,
            'stage' => $proveCurrent ? 'current' : 'new',
            'phone' => $phone,
            'method' => $proveCurrent && $method === 'recovery' ? 'recovery' : 'sms',
            // Whether the current phone counts as proven for this change: true
            // once it is, or when this session proved it a moment ago.
            'currentProven' => $state['enabled'] && !$proveCurrent,
            'at' => $this->now(),
            'challenge' => null,
        ];
        return $this->openStage($userId);
    }

    /** Text the code for the change under way again. */
    public function resendAction(int $userId): array
    {
        $flow = $this->pendingAction($userId);
        $state = $this->repo->state($userId) ?? throw TwoFactorError::noAction();
        $challenge = $flow['challenge'] !== null ? $this->repo->challenge((string)$flow['challenge']) : null;
        if ($challenge === null || $challenge['user_id'] !== $userId) throw TwoFactorError::noAction();
        [$target, $purpose] = $this->stageTarget($flow, $state);
        if ($target === null) throw TwoFactorError::smsNotConfigured();
        $_SESSION['two_factor_action']['method'] = 'sms';
        return array_merge($this->stageInfo($flow, $target), $this->deliver($challenge, $userId, $target, $purpose));
    }

    /**
     * Check the code for the stage the change is at, and carry it out -- or,
     * when the current phone has just been proven for a new number, move on
     * to proving the new one.
     */
    public function confirmAction(int $userId, ?string $code, ?string $recoveryCode): array
    {
        $flow = $this->pendingAction($userId);
        $current = $flow['stage'] === 'current';
        try {
            $this->prove($userId, $flow['challenge'] !== null ? (string)$flow['challenge'] : null, $code, $recoveryCode, $current);
        } catch (TwoFactorError $e) {
            AuditLog::write($this->db, 'two_factor.'.$flow['action'], $e->countsAsFailure ? 'failure' : 'refused',
                ['stage' => $flow['stage'], 'method' => $recoveryCode !== null ? 'recovery' : 'sms', 'reason' => $e->errorCode]);
            throw $e;
        }

        if ($current) {
            // The current phone, or a recovery code, has just been proven here.
            $_SESSION['two_factor_verified_at'] = $this->now();
            if ($flow['action'] === 'phone') {
                $_SESSION['two_factor_action']['stage'] = 'new';
                $_SESSION['two_factor_action']['method'] = 'sms';
                $_SESSION['two_factor_action']['currentProven'] = true;
                return ['done' => false] + $this->openStage($userId);
            }
        }
        unset($_SESSION['two_factor_action']);
        return ['done' => true] + $this->apply($userId, $flow);
    }

    /** Abandon the change under way, if any; its code stops working. */
    public function cancelAction(int $userId): void
    {
        $flow = $_SESSION['two_factor_action'] ?? null;
        if (is_array($flow) && (int)($flow['user'] ?? 0) === $userId && !empty($flow['challenge'])) {
            $this->dropChallenge((string)$flow['challenge'], $userId);
        }
        unset($_SESSION['two_factor_action']);
    }

    /**
     * An administrator turns it off for someone who has lost their phone and
     * their recovery codes. Their own password is asked for first; the owner's
     * phone is told, so a reset nobody asked for does not go unnoticed.
     */
    public function adminReset(int $adminId, string $adminUsername, string $password, int $targetId): array
    {
        $this->reauthenticate($adminId, $adminUsername, $password);
        $state = $this->repo->state($targetId) ?? throw new \RuntimeException('Account not found', 404);
        if (!$state['enabled']) throw TwoFactorError::notEnabled();
        $this->repo->disable($targetId);
        if ($state['phone'] !== null) $this->notify($state['phone'], 'reset');
        return ['phoneEnding' => $state['phone'] !== null ? PhoneNumber::ending($state['phone']) : null];
    }

    /** The change under way in this session for this account. */
    private function pendingAction(int $userId): array
    {
        $flow = $_SESSION['two_factor_action'] ?? null;
        if (!is_array($flow) || (int)($flow['user'] ?? 0) !== $userId || $this->now() - (int)($flow['at'] ?? 0) > self::ACTION_WINDOW) {
            unset($_SESSION['two_factor_action']);
            throw TwoFactorError::noAction();
        }
        return $flow;
    }

    /**
     * Open the challenge for the stage the change is at and text its code --
     * unless a recovery code will answer it. A text that cannot be sent is
     * reported in the answer rather than thrown: the change is under way, and
     * the code can be asked for again or answered with a recovery code.
     */
    private function openStage(int $userId): array
    {
        $flow = $_SESSION['two_factor_action'];
        $state = $this->repo->state($userId) ?? throw TwoFactorError::noAction();
        [$target, $purpose] = $this->stageTarget($flow, $state);
        $id = $this->repo->createChallenge($userId, $flow['stage'] === 'current' ? 'confirm' : 'phone');
        $_SESSION['two_factor_action']['challenge'] = $id;
        $info = $this->stageInfo($flow, $target);
        if ($flow['method'] === 'recovery') return $info;
        if ($target === null) {
            $e = TwoFactorError::smsNotConfigured();
            return $info + ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'retryAfter' => 0]];
        }
        try {
            $challenge = $this->repo->challenge($id) ?? throw TwoFactorError::noAction();
            return array_merge($info, $this->deliver($challenge, $userId, $target, $purpose));
        } catch (TwoFactorError $e) {
            return $info + ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'retryAfter' => $e->retryAfter]];
        }
    }

    /** @return array{0: ?string, 1: string} where this stage's code goes, and what its message says it is for */
    private function stageTarget(array $flow, array $state): array
    {
        if ($flow['stage'] === 'new') return [$flow['phone'], 'new-phone'];
        return [$state['phone'], $flow['action'] === 'phone' ? 'change' : $flow['action']];
    }

    private function stageInfo(array $flow, ?string $target): array
    {
        return [
            'action' => $flow['action'],
            'stage' => $flow['stage'],
            'phoneEnding' => $target !== null ? PhoneNumber::ending($target) : null,
            'codeLength' => self::CODE_LENGTH,
            'recoveryAllowed' => $flow['stage'] === 'current',
            'sent' => false,
            'resendIn' => 0,
            'expiresIn' => null,
        ];
    }

    /** Carry out a change whose codes have all been proven. */
    private function apply(int $userId, array $flow): array
    {
        $before = $this->repo->state($userId) ?? throw TwoFactorError::noAction();
        if ($flow['action'] === 'phone') {
            // Turned on in another session since this change began, without
            // this one proving that phone: start again, which will ask for it.
            if ($before['enabled'] && empty($flow['currentProven'])) throw TwoFactorError::noAction();
            $phone = (string)$flow['phone'];
            $turnedOn = $this->repo->enable($userId, $phone);
            // This session has just proven the phone the account now uses.
            $_SESSION['two_factor_verified_at'] = $this->now();
            $codes = $turnedOn ? $this->issueRecoveryCodes($userId) : null;
            AuditLog::write($this->db, $turnedOn ? 'two_factor.enable' : 'two_factor.phone_change', 'success',
                ['phoneEnding' => PhoneNumber::ending($phone)]);
            if (!$turnedOn && $before['phone'] !== null && $before['phone'] !== $phone) $this->notify($before['phone'], 'phone-changed');
            return ['enabled' => true, 'phoneEnding' => PhoneNumber::ending($phone), 'recoveryCodes' => $codes,
                'recoveryCodesLeft' => $this->repo->recoveryCodesLeft($userId)];
        }
        if ($flow['action'] === 'disable') {
            $this->repo->disable($userId);
            AuditLog::write($this->db, 'two_factor.disable', 'success');
            if ($before['phone'] !== null) $this->notify($before['phone'], 'disabled');
            return ['enabled' => false];
        }
        $codes = $this->issueRecoveryCodes($userId);
        AuditLog::write($this->db, 'two_factor.recovery_codes', 'success');
        return ['enabled' => true, 'recoveryCodes' => $codes, 'recoveryCodesLeft' => count($codes)];
    }

    private function recentlyVerified(): bool
    {
        $at = (int)($_SESSION['two_factor_verified_at'] ?? 0);
        return $at > 0 && $this->now() - $at <= self::RECENT_SECONDS;
    }

    /**
     * The account's password, asked for again before any change. Wrong
     * guesses count against the same limits as signing in, so a borrowed
     * session cannot be used to guess the password here instead.
     */
    private function reauthenticate(int $userId, string $username, string $password): void
    {
        $this->limiter->assertAllowed($username);
        if (!(new UserRepository($this->db))->verifyPassword($userId, $password)) {
            $this->limiter->recordFailure($username);
            AuditLog::write($this->db, 'two_factor.reauth', 'failure');
            throw TwoFactorError::wrongPassword();
        }
    }

    /* ---- the codes ------------------------------------------------------------ */

    /**
     * Check a second factor for $userId: an SMS code against $challengeId, or
     * a recovery code. Spends what it is given -- an attempt on the code, the
     * recovery code -- and throws TwoFactorError when it does not hold.
     *
     * Each check takes a slot from the per-account and per-address failure
     * limits before anything is compared, and gives it back when the answer
     * was right or was not a guess at all.
     */
    private function prove(int $userId, ?string $challengeId, ?string $code, ?string $recoveryCode, bool $recoveryAllowed): void
    {
        if ($recoveryCode !== null) {
            if (!$recoveryAllowed) throw TwoFactorError::recoveryNotAllowed();
            $normalized = self::normalizeRecoveryCode($recoveryCode)
                ?? throw new TwoFactorError('VALIDATION_FAILED', 'A recovery code is 16 letters and digits, like abcd-efgh-jkmn-pqrs.', 422);
        } else {
            $code = preg_replace('/\s+/', '', (string)$code) ?? '';
            if (preg_match('/^[0-9]{'.self::CODE_LENGTH.'}$/', $code) !== 1) throw TwoFactorError::malformedCode(self::CODE_LENGTH);
        }

        $slots = $this->claimVerifySlots($userId);
        try {
            if ($recoveryCode !== null) {
                if (!$this->repo->useRecoveryCode($userId, self::recoveryHash($userId, $normalized))) throw TwoFactorError::recoveryWrong();
            } else {
                $this->checkCode($userId, $challengeId, $code);
            }
        } catch (TwoFactorError $e) {
            if (!$e->countsAsFailure) $this->releaseSlots($slots);
            throw $e;
        }
        $this->releaseSlots($slots);
    }

    private function checkCode(int $userId, ?string $challengeId, string $code): void
    {
        $challenge = $challengeId !== null ? $this->repo->challenge($challengeId) : null;
        if ($challenge === null || $challenge['user_id'] !== $userId) throw TwoFactorError::codeExpired();
        if ($challenge['code_hash'] === null) throw TwoFactorError::noCodeSent();
        if ($challenge['expires_at'] === null || $challenge['expires_at'] <= $this->now()) throw TwoFactorError::codeExpired();
        $max = $this->maxAttempts();
        if ($challenge['attempts'] >= $max) throw TwoFactorError::tooManyAttempts();

        $after = $this->repo->spendAttempt($challenge['id'], $max);
        if ($after === null) {
            // Another request spent the last attempt, or used the code, while
            // this one was looking.
            $again = $this->repo->challenge($challenge['id']);
            if ($again !== null && $again['attempts'] >= $max) throw TwoFactorError::tooManyAttempts();
            throw TwoFactorError::codeExpired();
        }
        if ($after['code_hash'] === null || !hash_equals($after['code_hash'], $this->codeHash($after['id'], $code))) {
            throw TwoFactorError::codeWrong(max(0, $max - $after['attempts']));
        }
        // Right. Using it is one more compare-and-set: of two requests that
        // both got this far with it, exactly one succeeds.
        if (!$this->repo->consume($after['id'])) throw TwoFactorError::codeExpired();
    }

    /** @return list<int> */
    private function claimVerifySlots(int $userId): array
    {
        $ip = $this->limiter->clientIp();
        $perUser = $this->setting('two_factor_failures_per_hour', 10, 1, 100);
        $perIp = $this->setting('two_factor_ip_failures_per_hour', 30, 1, 1000);
        $user = $this->limiter->claim('verify_user', (string)$userId, $perUser, 3600);
        if ($user === null) throw TwoFactorError::locked($this->limiter->retryAfter('verify_user', (string)$userId, $perUser, 3600));
        $address = $this->limiter->claim('verify_ip', $ip, $perIp, 3600);
        if ($address === null) {
            $this->limiter->release($user);
            throw TwoFactorError::locked($this->limiter->retryAfter('verify_ip', $ip, $perIp, 3600));
        }
        return [$user, $address];
    }

    /** @param list<int> $slots */
    private function releaseSlots(array $slots): void
    {
        foreach ($slots as $slot) $this->limiter->release($slot);
    }

    /**
     * Put a fresh code on the challenge and text it to $phone.
     *
     * The hourly limits are taken first; then the challenge's cooldown is a
     * compare-and-set, so a double-click sends one message. A gateway that
     * refused the number sent nothing and gives the hourly slots back; an
     * outage keeps them -- a timeout can come after the gateway took the
     * message -- and the code stays valid in case it arrives.
     */
    private function deliver(array $challenge, int $userId, string $phone, string $purpose, ?array $actor = null): array
    {
        if ($this->sms === null) throw TwoFactorError::smsNotConfigured();
        $ip = $this->limiter->clientIp();
        $perHour = $this->setting('two_factor_sms_per_hour', 5, 1, 100);
        $slots = [];
        foreach ([['sms_user', (string)$userId, $perHour], ['sms_phone', $phone, $perHour],
                  ['sms_ip', $ip, $this->setting('two_factor_sms_ip_per_hour', 20, 1, 1000)]] as [$scope, $value, $max]) {
            $slot = $this->limiter->claim($scope, $value, $max, 3600);
            if ($slot === null) {
                $this->releaseSlots($slots);
                AuditLog::write($this->db, 'two_factor.sms', 'blocked', ['purpose' => $purpose, 'limit' => $scope], $actor);
                throw TwoFactorError::smsLimit($this->limiter->retryAfter($scope, $value, $max, 3600));
            }
            $slots[] = $slot;
        }

        $code = self::generateCode();
        if (!$this->repo->arm($challenge['id'], $this->codeHash($challenge['id'], $code), $this->ttl(), $this->resendSeconds())) {
            $this->releaseSlots($slots);
            $current = $this->repo->challenge($challenge['id']) ?? throw TwoFactorError::codeExpired();
            throw TwoFactorError::resendCooldown(max(1, (int)$current['sent_at'] + $this->resendSeconds() - $this->now()));
        }

        try {
            $this->sms->send($phone, $this->message($purpose, $code));
        } catch (SmsException $e) {
            if ($e->kind !== SmsException::UNAVAILABLE) $this->releaseSlots($slots);
            error_log('['.Http::requestId().'] two-step code not sent: '.$e->getMessage());
            AuditLog::write($this->db, 'two_factor.sms', 'failure', ['purpose' => $purpose, 'provider' => $this->sms->name(), 'kind' => $e->kind], $actor);
            throw TwoFactorError::fromSms($e, $this->resendSeconds());
        }
        AuditLog::write($this->db, 'two_factor.sms', 'success',
            ['purpose' => $purpose, 'provider' => $this->sms->name(), 'phoneEnding' => PhoneNumber::ending($phone)], $actor);
        return ['sent' => true, 'phoneEnding' => PhoneNumber::ending($phone), 'expiresIn' => $this->ttl(),
            'resendIn' => $this->resendSeconds(), 'codeLength' => self::CODE_LENGTH];
    }

    private function dropChallenge(string $id, int $userId): void
    {
        $challenge = $this->repo->challenge($id);
        if ($challenge !== null && $challenge['user_id'] === $userId) $this->repo->consume($id);
    }

    /** Text the old number about a change it did not have to approve. Best effort. */
    private function notify(string $phone, string $what): void
    {
        if ($this->sms === null) return;
        $app = $this->appName();
        $text = match ($what) {
            'phone-changed' => "The phone number for your $app sign-in codes was just changed. If you didn't do this, contact your administrator.",
            'disabled' => "Two-step verification was turned off for your $app account. If you didn't do this, contact your administrator.",
            default => "An administrator turned off two-step verification for your $app account. If you didn't ask for this, contact them.",
        };
        try {
            $this->sms->send($phone, $text);
        } catch (SmsException $e) {
            error_log('['.Http::requestId().'] two-step notice not sent: '.$e->getMessage());
        }
    }

    /** The text of a code message; what it is for is spelled out, so a code nobody asked for stands out. */
    public function message(string $purpose, string $code): string
    {
        $minutes = intdiv($this->ttl() + 59, 60);
        $what = match ($purpose) {
            'login' => 'sign-in code',
            'disable' => 'code to turn off two-step verification',
            'recovery' => 'code to create new recovery codes',
            'change' => 'code to change your two-step verification number',
            'new-phone' => 'code to confirm this number for two-step verification',
            default => throw new \InvalidArgumentException('Unknown message purpose'),
        };
        $text = $code.' is your '.$this->appName().' '.$what.'. It expires in '.$minutes.' minute'.($minutes === 1 ? '' : 's')
            .". Don't share it with anyone.";
        // An origin-bound line lets Android Chrome offer the code to the
        // sign-in page (WebOTP) -- and only to that site, which is what makes
        // a phishing page useless for it. The host comes from APP_URL, never
        // from the request: a Host header is the client's to choose.
        $host = $this->originHost();
        return $host === null ? $text : $text."\n\n@".$host.' #'.$code;
    }

    private function appName(): string
    {
        $name = trim(mb_substr(preg_replace('/\p{C}+/u', '', (string)($this->config['sms_app_name'] ?? '')) ?? '', 0, 30));
        return $name !== '' ? $name : 'CloudHub';
    }

    private function originHost(): ?string
    {
        $parts = parse_url((string)($this->config['app_url'] ?? ''));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return null;
        if (isset($parts['port']) && (int)$parts['port'] !== 443) return null;
        $host = strtolower((string)($parts['host'] ?? ''));
        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1 ? $host : null;
    }

    /** A code: CODE_LENGTH digits, uniformly from the CSPRNG. */
    public static function generateCode(): string
    {
        return str_pad((string)random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * What is stored for a code: an HMAC bound to its challenge, under a key
     * the database does not hold. A stolen copy of the table is then not
     * enough to recover a live code, even though a six-digit one could
     * otherwise be found by trying them all.
     */
    public function codeHash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', 'cloudhub-two-factor|'.$challengeId.'|'.$code, $this->secret());
    }

    private function secret(): string
    {
        $secret = (string)($this->config['two_factor_secret'] ?? '');
        if ($secret !== '') return $secret;
        // Without TWO_FACTOR_SECRET the rate limiter's key stands in, and
        // without that, a derived one. Each still keeps the key out of the
        // database; SECURITY.md asks for a key of its own.
        $fallback = (string)($this->config['rate_limit_secret'] ?? '');
        return hash('sha256', 'cloudhub-two-factor|'.($fallback !== '' ? $fallback : (string)($this->config['app_url'] ?? '')));
    }

    /** @return list<string> the new codes, formatted to be shown once */
    private function issueRecoveryCodes(int $userId): array
    {
        $codes = [];
        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $code = self::generateRecoveryCode();
            if (!in_array($code, $codes, true)) $codes[] = $code;
        }
        $this->repo->replaceRecoveryCodes($userId, array_map(static fn(string $c): string => self::recoveryHash($userId, $c), $codes));
        return array_map([self::class, 'formatRecoveryCode'], $codes);
    }

    /** 16 characters from a 31-letter alphabet: 79 bits, beyond any brute force of the stored hash. */
    public static function generateRecoveryCode(): string
    {
        $code = '';
        $last = strlen(self::RECOVERY_ALPHABET) - 1;
        for ($i = 0; $i < self::RECOVERY_LENGTH; $i++) $code .= self::RECOVERY_ALPHABET[random_int(0, $last)];
        return $code;
    }

    public static function formatRecoveryCode(string $normalized): string
    {
        return implode('-', str_split($normalized, 4));
    }

    /** A recovery code as typed -- any case, with or without dashes and spaces -- or null if it cannot be one. */
    public static function normalizeRecoveryCode(string $input): ?string
    {
        $code = strtolower(preg_replace('/[\s-]+/', '', $input) ?? '');
        return strlen($code) === self::RECOVERY_LENGTH && strspn($code, self::RECOVERY_ALPHABET) === self::RECOVERY_LENGTH ? $code : null;
    }

    /**
     * What is stored for a recovery code. With 79 bits of randomness a plain
     * SHA-256 is already out of reach of guessing, which keeps checking one a
     * single indexed lookup -- and spending it a single compare-and-set.
     */
    public static function recoveryHash(int $userId, string $normalized): string
    {
        return hash('sha256', 'cloudhub-recovery|'.$userId.'|'.$normalized);
    }

    private function ttl(): int
    {
        return $this->setting('two_factor_code_ttl_seconds', 300, 60, 900);
    }

    private function maxAttempts(): int
    {
        return $this->setting('two_factor_max_attempts', 5, 1, 10);
    }

    private function resendSeconds(): int
    {
        return $this->setting('two_factor_resend_seconds', 60, 30, 900);
    }

    private function setting(string $key, int $default, int $min, int $max): int
    {
        $value = (int)($this->config[$key] ?? $default);
        return max($min, min($max, $value > 0 ? $value : $default));
    }

    /** @return array{id:int,username:string} */
    private static function actor(array $pending): array
    {
        return ['id' => (int)$pending['user'], 'username' => (string)($pending['username'] ?? '')];
    }
}
