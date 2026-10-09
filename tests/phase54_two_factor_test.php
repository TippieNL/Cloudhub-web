<?php
declare(strict_types=1);

/**
 * SMS two-step verification, exercised in one process.
 *
 * Runs against SQLite with the tables taken from database/schema.sql, so a
 * column the code writes and the schema lacks fails here, with a clock the
 * test moves and an SMS gateway that records instead of sending. Auth's own
 * sign-in runs for real against a PHP session.
 *
 * What a real deployment adds -- MariaDB, HTTP, cookies, CSRF, a gateway over
 * the network, sessions ended on another device -- is covered by
 * tests/http/two_factor_run.php.
 *
 * Every session operation runs before anything is printed: PHP will not start
 * or regenerate a session once output has gone, so results are collected and
 * reported at the end.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CloudHub\\')) return;
    $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, 9)).'.php';
    if (is_file($file)) require $file;
});

use CloudHub\Repositories\TwoFactorRepository;
use CloudHub\Services\Auth;
use CloudHub\Services\LoginRateLimiter;
use CloudHub\Services\PhoneNumber;
use CloudHub\Services\Sms\HttpTransport;
use CloudHub\Services\Sms\LogSms;
use CloudHub\Services\Sms\Sms;
use CloudHub\Services\Sms\SmsException;
use CloudHub\Services\Sms\SmsSender;
use CloudHub\Services\Sms\TwilioSms;
use CloudHub\Services\Sms\WebhookSms;
use CloudHub\Services\TwoFactor;
use CloudHub\Services\TwoFactorError;

$root = dirname(__DIR__);
$scratch = sys_get_temp_dir().'/cloudhub-p54-'.bin2hex(random_bytes(5));
mkdir($scratch.'/sessions', 0775, true);
// Gateway failures and audit problems are logged, not thrown: keep them out
// of the output, and read them back to prove no code or number is in them.
$errorLog = $scratch.'/error.log';
ini_set('error_log', $errorLog);
ini_set('session.save_path', $scratch.'/sessions');
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_strict_mode', '0');
ini_set('session.cache_limiter', '');
session_start();

$checks = [];

/** The error code a call is refused with, 'HTTP<status>' for anything else, null when it goes through. */
function refusal(callable $fn): ?string
{
    try { $fn(); return null; }
    catch (TwoFactorError $e) { return $e->errorCode; }
    catch (RuntimeException $e) { return 'HTTP'.$e->getCode(); }
}

final class FakeSms implements SmsSender
{
    /** @var list<array{to:string,message:string}> */
    public array $sent = [];
    public ?SmsException $fail = null;
    public function send(string $to, string $message): string
    {
        if ($this->fail !== null) throw $this->fail;
        $this->sent[] = ['to' => $to, 'message' => $message];
        return 'fake-'.count($this->sent);
    }
    public function name(): string { return 'fake'; }
    public function lastCode(): string
    {
        $last = end($this->sent);
        return $last !== false && preg_match('/^(\d{6}) /', $last['message'], $m) ? $m[1] : '';
    }
}

final class FakeTransport implements HttpTransport
{
    public array $requests = [];
    /** @param array{0:int,1:string}|Throwable $answer */
    public function __construct(private readonly array|Throwable $answer) {}
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        if ($this->answer instanceof Throwable) throw $this->answer;
        return $this->answer;
    }
}

// --- the database ------------------------------------------------------------------

$schema = (string)file_get_contents($root.'/database/schema.sql');
function sqlite_table(string $schema, string $table): string
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS '.$table.' \((.*?)\) ENGINE=[^;]*;/s', $schema, $m)) return '';
    $body = preg_replace([
        '/\b(?:BIG)?INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/', '/ENUM\([^)]*\)/', '/\bUNSIGNED\b/',
        '/UNIQUE KEY \w+\s*\(/', '/ON UPDATE CURRENT_TIMESTAMP/',
    ], ['INTEGER PRIMARY KEY AUTOINCREMENT', 'TEXT', '', 'UNIQUE(', ''], $m[1]) ?? '';
    $lines = array_values(array_filter(array_map('rtrim', preg_split('/\R/', $body) ?: []),
        static fn(string $l): bool => trim($l) !== '' && !str_starts_with(trim($l), '--') && !str_starts_with(trim($l), 'INDEX ')));
    if ($lines) $lines[count($lines) - 1] = rtrim($lines[count($lines) - 1], ',');
    return 'CREATE TABLE '.$table.' ('.implode("\n", $lines).')';
}
$tables = ['users', 'login_attempts', 'security_events', 'two_factor_challenges', 'two_factor_recovery_codes'];
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
foreach ($tables as $table) {
    $ddl = sqlite_table($schema, $table);
    $checks["schema.sql defines $table"] = $ddl !== '';
    if ($ddl !== '') $db->exec($ddl);
}
$hash = static fn(string $p): string => password_hash($p, PASSWORD_BCRYPT, ['cost' => 4]);
$addUser = $db->prepare('INSERT INTO users (id, username, password_hash, is_active, role) VALUES (?, ?, ?, ?, ?)');
foreach ([[1, 'alice', 'alice-pass-1234', 1, 'editor'], [2, 'bob', 'bob-pass-12345', 1, 'viewer'],
          [3, 'admin', 'admin-pass-1234', 1, 'admin'], [4, 'carol', 'carol-pass-1234', 1, 'editor']] as $u) {
    $addUser->execute([$u[0], $u[1], $hash($u[2]), $u[3], $u[4]]);
}

$now = time();
$clock = static function () use (&$now): int { return $now; };
$config = [
    'app_url' => 'https://cloud.example.com', 'app_env' => 'production', 'sms_app_name' => 'CloudHub',
    'two_factor_secret' => str_repeat('k', 64), 'rate_limit_secret' => 'r',
    'two_factor_code_ttl_seconds' => 300, 'two_factor_max_attempts' => 5, 'two_factor_resend_seconds' => 60,
    'two_factor_sms_per_hour' => 5, 'two_factor_sms_ip_per_hour' => 20,
    'two_factor_failures_per_hour' => 10, 'two_factor_ip_failures_per_hour' => 30,
    'login_rate_window_seconds' => 900, 'login_rate_user_attempts' => 5, 'login_rate_ip_attempts' => 20,
];
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$repo = new TwoFactorRepository($db, $clock);
$limiter = new LoginRateLimiter($db, $config, $clock);
$sms = new FakeSms();
$tf = new TwoFactor($db, $repo, $limiter, $sms, $config, $clock);
$resetThrottles = static function () use ($db): void { $db->exec('DELETE FROM login_attempts'); };

// A scenario that throws must still fail by name: results are printed only at
// the end, so an uncaught exception would otherwise end the run with nothing
// said about which property broke.
try {

// --- phone numbers -----------------------------------------------------------------

$checks['an international number is kept, tidied'] = PhoneNumber::normalize(' +31 6-1234 5678 ') === '+31612345678';
$checks['"00" stands for "+"'] = PhoneNumber::normalize('0031612345678') === '+31612345678';
$checks['the "(0)" of a business card is dropped'] = PhoneNumber::normalize('+31 (0)6 1234 5678') === '+31612345678';
$checks['a local number is refused rather than guessed'] = PhoneNumber::normalize('06 12345678') === null;
$checks['letters, too-long and too-short numbers are refused'] = PhoneNumber::normalize('+31 6 CALL ME') === null
    && PhoneNumber::normalize('+1234567890123456') === null && PhoneNumber::normalize('+12345') === null
    && PhoneNumber::normalize('+0612345678') === null && PhoneNumber::normalize('') === null;
$checks['only the last two digits are ever shown'] = PhoneNumber::ending('+31612345678') === '78';

// --- codes ---------------------------------------------------------------------------

$codes = array_map(static fn(): string => TwoFactor::generateCode(), range(1, 400));
$checks['codes are six digits, leading zeros kept'] = count(array_filter($codes, static fn(string $c): bool => preg_match('/^\d{6}$/', $c) === 1)) === 400;
$checks['and do not repeat in a run of 400'] = count(array_unique($codes)) >= 399;
$checks['codes come from the CSPRNG'] = str_contains((string)file_get_contents($root.'/src/Services/TwoFactor.php'), 'random_int(0, 10 ** self::CODE_LENGTH - 1)');
$checks['a stored code is an HMAC bound to its challenge'] = $tf->codeHash(str_repeat('a', 32), '123456') !== $tf->codeHash(str_repeat('b', 32), '123456')
    && $tf->codeHash(str_repeat('a', 32), '123456') === hash_hmac('sha256', 'cloudhub-two-factor|'.str_repeat('a', 32).'|123456', str_repeat('k', 64));
$other = new TwoFactor($db, $repo, $limiter, $sms, ['two_factor_secret' => 'another-key'] + $config, $clock);
$checks['under the server secret, which the database does not hold'] = $tf->codeHash(str_repeat('a', 32), '123456') !== $other->codeHash(str_repeat('a', 32), '123456');
$recovery = TwoFactor::generateRecoveryCode();
$checks['recovery codes are 16 unambiguous characters'] = preg_match('/^[abcdefghjkmnpqrstuvwxyz23456789]{16}$/', $recovery) === 1;
$checks['shown in groups of four'] = preg_match('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', TwoFactor::formatRecoveryCode($recovery)) === 1;
$checks['typed in any case, with or without dashes'] = TwoFactor::normalizeRecoveryCode(strtoupper(implode(' ', str_split($recovery, 4)))) === $recovery
    && TwoFactor::normalizeRecoveryCode('not-a-code') === null && TwoFactor::normalizeRecoveryCode(str_repeat('0', 16)) === null;
$checks['a recovery code is stored as a hash bound to its account'] = TwoFactor::recoveryHash(1, $recovery) !== TwoFactor::recoveryHash(2, $recovery)
    && strlen(TwoFactor::recoveryHash(1, $recovery)) === 64;
$message = $tf->message('login', '012345');
$checks['the message says what the code is for, and for how long'] = str_starts_with($message, '012345 is your CloudHub sign-in code. It expires in 5 minutes.');
$checks['and ends with the origin-bound line for WebOTP'] = str_ends_with($message, "\n\n@cloud.example.com #012345");
$plain = new TwoFactor($db, $repo, $limiter, $sms, ['app_url' => 'http://192.168.1.4:8080'] + $config, $clock);
$checks['which is left out unless APP_URL is an https domain'] = !str_contains($plain->message('login', '012345'), '@');
$checks['an account change names itself in the message'] = str_contains($tf->message('disable', '1'), 'to turn off two-step verification')
    && str_contains($tf->message('new-phone', '1'), 'to confirm this number');

// --- the gateways ------------------------------------------------------------------

$sid = 'SM'.str_repeat('0123456789abcdef', 2);
$t = new FakeTransport([201, json_encode(['sid' => $sid, 'status' => 'queued'])]);
$twilio = new TwilioSms('AC'.str_repeat('1', 32), 'token', '+15005550006', '', $t, 7);
$checks['Twilio: an accepted message returns its SID'] = $twilio->send('+31612345678', 'hello') === $sid;
$sent = $t->requests[0];
parse_str($sent['body'], $form);
$checks['Twilio: one POST to the account\'s Messages endpoint'] = $sent['url'] === 'https://api.twilio.com/2010-04-01/Accounts/AC'.str_repeat('1', 32).'/Messages.json'
    && $sent['timeout'] === 7;
$checks['Twilio: basic auth with the SID and token'] = in_array('Authorization: Basic '.base64_encode('AC'.str_repeat('1', 32).':token'), $sent['headers'], true);
$checks['Twilio: To, Body and From are sent'] = ($form['To'] ?? '') === '+31612345678' && ($form['Body'] ?? '') === 'hello' && ($form['From'] ?? '') === '+15005550006';
$t = new FakeTransport([201, json_encode(['sid' => $sid])]);
(new TwilioSms('AC'.str_repeat('1', 32), 'token', '', 'MG'.str_repeat('2', 32), $t))->send('+31612345678', 'x');
parse_str($t->requests[0]['body'], $form);
$checks['Twilio: a messaging service replaces From'] = ($form['MessagingServiceSid'] ?? '') === 'MG'.str_repeat('2', 32) && !isset($form['From']);
$twilioKind = static function (array|Throwable $answer): ?string {
    try { (new TwilioSms('AC'.str_repeat('1', 32), 't', '+1', '', new FakeTransport($answer)))->send('+31612345678', 'x'); return null; }
    catch (SmsException $e) { return $e->kind.': '.$e->getMessage(); }
};
$checks['Twilio: an invalid number is a refusal'] = str_starts_with((string)$twilioKind([400, '{"code":21211,"message":"The \'To\' number +31612345678 is not a valid phone number.","status":400}']), 'rejected');
$checks['Twilio: and its message, which quotes the number, is not passed on'] = !str_contains((string)$twilioKind([400, '{"code":21211,"message":"The \'To\' number +31612345678 is not valid."}']), '612345678');
$checks['Twilio: bad credentials are an outage, not the number\'s fault'] = str_starts_with((string)$twilioKind([401, '{"code":20003}']), 'unavailable');
$checks['Twilio: a server error is an outage'] = str_starts_with((string)$twilioKind([503, 'Service Unavailable']), 'unavailable');
$checks['Twilio: no answer at all is an outage'] = str_starts_with((string)$twilioKind(new RuntimeException('Connection timed out')), 'unavailable');
$checks['Twilio: a 2xx that is not JSON is not reported as sent'] = str_starts_with((string)$twilioKind([201, '<html>ok</html>']), 'unavailable');
$checks['Twilio: nor one without a message SID'] = str_starts_with((string)$twilioKind([200, '{"status":"queued"}']), 'unavailable')
    && str_starts_with((string)$twilioKind([201, '{"sid":"nonsense"}']), 'unavailable');
$checks['Twilio: a message it already failed is a refusal'] = str_starts_with((string)$twilioKind([201, json_encode(['sid' => $sid, 'status' => 'failed'])]), 'rejected');

$t = new FakeTransport([202, '{"id":"abc-123"}']);
$hook = new WebhookSms('https://sms.example.com/send', 'hook-token', 'CloudHub', $t, 5);
$checks['webhook: an accepted message returns its id'] = $hook->send('+31612345678', 'hi') === 'abc-123';
$checks['webhook: JSON with to, from and message, and the bearer token'] = json_decode($t->requests[0]['body'], true) === ['to' => '+31612345678', 'from' => 'CloudHub', 'message' => 'hi']
    && in_array('Authorization: Bearer hook-token', $t->requests[0]['headers'], true);
$hookKind = static function (array|Throwable $answer): ?string {
    try { (new WebhookSms('https://h/s', '', '', new FakeTransport($answer)))->send('+31612345678', 'x'); return null; }
    catch (SmsException $e) { return $e->kind; }
};
$checks['webhook: any 2xx is accepted, whatever its body'] = $hookKind([200, 'not json']) === null && $hookKind([204, '']) === null;
$checks['webhook: 400 and 422 refuse the number'] = $hookKind([400, '']) === SmsException::REJECTED && $hookKind([422, '{}']) === SmsException::REJECTED;
$checks['webhook: anything else is an outage'] = $hookKind([401, '']) === SmsException::UNAVAILABLE && $hookKind([500, '']) === SmsException::UNAVAILABLE
    && $hookKind([302, '']) === SmsException::UNAVAILABLE && $hookKind(new RuntimeException('refused')) === SmsException::UNAVAILABLE;

// The two SMS gateway apps, spoken to as their own servers expect. Traccar's
// compares the Authorization header with its key as it stands and fails on a
// field it does not know; sms-gate.app's local server takes basic auth.
$t = new FakeTransport([200, '']);
(new WebhookSms('http://192.168.1.20:8082/', 'traccar-key', 'CloudHub', $t, 5, 'traccar'))->send('+31612345678', 'hi');
$checks['webhook, traccar: to and message, and nothing else'] = $t->requests[0]['body'] === '{"to":"+31612345678","message":"hi"}';
$checks['webhook, traccar: the key as the Authorization header, with no Bearer'] = in_array('Authorization: traccar-key', $t->requests[0]['headers'], true)
    && !preg_grep('/Bearer/', $t->requests[0]['headers']);
$t = new FakeTransport([202, '{"id":"Pq8x-1","state":"Pending"}']);
$smsgateId = (new WebhookSms('http://127.0.0.1:8080/message', 'gw-user:gw pass', 'CloudHub', $t, 5, 'smsgate'))->send('+31612345678', 'hi');
$checks['webhook, smsgate: textMessage and phoneNumbers'] = json_decode($t->requests[0]['body'], true) === ['textMessage' => ['text' => 'hi'], 'phoneNumbers' => ['+31612345678']];
$checks['webhook, smsgate: basic authentication with the app\'s username and password'] =
    in_array('Authorization: Basic '.base64_encode('gw-user:gw pass'), $t->requests[0]['headers'], true);
$checks['webhook, smsgate: the id it answers with is kept'] = $smsgateId === 'Pq8x-1';
$checks['webhook, smsgate: a refused number is a refusal'] =
    (static function (): ?string {
        try { (new WebhookSms('http://127.0.0.1:8080/message', 'u:p', '', new FakeTransport([400, '{"message":"invalid phone"}']), 5, 'smsgate'))->send('+31612345678', 'x'); return null; }
        catch (SmsException $e) { return $e->kind; }
    })() === SmsException::REJECTED;
$hookConfig = static fn(string $url, string $format, string $token): array =>
    ['sms_driver' => 'webhook', 'sms_webhook_url' => $url, 'sms_webhook_format' => $format, 'sms_webhook_token' => $token];
$checks['a gateway app\'s format is refused without the credentials the app shows'] =
    Sms::fromConfig($hookConfig('http://127.0.0.1:8082/', 'traccar', ''), $scratch) === null
    && Sms::fromConfig($hookConfig('http://127.0.0.1:8080/message', 'smsgate', 'no-colon'), $scratch) === null
    && Sms::fromConfig($hookConfig('http://127.0.0.1:8080/message', 'smsgate', ':password-only'), $scratch) === null
    && Sms::fromConfig($hookConfig('http://127.0.0.1:8080/message', 'pigeon', 'x'), $scratch) === null
    && str_contains((string)Sms::problem($hookConfig('http://127.0.0.1:8082/', 'traccar', '')), 'SMS_WEBHOOK_TOKEN');
$checks['and works with them, as does a format left unset'] =
    Sms::fromConfig($hookConfig('http://127.0.0.1:8082/', 'traccar', 'key'), $scratch) instanceof WebhookSms
    && Sms::fromConfig($hookConfig('http://192.168.1.20:8080/message', 'SMSGate', 'u:p'), $scratch) instanceof WebhookSms
    && Sms::fromConfig($hookConfig('https://sms.example.com/x', '', ''), $scratch) instanceof WebhookSms;

$outbox = $scratch.'/logs/sms-outbox.log';
(new LogSms($outbox))->send('+31612345678', '123456 is your code');
$checks['the development outbox gets the message, with the number masked'] = str_contains((string)@file_get_contents($outbox), 'to ••78: 123456 is your code')
    && !str_contains((string)@file_get_contents($outbox), '612345678');

$checks['no driver: no gateway, and nothing to complain about'] = Sms::fromConfig([], $scratch) === null && Sms::problem([]) === null;
$checks['the development outbox is refused outside development'] = Sms::fromConfig(['sms_driver' => 'log', 'app_env' => 'production'], $scratch) === null
    && Sms::fromConfig(['sms_driver' => 'log', 'app_env' => 'development'], $scratch) instanceof LogSms;
$checks['Twilio needs its SID, token and a sender'] = Sms::fromConfig(['sms_driver' => 'twilio'], $scratch) === null
    && Sms::fromConfig(['sms_driver' => 'twilio', 'twilio_account_sid' => 'AC'.str_repeat('a', 32), 'twilio_auth_token' => 't'], $scratch) === null
    && Sms::fromConfig(['sms_driver' => 'twilio', 'twilio_account_sid' => 'AC'.str_repeat('a', 32), 'twilio_auth_token' => 't', 'sms_from' => '+1'], $scratch) instanceof TwilioSms;
$checks['a webhook in clear text to the internet is refused'] = Sms::fromConfig(['sms_driver' => 'webhook', 'sms_webhook_url' => 'http://sms.example.com/x'], $scratch) === null;
$checks['but allowed to this machine or the LAN, and over https anywhere'] =
    Sms::fromConfig(['sms_driver' => 'webhook', 'sms_webhook_url' => 'http://127.0.0.1:8080/m'], $scratch) instanceof WebhookSms
    && Sms::fromConfig(['sms_driver' => 'webhook', 'sms_webhook_url' => 'http://192.168.1.20:8080/m'], $scratch) instanceof WebhookSms
    && Sms::fromConfig(['sms_driver' => 'webhook', 'sms_webhook_url' => 'https://sms.example.com/x'], $scratch) instanceof WebhookSms;
$checks['an unknown driver is no gateway'] = Sms::fromConfig(['sms_driver' => 'pigeon'], $scratch) === null;

// --- the repository's compare-and-set ------------------------------------------------

$first = $repo->createChallenge(1, 'login');
$second = $repo->createChallenge(1, 'login');
$checks['asking again replaces the challenge'] = $repo->challenge($first) === null && $repo->challenge($second) !== null;
$checks['ids are 32 hex characters; anything else names nothing'] = preg_match('/^[0-9a-f]{32}$/', $second) === 1
    && $repo->challenge("' OR 1=1 --") === null;
$checks['a challenge with no code cannot be spent'] = $repo->spendAttempt($second, 5) === null;
$checks['a code is armed once per cooldown'] = $repo->arm($second, str_repeat('a', 64), 300, 60) && !$repo->arm($second, str_repeat('b', 64), 300, 60);
$now += 61;
$checks['and again once it has passed'] = $repo->arm($second, str_repeat('c', 64), 300, 60);
$spent = [];
for ($i = 0; $i < 7; $i++) $spent[] = $repo->spendAttempt($second, 5) !== null;
$checks['attempts stop at the limit, however many ask'] = $spent === [true, true, true, true, true, false, false];
$third = $repo->createChallenge(1, 'confirm');
$repo->arm($third, str_repeat('d', 64), 300, 60);
$now += 301;
$checks['an expired code cannot be spent'] = $repo->spendAttempt($third, 5) === null;
$fourth = $repo->createChallenge(1, 'phone');
$checks['a challenge is used once: the second of two racing callers loses'] = $repo->consume($fourth) && !$repo->consume($fourth)
    && $repo->challenge($fourth) === null;
$repo->forgetChallenges(1);
$repo->replaceRecoveryCodes(1, ['h1', 'h2']);
$checks['a recovery code is spent once'] = $repo->useRecoveryCode(1, 'h1') && !$repo->useRecoveryCode(1, 'h1') && $repo->recoveryCodesLeft(1) === 1;
$checks["and only by its own account"] = !$repo->useRecoveryCode(2, 'h2') && $repo->recoveryCodesLeft(1) === 1;
$repo->replaceRecoveryCodes(1, ['h3']);
$checks['replacing them retires the old ones'] = !$repo->useRecoveryCode(1, 'h2') && $repo->recoveryCodesLeft(1) === 1;
$checks['every account starts with it off'] = $repo->state(1) === ['enabled' => false, 'phone' => null, 'enabledAt' => null]
    && !$repo->requiredFor(2) && $repo->state(999) === null;
$checks['turning it on reports that it did, once'] = $repo->enable(1, '+31612345678') && !$repo->enable(1, '+31687654321')
    && $repo->state(1)['phone'] === '+31687654321';
$repo->disable(1);
$checks['turning it off takes the number, codes and challenges with it'] = $repo->state(1)['enabled'] === false && $repo->state(1)['phone'] === null
    && $repo->recoveryCodesLeft(1) === 0;

$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bare->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, is_active INTEGER, role TEXT)");
$bare->exec("INSERT INTO users VALUES (1, 'legacy', '', 1, 'viewer')");
$legacy = new TwoFactorRepository($bare);
$checks['a database not yet migrated reads as off, for everyone'] = $legacy->state(1) === ['enabled' => false, 'phone' => null, 'enabledAt' => null]
    && !$legacy->schemaReady() && $legacy->enabledUserIds() === [];
$checks['and so does the session check'] = (new CloudHub\Repositories\UserRepository($bare))->status(1)['twoFactor'] === false;
$down = new class('sqlite::memory:') extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
    }
};
$checks['only a missing schema reads as off: any other database error propagates'] = (function () use ($down): bool {
    try { (new TwoFactorRepository($down))->state(1); return false; } catch (PDOException) { return true; }
})() && !TwoFactorRepository::missingSchema(new PDOException('SQLSTATE[HY000] [2002] Connection refused'));

// --- the throttle --------------------------------------------------------------------

$resetThrottles();
$slots = [];
for ($i = 0; $i < 4; $i++) $slots[] = $limiter->claim('sms_user', '1', 3, 3600);
$checks['three slots an hour means the fourth is refused'] = $slots[0] !== null && $slots[1] !== null && $slots[2] !== null && $slots[3] === null;
$checks['and says how long until one frees'] = $limiter->retryAfter('sms_user', '1', 3, 3600) === 3600;
$checks['a refused claim does not hold a slot'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn() === 3;
$limiter->release((int)$slots[0]);
$checks['a released slot is free again'] = $limiter->claim('sms_user', '1', 3, 3600) !== null;
$now += 3601;
$checks['and all of them once the hour is up'] = $limiter->retryAfter('sms_user', '1', 3, 3600) === 0 && $limiter->claim('sms_user', '1', 3, 3600) !== null;
$checks['keys are HMACs, never the number or the account'] = !str_contains(implode(',', $db->query('SELECT attempt_key FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN)), '612345678');
$resetThrottles();

// --- signing in --------------------------------------------------------------------

$signOut = static function (): void { $_SESSION = []; };
$signOut();
$plainLogin = Auth::login($db, 'bob', 'bob-pass-12345');
$checks['an account without it signs in with the password, as before'] = $plainLogin && Auth::user()['username'] === 'bob' && Auth::pendingSecondFactor() === null;
$checks['and its session is not marked as having passed a second factor'] = !isset($_SESSION['two_factor_verified_at']);

$repo->enable(1, '+31612345678');
$signOut();
$_SESSION['csrf'] = 'old-token';
$wrong = Auth::login($db, 'alice', 'not-her-password');
$checks['a wrong password for a two-step account is refused, with nothing pending'] = !$wrong && Auth::pendingSecondFactor() === null && Auth::user() === null;
$beforeId = session_id();
$waiting = Auth::login($db, 'alice', 'alice-pass-1234');
$pending = Auth::pendingSecondFactor();
$checks['the right password alone does not sign in'] = $waiting === false && Auth::user() === null && !isset($_SESSION['user_id']);
$checks['it leaves the session waiting for this account\'s code'] = ($pending['user'] ?? null) === 1 && ($pending['username'] ?? null) === 'alice';
$checks['with a new session id and a new CSRF token'] = session_id() !== $beforeId && ($_SESSION['csrf'] ?? '') !== 'old-token';
$info = $tf->beginLogin();
$checks['nothing is texted until the client asks'] = $sms->sent === [] && $info['codeSent'] === false && $info['phoneEnding'] === '78';
$sendInfo = $tf->sendLoginCode();
$code = $sms->lastCode();
$checks['asking texts a code to the account\'s number'] = count($sms->sent) === 1 && $sms->sent[0]['to'] === '+31612345678' && $code !== '' && $sendInfo['sent'] === true;
$checks['the code is stored only as its hash'] = !str_contains(json_encode($db->query('SELECT * FROM two_factor_challenges')->fetchAll()), $code);
$checks['asking again at once is refused, with the wait'] = refusal(fn() => $tf->sendLoginCode()) === 'TWO_FACTOR_RESEND_COOLDOWN'
    && count($sms->sent) === 1;
$checks['a malformed code is refused without spending an attempt'] = refusal(fn() => $tf->verifyLogin('12a456', null)) === 'VALIDATION_FAILED'
    && $repo->challenge((string)Auth::pendingSecondFactor()['challenge'])['attempts'] === 0;
$wrongCode = $code === '000000' ? '000001' : '000000';
try { $tf->verifyLogin($wrongCode, null); $wrongError = null; } catch (TwoFactorError $e) { $wrongError = $e; }
$checks['a wrong code is refused and says how many tries are left'] = $wrongError?->errorCode === 'TWO_FACTOR_CODE_INVALID' && ($wrongError->details['attemptsLeft'] ?? null) === 4;
$checks['and the session is still not signed in'] = Auth::user() === null && Auth::pendingSecondFactor() !== null;
$pendingId = session_id();
$signedIn = $tf->verifyLogin($code, null);
$checks['the right code signs the session in'] = ($signedIn['user']['username'] ?? '') === 'alice' && Auth::user()['id'] === 1 && Auth::pendingSecondFactor() === null;
$checks['with yet another session id, marked as having passed the second factor'] = session_id() !== $pendingId && isset($_SESSION['two_factor_verified_at']);
$checks['the code is gone once used'] = (int)$db->query("SELECT COUNT(*) FROM two_factor_challenges WHERE user_id = 1 AND purpose = 'login'")->fetchColumn() === 0;
$checks['a right code takes no slot from the failure limit; the wrong one did'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'verify_user'")->fetchColumn() === 1;
$checks['a used code cannot be replayed'] = refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_EXPIRED';

// Expiry, exhaustion, supersession
$signOut(); $resetThrottles(); $sms->sent = [];
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $tf->sendLoginCode(); $code = $sms->lastCode();
$now += 301;
$checks['a code past its five minutes is refused'] = refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_CODE_EXPIRED' && Auth::user() === null;
$tf->sendLoginCode(); $fresh = $sms->lastCode();
$checks['a new code replaces it, and the old one stays dead'] = $fresh !== $code ? refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_CODE_INVALID' : true;
$now += 61;
$tf->sendLoginCode(); $newest = $sms->lastCode();
$checks['a superseded code no longer works'] = $newest === $fresh || refusal(fn() => $tf->verifyLogin($fresh, null)) === 'TWO_FACTOR_CODE_INVALID';
$guesses = [];
for ($i = 0; $i < 6; $i++) $guesses[] = refusal(fn() => $tf->verifyLogin($newest === '999999' ? '999998' : '999999', null));
$checks['five wrong guesses kill the code'] = $guesses[count($guesses) - 1] === 'TWO_FACTOR_CODE_EXHAUSTED';
$checks['after which even the right one is refused'] = refusal(fn() => $tf->verifyLogin($newest, null)) === 'TWO_FACTOR_CODE_EXHAUSTED' && Auth::user() === null;

// Another sign-in elsewhere replaces this one's code
$signOut(); $resetThrottles(); $sms->sent = []; $now += 61;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $tf->sendLoginCode(); $codeA = $sms->lastCode();
$sessionA = $_SESSION;
$_SESSION = [];
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$sessionB = $_SESSION;
$_SESSION = $sessionA;
$checks['a later sign-in elsewhere retires the earlier code'] = refusal(fn() => $tf->verifyLogin($codeA, null)) === 'TWO_FACTOR_CODE_EXPIRED' && Auth::user() === null;
$_SESSION = $sessionB;
$checks['and the session holding no code cannot use one from another'] = refusal(fn() => $tf->verifyLogin($codeA, null)) === 'TWO_FACTOR_CODE_NOT_SENT' && Auth::user() === null;

// The failure limit across codes
$signOut(); $resetThrottles(); $now += 61;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$locked = null;
for ($round = 0; $round < 4 && $locked === null; $round++) {
    $now += 61;
    $tf->sendLoginCode(); $c = $sms->lastCode();
    for ($i = 0; $i < 5; $i++) {
        $r = refusal(fn() => $tf->verifyLogin($c === '123123' ? '321321' : '123123', null));
        if ($r === 'TWO_FACTOR_LOCKED') { $locked = $round * 5 + $i; break; }
    }
}
$checks['ten wrong codes in an hour lock the account\'s verification, across new codes'] = $locked === 10;
$checks['and the lock holds for the right code too'] = refusal(fn() => $tf->verifyLogin($c, null)) === 'TWO_FACTOR_LOCKED';
$checks['refusals while locked do not lengthen it'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'verify_user'")->fetchColumn() === 10;

// The hourly SMS limit
$signOut(); $resetThrottles(); $sms->sent = [];
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$sends = [];
for ($i = 0; $i < 6; $i++) { $now += 61; $sends[] = refusal(fn() => $tf->sendLoginCode()); }
$checks['five texts an hour per account; the sixth is refused'] = $sends === [null, null, null, null, null, 'TWO_FACTOR_SMS_LIMIT'] && count($sms->sent) === 5;

// Recovery codes at sign-in
$signOut(); $resetThrottles(); $sms->sent = [];
$plainCodes = array_map(static fn(): string => TwoFactor::generateRecoveryCode(), range(1, 3));
$repo->replaceRecoveryCodes(1, array_map(static fn(string $c): string => TwoFactor::recoveryHash(1, $c), $plainCodes));
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$checks['a wrong recovery code is refused'] = refusal(fn() => $tf->verifyLogin(null, TwoFactor::generateRecoveryCode())) === 'TWO_FACTOR_RECOVERY_INVALID';
$viaRecovery = $tf->verifyLogin(null, strtoupper(TwoFactor::formatRecoveryCode($plainCodes[0])));
$checks['a recovery code signs in without any text'] = Auth::user()['id'] === 1 && $sms->sent === [] && $viaRecovery['method'] === 'recovery';
$checks['and says how many are left'] = $viaRecovery['recoveryCodesLeft'] === 2;
$signOut();
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$checks['a recovery code works once'] = refusal(fn() => $tf->verifyLogin(null, $plainCodes[0])) === 'TWO_FACTOR_RECOVERY_INVALID' && Auth::user() === null;

// A sign-in that waits too long, gives up, or loses its account
$_SESSION['two_factor_login']['at'] = time() - Auth::SECOND_FACTOR_WINDOW - 1;
$checks['a password proven too long ago has to be entered again'] = Auth::pendingSecondFactor() === null
    && refusal(fn() => $tf->sendLoginCode()) === 'TWO_FACTOR_EXPIRED' && Auth::finishSecondFactor($db) === null;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $resetThrottles(); $now += 61; $tf->sendLoginCode(); $code = $sms->lastCode();
$tf->cancelLogin();
$checks['cancelling forgets the sign-in and its code'] = Auth::pendingSecondFactor() === null && refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_EXPIRED'
    && (int)$db->query("SELECT COUNT(*) FROM two_factor_challenges WHERE purpose = 'login'")->fetchColumn() === 0;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $now += 61; $tf->sendLoginCode(); $code = $sms->lastCode();
$db->exec('UPDATE users SET is_active = 0 WHERE id = 1');
$checks['an account disabled while it waits is not signed in, even with the right code'] = refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_EXPIRED' && Auth::user() === null;
$db->exec('UPDATE users SET is_active = 1 WHERE id = 1');
$checks['finishing with nothing pending signs nobody in'] = Auth::finishSecondFactor($db) === null && Auth::user() === null;

// The gateway is down, refuses, or there is none: never a way round the code
$signOut(); $resetThrottles(); $now += 61;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$sms->fail = new SmsException(SmsException::UNAVAILABLE, 'fake: HTTP 503');
$checks['an outage is reported, and the session stays signed out'] = refusal(fn() => $tf->sendLoginCode()) === 'SMS_UNAVAILABLE' && Auth::user() === null;
$checks['an outage keeps its slot: the message may still have gone'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'sms_user'")->fetchColumn() === 1;
$now += 61;
$sms->fail = new SmsException(SmsException::REJECTED, 'fake: HTTP 400');
$checks['a refused number is reported as such'] = refusal(fn() => $tf->sendLoginCode()) === 'SMS_REJECTED';
$checks['and gives its slots back: nothing was sent'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'sms_user'")->fetchColumn() === 1;
$sms->fail = null;
$none = new TwoFactor($db, $repo, $limiter, null, $config, $clock);
$checks['with no gateway at all the code cannot be sent'] = refusal(fn() => $none->sendLoginCode()) === 'SMS_NOT_CONFIGURED' && Auth::user() === null;
$checks['and the client is told to use a recovery code'] = $none->loginStatus()['smsAvailable'] === false;
$checks['which still works'] = $none->verifyLogin(null, $plainCodes[1])['method'] === 'recovery' && Auth::user()['id'] === 1;

// --- changing it -------------------------------------------------------------------

$repo->disable(1); $repo->disable(2);
$signOut(); $resetThrottles(); $sms->sent = []; $now += 3601;
Auth::login($db, 'bob', 'bob-pass-12345');
$checks['changes need the current password'] = refusal(fn() => $tf->startAction(2, 'bob', 'phone', 'wrong-password', '+31611111111')) === 'FORBIDDEN';
$checks['which counts as a failed sign-in'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'user'")->fetchColumn() === 1;
$checks['a local number is refused when turning it on'] = refusal(fn() => $tf->startAction(2, 'bob', 'phone', 'bob-pass-12345', '0611111111')) === 'VALIDATION_FAILED';
$checks['turning off what is not on is refused'] = refusal(fn() => $tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null)) === 'CONFLICT';
$checks['an unknown change is refused'] = refusal(fn() => $tf->startAction(2, 'bob', 'everything', 'bob-pass-12345', null)) === 'VALIDATION_FAILED';
$start = $tf->startAction(2, 'bob', 'phone', 'bob-pass-12345', '+31 6 1111 1111');
$checks['turning it on texts the new number, and only that'] = $start['stage'] === 'new' && $start['sent'] === true
    && count($sms->sent) === 1 && $sms->sent[0]['to'] === '+31611111111' && $start['recoveryAllowed'] === false;
$checks['a recovery code cannot stand in for proving a new number'] = refusal(fn() => $tf->confirmAction(2, null, TwoFactor::generateRecoveryCode())) === 'TWO_FACTOR_RECOVERY_NOT_ALLOWED';
$done = $tf->confirmAction(2, $sms->lastCode(), null);
$checks['the code turns it on, with ten recovery codes shown once'] = $done['done'] === true && $done['enabled'] === true
    && count($done['recoveryCodes'] ?? []) === 10 && $repo->recoveryCodesLeft(2) === 10;
$checks['this session counts as having passed the second factor'] = isset($_SESSION['two_factor_verified_at']);
$checks['the change is over'] = refusal(fn() => $tf->confirmAction(2, $sms->lastCode(), null)) === 'TWO_FACTOR_NO_PENDING_CHANGE';
$bobCodes = $done['recoveryCodes'];

// Moving to a new number: just verified, so only the new number's code
$sms->sent = []; $now += 61;
$move = $tf->startAction(2, 'bob', 'phone', 'bob-pass-12345', '+31622222222');
$checks['a session that just proved its phone moves number with the new one\'s code only'] = $move['stage'] === 'new' && $sms->sent[0]['to'] === '+31622222222';
$moved = $tf->confirmAction(2, $sms->lastCode(), null);
$checks['moving keeps the recovery codes'] = $moved['done'] && $moved['recoveryCodes'] === null && $repo->recoveryCodesLeft(2) === 10
    && $repo->state(2)['phone'] === '+31622222222';
$checks['and texts the old number that it happened'] = str_contains(end($sms->sent)['message'], 'was just changed') && end($sms->sent)['to'] === '+31611111111';
$checks['moving to the number already in use is refused'] = refusal(fn() => $tf->startAction(2, 'bob', 'phone', 'bob-pass-12345', '+31622222222')) === 'VALIDATION_FAILED';

// Later on, the current phone has to be proven first
$now += TwoFactor::RECENT_SECONDS + 61; $sms->sent = [];
$later = $tf->startAction(2, 'bob', 'phone', 'bob-pass-12345', '+31633333333');
$checks['without a recent proof, the current phone is asked for first'] = $later['stage'] === 'current' && $sms->sent[0]['to'] === '+31622222222'
    && str_contains($sms->sent[0]['message'], 'to change your two-step verification number') && $later['recoveryAllowed'] === true;
$next = $tf->confirmAction(2, $sms->lastCode(), null);
$checks['then the new number\'s code'] = $next['done'] === false && $next['stage'] === 'new' && end($sms->sent)['to'] === '+31633333333';
$tf->confirmAction(2, $sms->lastCode(), null);
$checks['and only then does the number change'] = $repo->state(2)['phone'] === '+31633333333';

// Turning it off: always the current phone, or a recovery code
$now += 61; $sms->sent = [];
$off = $tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null, 'recovery');
$checks['a recovery code can answer for a lost phone, and nothing is texted'] = $off['stage'] === 'current' && $off['sent'] === false && $sms->sent === [];
$checks['a wrong recovery code does not turn it off'] = refusal(fn() => $tf->confirmAction(2, null, TwoFactor::generateRecoveryCode())) === 'TWO_FACTOR_RECOVERY_INVALID'
    && $repo->state(2)['enabled'];
$offDone = $tf->confirmAction(2, null, $bobCodes[0]);
$checks['a right one does, and takes the number and codes with it'] = $offDone['enabled'] === false && $repo->state(2)['enabled'] === false
    && $repo->recoveryCodesLeft(2) === 0 && $repo->state(2)['phone'] === null;
$checks['the phone is told'] = str_contains(end($sms->sent)['message'], 'was turned off');

// New recovery codes need the current phone too
$resetThrottles();
$tf->startAction(2, 'bob', 'phone', 'bob-pass-12345', '+31644444444');
$tf->confirmAction(2, $sms->lastCode(), null);
$now += 61; $sms->sent = [];
$regen = $tf->startAction(2, 'bob', 'recovery', 'bob-pass-12345', null);
$checks['new recovery codes need a code from the phone'] = $regen['stage'] === 'current' && $regen['sent'] === true;
$fresh = $tf->confirmAction(2, $sms->lastCode(), null);
$checks['and replace all the old ones'] = count($fresh['recoveryCodes']) === 10 && $repo->recoveryCodesLeft(2) === 10;

// A change waiting too long, or belonging to someone else
$now += 61;
$tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null);
$now += TwoFactor::ACTION_WINDOW + 1;
$checks['a change left waiting too long has to be started again'] = refusal(fn() => $tf->confirmAction(2, $sms->lastCode(), null)) === 'TWO_FACTOR_NO_PENDING_CHANGE';
$now += 61;
$tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null);
$cancelled = $sms->lastCode();
$tf->cancelAction(2);
$checks['a cancelled change takes its code with it'] = refusal(fn() => $tf->confirmAction(2, $cancelled, null)) === 'TWO_FACTOR_NO_PENDING_CHANGE'
    && $repo->state(2)['enabled'] && (int)$db->query("SELECT COUNT(*) FROM two_factor_challenges WHERE user_id = 2")->fetchColumn() === 0;
$now += 61;
$tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null);
$checks["one account's change cannot be confirmed as another's"] = refusal(fn() => $tf->confirmAction(1, $sms->lastCode(), null)) === 'TWO_FACTOR_NO_PENDING_CHANGE'
    && $repo->state(2)['enabled'];

// Begun while it was off, so no current phone was asked for; turned on in
// another session meanwhile; this one must not then move it without that proof.
$repo->disable(4);
$signOut(); $resetThrottles(); $now += 3601;
Auth::login($db, 'carol', 'carol-pass-1234');
$begun = $tf->startAction(4, 'carol', 'phone', 'carol-pass-1234', '+31655555555');
$racing = $sms->lastCode();
$repo->enable(4, '+31666666666');
unset($_SESSION['two_factor_verified_at']);
$checks['a change begun while it was off cannot finish once it was turned on elsewhere'] = $begun['stage'] === 'new'
    && refusal(fn() => $tf->confirmAction(4, $racing, null)) === 'TWO_FACTOR_NO_PENDING_CHANGE'
    && $repo->state(4)['phone'] === '+31666666666';
$signOut();
Auth::login($db, 'bob', 'bob-pass-12345');

// --- an administrator's reset ------------------------------------------------------

$sms->sent = [];
$checks["an administrator's reset needs their own password"] = refusal(fn() => $tf->adminReset(3, 'admin', 'wrong', 2)) === 'FORBIDDEN' && $repo->state(2)['enabled'];
$tf->adminReset(3, 'admin', 'admin-pass-1234', 2);
$checks['a reset turns it off and texts the owner'] = !$repo->state(2)['enabled'] && str_contains(end($sms->sent)['message'], 'An administrator turned off');
$checks['resetting what is off is refused'] = refusal(fn() => $tf->adminReset(3, 'admin', 'admin-pass-1234', 2)) === 'CONFLICT';
$signOut();
$checks['after a reset the password alone signs in again'] = Auth::login($db, 'bob', 'bob-pass-12345') && Auth::user()['id'] === 2;

// --- what is written down ------------------------------------------------------------

$codesSeen = [];
foreach ($sms->sent as $s) if (preg_match('/^(\d{6}) /', $s['message'], $m)) $codesSeen[] = $m[1];
$trail = json_encode($db->query('SELECT event_type, outcome, username, context_json FROM security_events')->fetchAll());
$checks['the audit trail records the events'] = str_contains($trail, 'two_factor.enable') && str_contains($trail, 'two_factor.disable')
    && str_contains($trail, 'two_factor.sms') && str_contains($trail, 'auth.two_factor');
$numbers = ['+31612345678', '+31687654321', '+31611111111', '+31622222222', '+31633333333', '+31644444444'];
$mentions = static fn(string $text): array => array_filter($numbers, static fn(string $n): bool => str_contains($text, $n) || str_contains($text, substr($n, 3)));
$checks['without a single code or phone number'] = $mentions((string)$trail) === []
    && array_filter($codesSeen, static fn(string $c): bool => str_contains((string)$trail, '"'.$c.'"')) === [];
$log = (string)@file_get_contents($errorLog);
$checks['the error log names the gateway\'s failures'] = str_contains($log, 'two-step code not sent: fake: HTTP 503');
$checks['without a code or a number'] = $mentions($log) === []
    && array_filter($codesSeen, static fn(string $c): bool => str_contains($log, $c)) === [];

// --- the routes and the rest ---------------------------------------------------------

$index = (string)file_get_contents($root.'/public/index.php');
$migrate = (string)file_get_contents($root.'/database/migrate.php');
$checks['the sign-in step checks CSRF itself, being outside the guard'] =
    (bool)preg_match("#str_starts_with\(\\\$path, '/api/auth/two-factor/'\) && \\\$method === 'POST'\) two_factor_try\(function \(\) use \(\\\$path\) \{\s*Auth::verifyCsrf\(\);#", $index);
$checks['being signed in still means having a user_id, which a waiting session lacks'] =
    str_contains((string)file_get_contents($root.'/src/Services/Auth.php'), "public static function user(): ?array {return isset(\$_SESSION['user_id'])?");
$checks['the login answer for a two-step account is a 401 the old clients can read'] = str_contains($index, "'code' => 'TWO_FACTOR_REQUIRED'")
    && str_contains($index, "'csrfToken' => \$_SESSION['csrf']], 401);");
$checks['account routes run behind the guard, which checks CSRF'] =
    strpos($index, "if (str_starts_with(\$path, '/api/users/me/two-factor/') && \$method === 'POST')") > strpos($index, "if (\$isProtectedApi && \$method !== 'OPTIONS') {");
$checks['an administrator cannot reset their own from the Users screen'] =
    str_contains($index, "Turn off your own two-step verification from Security, which asks for your phone");
$checks['the sessions of an account that turns it on must have passed it'] =
    str_contains((string)file_get_contents($root.'/src/Services/Auth.php'), "if(!empty(\$status['twoFactor'])&&empty(\$_SESSION['two_factor_verified_at'])){");
$checks['migrate.php adds everything, only adding'] = str_contains($migrate, "addColumn(\$pdo, 'users', 'two_factor_phone'")
    && str_contains($migrate, "addColumn(\$pdo, 'users', 'two_factor_enabled_at'")
    && str_contains($migrate, 'CREATE TABLE IF NOT EXISTS two_factor_challenges')
    && str_contains($migrate, 'CREATE TABLE IF NOT EXISTS two_factor_recovery_codes')
    && str_contains($migrate, "'sms_phone'") && !preg_match('/DROP (?:TABLE|COLUMN)[^;]*two_factor/i', $migrate);
$tool = (string)file_get_contents($root.'/tools/reset-two-factor.php');
$checks['the operator\'s reset runs only from the command line'] = str_contains($tool, "if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }");
$checks['and turns it off the same way the web does, on the record'] = str_contains($tool, '$twoFactor->disable($account[\'id\']);')
    && str_contains($tool, "AuditLog::write(\$db, 'two_factor.admin_reset'");
$env = (string)file_get_contents($root.'/.env.example');
$checks['.env.example documents every setting, with no secret in it'] =
    (bool)preg_match('/^SMS_DRIVER=$/m', $env) && (bool)preg_match('/^TWILIO_AUTH_TOKEN=$/m', $env) && (bool)preg_match('/^TWO_FACTOR_SECRET=$/m', $env)
    && (bool)preg_match('/^SMS_WEBHOOK_TOKEN=$/m', $env) && str_contains($env, 'TWO_FACTOR_CODE_TTL_SECONDS=300');

} catch (Throwable $e) {
    $checks['the run reached its end, rather than stopping at '.get_class($e).': '.$e->getMessage()] = false;
}

// --- report --------------------------------------------------------------------------

session_write_close();
array_map('unlink', glob($scratch.'/sessions/*') ?: []);
@rmdir($scratch.'/sessions');
@unlink($outbox); @rmdir($scratch.'/logs');
@unlink($errorLog); @rmdir($scratch);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
