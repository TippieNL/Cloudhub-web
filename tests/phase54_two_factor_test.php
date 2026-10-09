<?php
declare(strict_types=1);

/**
 * Two-step verification by email, exercised in one process.
 *
 * Runs against SQLite with the tables taken from database/schema.sql, so a
 * column the code writes and the schema lacks fails here, with a clock the
 * test moves and a mailer that records instead of sending. The SMTP client is
 * driven through scripted server conversations. Auth's own sign-in runs for
 * real against a PHP session.
 *
 * What a real deployment adds -- MariaDB, HTTP, cookies, CSRF, an SMTP server
 * over the network, sessions ended on another device -- is covered by
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
use CloudHub\Services\EmailAddress;
use CloudHub\Services\LoginRateLimiter;
use CloudHub\Services\Mail\Mail;
use CloudHub\Services\Mail\MailException;
use CloudHub\Services\Mail\MailSender;
use CloudHub\Services\Mail\SmtpConnection;
use CloudHub\Services\Mail\SmtpMailer;
use CloudHub\Services\TwoFactor;
use CloudHub\Services\TwoFactorError;

$root = dirname(__DIR__);
$scratch = sys_get_temp_dir().'/cloudhub-p54-'.bin2hex(random_bytes(5));
mkdir($scratch.'/sessions', 0775, true);
// Mail failures and audit problems are logged, not thrown: keep them out of
// the output, and read them back to prove no code or address is in them.
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

final class FakeMailer implements MailSender
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $sent = [];
    public ?MailException $fail = null;
    public function send(string $to, string $subject, string $body): string
    {
        if ($this->fail !== null) throw $this->fail;
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        return 'fake-'.count($this->sent).'@example.com';
    }
    public function name(): string { return 'fake'; }
    public function lastCode(): string
    {
        $last = end($this->sent);
        return $last !== false && preg_match('/^    (\d{6})$/m', $last['body'], $m) ? $m[1] : '';
    }
}

/** An SMTP server that answers from a script, and remembers what it was told. */
final class ScriptedSmtp implements SmtpConnection
{
    /** @var list<string> */
    public array $log = [];
    public string $data = '';
    public bool $closed = false;
    /** @param list<string> $replies */
    public function __construct(private array $replies) {}
    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void
    {
        $this->log[] = "[open $host:$port ".($implicitTls ? 'tls' : 'plain')." {$timeoutSeconds}s]";
    }
    public function enableTls(): void { $this->log[] = '[tls]'; }
    public function writeLine(string $line): void { $this->log[] = $line; }
    public function writeRaw(string $data): void { $this->log[] = '[data]'; $this->data .= $data; }
    public function readLine(): string
    {
        if ($this->replies === []) throw new MailException(MailException::UNAVAILABLE, 'smtp: no answer');
        return array_shift($this->replies);
    }
    public function close(): void { $this->closed = true; }
    /** What was sent, without the connection's own bookkeeping. */
    public function commands(): array
    {
        return array_values(array_filter($this->log, static fn(string $l): bool => !str_starts_with($l, '[open')));
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
          [3, 'admin', 'admin-pass-1234', 1, 'admin'], [4, 'carol', 'carol-pass-1234', 1, 'editor'],
          [5, 'dave', 'dave-pass-12345', 1, 'viewer']] as $u) {
    $addUser->execute([$u[0], $u[1], $hash($u[2]), $u[3], $u[4]]);
}

$now = time();
$clock = static function () use (&$now): int { return $now; };
$config = [
    'app_url' => 'https://cloud.example.com', 'app_env' => 'production', 'mail_from_name' => 'CloudHub',
    'two_factor_secret' => str_repeat('k', 64), 'rate_limit_secret' => 'r',
    'two_factor_code_ttl_seconds' => 300, 'two_factor_max_attempts' => 5, 'two_factor_resend_seconds' => 60,
    'two_factor_email_per_hour' => 5, 'two_factor_email_ip_per_hour' => 20,
    'two_factor_failures_per_hour' => 10, 'two_factor_ip_failures_per_hour' => 30,
    'login_rate_window_seconds' => 900, 'login_rate_user_attempts' => 5, 'login_rate_ip_attempts' => 20,
];
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$repo = new TwoFactorRepository($db, $clock);
$limiter = new LoginRateLimiter($db, $config, $clock);
$mail = new FakeMailer();
$tf = new TwoFactor($db, $repo, $limiter, $mail, $config, $clock);
$resetThrottles = static function () use ($db): void { $db->exec('DELETE FROM login_attempts'); };
// Every address used below, to prove none is ever written down in full.
$addresses = ['alice@example.com', 'bob@example.org', 'bob.new@example.org', 'bob.third@example.org', 'bob.fourth@example.org',
    'carol@example.net', 'carol.race@example.net', 'dave@example.com'];

// A scenario that throws must still fail by name: results are printed only at
// the end, so an uncaught exception would otherwise end the run with nothing
// said about which property broke.
try {

// --- email addresses -------------------------------------------------------------------

$checks['an address is kept, its domain lower-cased'] = EmailAddress::normalize('  Kevin.T@Example.COM ') === 'Kevin.T@example.com';
$checks['what is not an address is refused'] = EmailAddress::normalize('kevin') === null && EmailAddress::normalize('@example.com') === null
    && EmailAddress::normalize('kevin@') === null && EmailAddress::normalize('') === null && EmailAddress::normalize('a b@example.com') === null;
$checks['an address cannot carry a second SMTP line or header'] = EmailAddress::normalize("kevin@example.com\r\nRCPT TO:<x@example.net>") === null
    && EmailAddress::normalize("kevin@example.com\nBcc: x@example.net") === null && EmailAddress::normalize('<kevin@example.com>') === null
    && EmailAddress::normalize("kevin\0@example.com") === null;
$checks['nor be longer than SMTP allows'] = EmailAddress::normalize(str_repeat('a', 65).'@example.com') === null
    && EmailAddress::normalize('a@'.str_repeat('b', 250).'.com') === null;
$checks['a masked address shows its first letter and its domain only'] = EmailAddress::mask('kevin@example.com') === 'k•••@example.com';

// --- codes ---------------------------------------------------------------------------

$codes = array_map(static fn(): string => TwoFactor::generateCode(), range(1, 400));
$checks['codes are six digits, leading zeros kept'] = count(array_filter($codes, static fn(string $c): bool => preg_match('/^\d{6}$/', $c) === 1)) === 400;
$checks['and do not repeat in a run of 400'] = count(array_unique($codes)) >= 399;
$checks['codes come from the CSPRNG'] = str_contains((string)file_get_contents($root.'/src/Services/TwoFactor.php'), 'random_int(0, 10 ** self::CODE_LENGTH - 1)');
$checks['a stored code is an HMAC bound to its challenge'] = $tf->codeHash(str_repeat('a', 32), '123456') !== $tf->codeHash(str_repeat('b', 32), '123456')
    && $tf->codeHash(str_repeat('a', 32), '123456') === hash_hmac('sha256', 'cloudhub-two-factor|'.str_repeat('a', 32).'|123456', str_repeat('k', 64));
$other = new TwoFactor($db, $repo, $limiter, $mail, ['two_factor_secret' => 'another-key'] + $config, $clock);
$checks['under the server secret, which the database does not hold'] = $tf->codeHash(str_repeat('a', 32), '123456') !== $other->codeHash(str_repeat('a', 32), '123456');
$recovery = TwoFactor::generateRecoveryCode();
$checks['recovery codes are 16 unambiguous characters'] = preg_match('/^[abcdefghjkmnpqrstuvwxyz23456789]{16}$/', $recovery) === 1;
$checks['shown in groups of four'] = preg_match('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', TwoFactor::formatRecoveryCode($recovery)) === 1;
$checks['typed in any case, with or without dashes'] = TwoFactor::normalizeRecoveryCode(strtoupper(implode(' ', str_split($recovery, 4)))) === $recovery
    && TwoFactor::normalizeRecoveryCode('not-a-code') === null && TwoFactor::normalizeRecoveryCode(str_repeat('0', 16)) === null;
$checks['a recovery code is stored as a hash bound to its account'] = TwoFactor::recoveryHash(1, $recovery) !== TwoFactor::recoveryHash(2, $recovery)
    && strlen(TwoFactor::recoveryHash(1, $recovery)) === 64;
[$subject, $body] = $tf->message('login', '012345');
$checks['the email says what the code is for, and for how long'] = $subject === 'Your CloudHub sign-in code'
    && str_contains($body, "to finish signing in to CloudHub:\n\n    012345\n") && str_contains($body, 'It expires in 5 minutes');
$checks['the code is in the body only, never the subject a locked screen shows'] = !preg_match('/\d/', $subject)
    && !preg_match('/\d{6}/', $tf->message('new-email', '654321')[0]);
$checks['an account change names itself in the email'] = str_contains($tf->message('disable', '1')[1], 'to turn off two-step verification')
    && str_contains($tf->message('new-email', '1')[1], 'to confirm this address') && str_contains($tf->message('change', '1')[1], 'to another address');
$named = new TwoFactor($db, $repo, $limiter, $mail, ['mail_from_name' => "Tippie\r\nCloud"] + $config, $clock);
$checks['the sender name stands in for CloudHub, with no line breaks'] = $named->message('login', '1')[0] === 'Your TippieCloud sign-in code';

// --- the SMTP client -------------------------------------------------------------------

$smtp = static function (array $replies, string $encryption = 'tls', string $user = 'mailer@example.com', string $pass = 'app-pass-word'): array {
    $connection = new ScriptedSmtp($replies);
    $port = ['tls' => 587, 'ssl' => 465, 'none' => 25][$encryption];
    return [new SmtpMailer('smtp.example.com', $port, $encryption, $user, $pass, 'codes@example.com', 'CloudHub', $connection, 7), $connection];
};
$smtpKind = static function (SmtpMailer $mailer, string $to = 'kevin@example.org'): string {
    try { $mailer->send($to, 'Subject', 'Body'); return 'sent'; }
    catch (MailException $e) { return $e->kind.': '.$e->getMessage(); }
};
$tail = ['250 2.1.0 OK', '250 2.1.5 OK', '354 Go ahead', '250 2.0.0 Queued as 1234', '221 2.0.0 Bye'];
$starttls = array_merge(['220 smtp.example.com ESMTP', '250-smtp.example.com greets you', '250-SIZE 35882577', '250-STARTTLS', '250 8BITMIME',
    '220 2.0.0 Ready to start TLS', '250-smtp.example.com', '250-AUTH LOGIN PLAIN XOAUTH2', '250 8BITMIME', '235 2.7.0 Accepted'], $tail);

[$mailer, $c] = $smtp($starttls);
$id = $mailer->send('kevin@example.org', 'Your CloudHub sign-in code', "Your code to finish signing in:\n\n    123456\n\n.a line that starts with a dot\nLast line");
$checks['SMTP: connects to the configured host and port, within the timeout'] = $c->log[0] === '[open smtp.example.com:587 plain 7s]';
$checks['SMTP: STARTTLS before anything else, then EHLO again, then the credentials'] = $c->commands() === [
    'EHLO example.com', 'STARTTLS', '[tls]', 'EHLO example.com', 'AUTH PLAIN '.base64_encode("\0mailer@example.com\0app-pass-word"),
    'MAIL FROM:<codes@example.com>', 'RCPT TO:<kevin@example.org>', 'DATA', '[data]', 'QUIT'];
$checks['SMTP: an accepted message returns its Message-ID, and the connection is closed'] = preg_match('/^[0-9a-f]{32}@example\.com$/', $id) === 1 && $c->closed;
[$headers, $text] = explode("\r\n\r\n", $c->data, 2) + ['', ''];
$checks['SMTP: plain text, UTF-8, quoted-printable, marked as automatic'] = str_contains($headers, "\r\nContent-Type: text/plain; charset=UTF-8\r\n")
    && str_contains($headers, "\r\nContent-Transfer-Encoding: quoted-printable\r\n") && str_contains($headers, "\r\nAuto-Submitted: auto-generated");
$checks['SMTP: sender, recipient, subject and Message-ID headers'] = str_contains($headers, "\r\nFrom: \"CloudHub\" <codes@example.com>\r\n")
    && str_contains($headers, "\r\nTo: <kevin@example.org>\r\n") && str_contains($headers, "\r\nSubject: Your CloudHub sign-in code\r\n")
    && str_contains($headers, "\r\nMessage-ID: <$id>\r\n") && str_starts_with($headers, 'Date: ');
$checks['SMTP: the code is in the body, untouched, and not in a header'] = str_contains($text, "\r\n    123456\r\n") && !str_contains($headers, '123456');
$checks['SMTP: a line starting with a dot is doubled, and the message ends with a lone dot'] = str_contains($text, "\r\n..a line that starts with a dot\r\n")
    && str_ends_with($c->data, "\r\nLast line\r\n.\r\n");

[$mailer, $c] = $smtp(['220 smtp.example.com ESMTP', '250-smtp.example.com', '250-AUTH PLAIN LOGIN', '250 8BITMIME']);
$checks['SMTP: a server that does not offer STARTTLS gets nothing'] = str_starts_with($smtpKind($mailer), 'unavailable: smtp: smtp.example.com does not offer STARTTLS')
    && $c->commands() === ['EHLO example.com'] && $c->closed;
[$mailer, $c] = $smtp(array_merge(['220 x', '250-x', '250-STARTTLS', '250 OK', '220 Go', '250-x', '250 AUTH LOGIN',
    '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 OK'], $tail));
$checks['SMTP: AUTH LOGIN where it is all there is'] = $smtpKind($mailer) === 'sent'
    && array_slice($c->commands(), 4, 3) === ['AUTH LOGIN', base64_encode('mailer@example.com'), base64_encode('app-pass-word')];
[$mailer, $c] = $smtp(['220 x', '250-x', '250-STARTTLS', '250 OK', '220 Go', '250-x', '250 AUTH CRAM-MD5']);
$checks['SMTP: and no credentials at all when neither is offered'] = str_contains($smtpKind($mailer), 'neither AUTH PLAIN nor AUTH LOGIN')
    && !preg_grep('/^AUTH|^MAIL/', $c->commands());
[$mailer, $c] = $smtp(['220 x', '250-x', '250-STARTTLS', '250 OK', '220 Go', '250-x', '250 AUTH PLAIN', '535 5.7.8 Username and Password not accepted']);
$checks['SMTP: refused credentials are an outage, not the address\'s fault'] = $smtpKind($mailer) === 'unavailable: smtp: AUTH refused (535)';
[$mailer, $c] = $smtp(array_merge(['220 x', '250-x', '250 AUTH PLAIN', '235 OK'], $tail), 'ssl');
$checks['SMTP: port 465 is TLS from the first byte, with no STARTTLS'] = $smtpKind($mailer) === 'sent'
    && $c->log[0] === '[open smtp.example.com:465 tls 7s]' && !in_array('STARTTLS', $c->commands(), true);
[$mailer, $c] = $smtp(array_merge(['220 x', '250-x', '250 AUTH PLAIN'], $tail), 'none', '', '');
$checks['SMTP: a local relay without credentials is spoken to plainly'] = $smtpKind($mailer) === 'sent'
    && $c->commands() === ['EHLO example.com', 'MAIL FROM:<codes@example.com>', 'RCPT TO:<kevin@example.org>', 'DATA', '[data]', 'QUIT'];
$rcpt = static function (string $reply) use ($smtp, $smtpKind): string {
    [$mailer] = $smtp(['220 x', '250-x', '250 AUTH PLAIN', '235 OK', '250 OK', $reply], 'ssl');
    return $smtpKind($mailer);
};
$checks['SMTP: an unknown mailbox is a refused address'] = str_starts_with($rcpt('550 5.1.1 <kevin@example.org>: Recipient address rejected: User unknown'), 'rejected:')
    && str_starts_with($rcpt('553 Mailbox name not allowed'), 'rejected:');
$checks['SMTP: and the server\'s text, which quotes the address, is not passed on'] = !str_contains($rcpt('550 5.1.1 <kevin@example.org>: User unknown'), 'kevin');
$checks['SMTP: relaying denied is this server\'s configuration, so an outage'] = str_starts_with($rcpt('550 5.7.1 Relaying denied'), 'unavailable:')
    && str_starts_with($rcpt('451 4.3.0 Try again later'), 'unavailable:');
[$mailer, $c] = $smtp(['HTTP/1.1 400 Bad Request']);
$checks['SMTP: something that is not an SMTP server is an outage'] = $smtpKind($mailer) === 'unavailable: smtp: an answer that is not SMTP' && $c->closed;
[$mailer, $c] = $smtp([]);
$checks['SMTP: so is silence'] = str_starts_with($smtpKind($mailer), 'unavailable:') && $c->closed;
[$mailer, $c] = $smtp($starttls);
$checks['SMTP: an address carrying a line break never reaches the server'] = str_starts_with($smtpKind($mailer, "kevin@example.org\r\nRCPT TO:<x@example.net>"), 'rejected:')
    && $c->log === [];
[$mailer, $c] = $smtp($starttls);
$mailer->send('kevin@example.org', "Hello\r\nBcc: someone@example.net", 'Body');
$checks['SMTP: a subject cannot add a header'] = !preg_match('/^Bcc:/mi', $c->data) && str_contains($c->data, "\r\nSubject: Hello  Bcc: someone@example.net\r\n");
[$mailer, $c] = $smtp($starttls);
$mailer->send('kevin@example.org', 'Código de acceso', "Één regel\nTwo");
$checks['SMTP: a non-ASCII subject is an encoded word, the body quoted-printable'] = str_contains($c->data, "\r\nSubject: =?UTF-8?B?".base64_encode('Código de acceso')."?=\r\n")
    && str_contains($c->data, "\r\n=C3=89=C3=A9n regel\r\nTwo\r\n");
$c = new ScriptedSmtp(array_merge(['220 x', '250-x', '250-STARTTLS', '250 OK', '220 Go', '250-x', '250 AUTH PLAIN', '235 OK'], $tail));
(new SmtpMailer('smtp.example.com', 587, 'tls', 'u', 'p', 'codes@example.com', 'Clöud "Hub"', $c))->send('kevin@example.org', 'S', 'B');
$checks['SMTP: a sender name is quoted, or encoded when it is not ASCII'] = str_contains($c->data, "\r\nFrom: =?UTF-8?B?".base64_encode('Clöud "Hub"')."?= <codes@example.com>\r\n");

// --- configuration ---------------------------------------------------------------------

$base = ['smtp_host' => 'smtp.example.com', 'smtp_encryption' => 'tls', 'mail_from_address' => 'codes@example.com',
    'smtp_username' => 'mailer@example.com', 'smtp_password' => 'secret-app-pass'];
$problems = [];
$problem = static function (array $over) use ($base, &$problems): string {
    $p = (string)Mail::problem($over + $base);
    $problems[] = $p;
    return $p;
};
$checks['no SMTP_HOST: no email, and nothing to complain about'] = Mail::fromConfig([]) === null && Mail::problem([]) === null
    && Mail::problem(['smtp_host' => '  ', 'mail_from_address' => '']) === null;
$checks['a complete configuration gives the SMTP sender'] = Mail::problem($base) === null && Mail::fromConfig($base) instanceof SmtpMailer;
$checks['MAIL_FROM_ADDRESS has to be an address'] = str_contains($problem(['mail_from_address' => '']), 'MAIL_FROM_ADDRESS')
    && str_contains($problem(['mail_from_address' => 'CloudHub']), 'MAIL_FROM_ADDRESS') && Mail::fromConfig(['mail_from_address' => 'x'] + $base) === null;
$checks['SMTP_HOST is a host: no scheme, port or user'] = str_contains($problem(['smtp_host' => 'smtps://smtp.example.com']), 'SMTP_HOST')
    && str_contains($problem(['smtp_host' => 'smtp.example.com:587']), 'SMTP_HOST') && str_contains($problem(['smtp_host' => 'me@smtp.example.com']), 'SMTP_HOST')
    && $problem(['smtp_host' => '[2001:db8::25]']) === '';
$checks['the two ports with a fixed meaning cannot be swapped'] = str_contains($problem(['smtp_port' => 465, 'smtp_encryption' => 'tls']), 'SMTP_PORT=465')
    && str_contains($problem(['smtp_port' => 587, 'smtp_encryption' => 'ssl']), 'SMTP_PORT=587') && str_contains($problem(['smtp_port' => 70000]), 'SMTP_PORT');
$checks['an encryption that is not tls, ssl or none is refused'] = str_contains($problem(['smtp_encryption' => 'starttls']), 'SMTP_ENCRYPTION');
$checks['unencrypted SMTP to the internet is refused'] = str_contains($problem(['smtp_encryption' => 'none', 'smtp_port' => 25]), 'SMTP_ENCRYPTION=none');
$checks['but allowed to this machine or the local network'] = $problem(['smtp_encryption' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 1025]) === ''
    && $problem(['smtp_encryption' => 'none', 'smtp_host' => 'localhost']) === '' && $problem(['smtp_encryption' => 'none', 'smtp_host' => '192.168.1.10']) === ''
    && $problem(['smtp_encryption' => 'none', 'smtp_host' => '10.0.0.5']) === '' && $problem(['smtp_encryption' => 'none', 'smtp_host' => '::1']) === '';
$checks['a username goes with a password'] = str_contains($problem(['smtp_password' => '']), 'SMTP_USERNAME and SMTP_PASSWORD')
    && str_contains($problem(['smtp_username' => '']), 'SMTP_USERNAME and SMTP_PASSWORD') && $problem(['smtp_username' => '', 'smtp_password' => '']) === '';
$checks['a CA bundle that cannot be read is refused'] = str_contains($problem(['smtp_ca_file' => $scratch.'/no-such-ca.pem']), 'SMTP_CA_FILE');
$checks['problems name settings, never their values'] = array_filter($problems, static fn(string $p): bool =>
    str_contains($p, 'secret-app-pass') || str_contains($p, 'smtp.example.com') || str_contains($p, 'codes@example.com') || str_contains($p, 'no-such-ca')) === [];
$port = static function (array $over) use ($base): string {
    $c = new ScriptedSmtp([]);
    try { Mail::fromConfig($over + $base, $c)?->send('kevin@example.org', 'S', 'B'); } catch (MailException) {}
    return $c->log[0] ?? '';
};
$checks['without SMTP_PORT the encryption picks the port'] = $port([]) === '[open smtp.example.com:587 plain 10s]'
    && $port(['smtp_encryption' => 'ssl']) === '[open smtp.example.com:465 tls 10s]'
    && $port(['smtp_encryption' => 'none', 'smtp_host' => '127.0.0.1']) === '[open 127.0.0.1:25 plain 10s]'
    && $port(['smtp_port' => 2525, 'smtp_timeout_seconds' => 999]) === '[open smtp.example.com:2525 plain 60s]';

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
$fourth = $repo->createChallenge(1, 'email');
$checks['a challenge is used once: the second of two racing callers loses'] = $repo->consume($fourth) && !$repo->consume($fourth)
    && $repo->challenge($fourth) === null;
$checks['the old purpose for a new number is gone'] = (function () use ($repo): bool {
    try { $repo->createChallenge(1, 'phone'); return false; } catch (InvalidArgumentException) { return true; }
})();
$db->exec('DELETE FROM two_factor_challenges WHERE user_id = 1');
$repo->replaceRecoveryCodes(1, ['h1', 'h2']);
$checks['a recovery code is spent once'] = $repo->useRecoveryCode(1, 'h1') && !$repo->useRecoveryCode(1, 'h1') && $repo->recoveryCodesLeft(1) === 1;
$checks["and only by its own account"] = !$repo->useRecoveryCode(2, 'h2') && $repo->recoveryCodesLeft(1) === 1;
$repo->replaceRecoveryCodes(1, ['h3']);
$checks['replacing them retires the old ones'] = !$repo->useRecoveryCode(1, 'h2') && $repo->recoveryCodesLeft(1) === 1;
$checks['every account starts with it off'] = $repo->state(1) === ['enabled' => false, 'email' => null, 'enabledAt' => null]
    && !$repo->requiredFor(2) && $repo->state(999) === null;
$checks['turning it on reports that it did, once'] = $repo->enable(1, 'alice.old@example.com') && !$repo->enable(1, 'alice@example.com')
    && $repo->state(1)['email'] === 'alice@example.com';
$repo->disable(1);
$checks['turning it off takes the address, codes and challenges with it'] = $repo->state(1)['enabled'] === false && $repo->state(1)['email'] === null
    && $repo->recoveryCodesLeft(1) === 0;

$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bare->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, is_active INTEGER, role TEXT)");
$bare->exec("INSERT INTO users VALUES (1, 'legacy', '', 1, 'viewer')");
$legacy = new TwoFactorRepository($bare);
$checks['a database never migrated reads as off, for everyone'] = $legacy->state(1) === ['enabled' => false, 'email' => null, 'enabledAt' => null]
    && !$legacy->schemaReady() && $legacy->enabledUserIds() === [];
$checks['and so does the session check'] = (new CloudHub\Repositories\UserRepository($bare))->status(1)['twoFactor'] === false;
// Set up for text-message codes and not yet migrated: the column the codes
// went to is there, the one they go to now is not.
$sms = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sms->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, is_active INTEGER, role TEXT, two_factor_phone TEXT, two_factor_enabled_at TEXT)");
$sms->exec("INSERT INTO users VALUES (1, 'texted', '', 1, 'viewer', '+31612345678', '2026-10-01 12:00:00'), (2, 'plain', '', 1, 'viewer', NULL, NULL)");
$smsRepo = new TwoFactorRepository($sms);
$checks['an account that had text-message codes still needs a second step before the update'] = $smsRepo->requiredFor(1)
    && $smsRepo->state(1)['enabled'] === true && $smsRepo->state(1)['email'] === null && !$smsRepo->requiredFor(2);
$checks['which is not ready for changes until it is migrated'] = !$smsRepo->schemaReady()
    && (new CloudHub\Repositories\UserRepository($sms))->status(1)['twoFactor'] === true;
$checks['and an administrator is told to migrate rather than shown an error'] = refusal(fn() =>
    (new TwoFactor($sms, $smsRepo, new LoginRateLimiter($sms, $config), $mail, $config))->adminReset(3, 'admin', 'x', 1)) === 'NOT_AVAILABLE';
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
for ($i = 0; $i < 4; $i++) $slots[] = $limiter->claim('email_user', '1', 3, 3600);
$checks['three slots an hour means the fourth is refused'] = $slots[0] !== null && $slots[1] !== null && $slots[2] !== null && $slots[3] === null;
$checks['and says how long until one frees'] = $limiter->retryAfter('email_user', '1', 3, 3600) === 3600;
$checks['a refused claim does not hold a slot'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn() === 3;
$limiter->release((int)$slots[0]);
$checks['a released slot is free again'] = $limiter->claim('email_user', '1', 3, 3600) !== null;
$now += 3601;
$checks['and all of them once the hour is up'] = $limiter->retryAfter('email_user', '1', 3, 3600) === 0 && $limiter->claim('email_user', '1', 3, 3600) !== null;
$limiter->claim('email_address', 'alice@example.com', 3, 3600);
$checks['keys are HMACs, never the address or the account'] = !str_contains(implode(',', $db->query('SELECT attempt_key FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN)), 'example.com');
$resetThrottles();

// --- signing in --------------------------------------------------------------------

$signOut = static function (): void { $_SESSION = []; };
$signOut();
$plainLogin = Auth::login($db, 'bob', 'bob-pass-12345');
$checks['an account without it signs in with the password, as before'] = $plainLogin && Auth::user()['username'] === 'bob' && Auth::pendingSecondFactor() === null;
$checks['and its session is not marked as having passed a second factor'] = !isset($_SESSION['two_factor_verified_at']);

$repo->enable(1, 'alice@example.com');
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
$checks['nothing is emailed until the client asks'] = $mail->sent === [] && $info['codeSent'] === false
    && $info['emailHint'] === 'a•••@example.com' && $info['emailAvailable'] === true && $info['method'] === 'email';
$sendInfo = $tf->sendLoginCode();
$code = $mail->lastCode();
$checks['asking emails a code to the account\'s address'] = count($mail->sent) === 1 && $mail->sent[0]['to'] === 'alice@example.com' && $code !== ''
    && $sendInfo['sent'] === true && $sendInfo['emailHint'] === 'a•••@example.com' && $sendInfo['expiresIn'] === 300 && $sendInfo['resendIn'] === 60;
$checks['the code is stored only as its hash'] = !str_contains(json_encode($db->query('SELECT * FROM two_factor_challenges')->fetchAll()), $code);
$checks['asking again within a minute is refused, with the wait'] = refusal(fn() => $tf->sendLoginCode()) === 'TWO_FACTOR_RESEND_COOLDOWN'
    && count($mail->sent) === 1;
$checks['a malformed code is refused without spending an attempt'] = refusal(fn() => $tf->verifyLogin('12a456', null)) === 'VALIDATION_FAILED'
    && $repo->challenge((string)Auth::pendingSecondFactor()['challenge'])['attempts'] === 0;
$wrongCode = $code === '000000' ? '000001' : '000000';
try { $tf->verifyLogin($wrongCode, null); $wrongError = null; } catch (TwoFactorError $e) { $wrongError = $e; }
$checks['a wrong code is refused and says how many tries are left'] = $wrongError?->errorCode === 'TWO_FACTOR_CODE_INVALID' && ($wrongError->details['attemptsLeft'] ?? null) === 4;
$checks['and the session is still not signed in'] = Auth::user() === null && Auth::pendingSecondFactor() !== null;
$pendingId = session_id();
$signedIn = $tf->verifyLogin(" $code ", null);
$checks['the right code signs the session in'] = ($signedIn['user']['username'] ?? '') === 'alice' && Auth::user()['id'] === 1 && Auth::pendingSecondFactor() === null
    && $signedIn['method'] === 'email';
$checks['with yet another session id, marked as having passed the second factor'] = session_id() !== $pendingId && isset($_SESSION['two_factor_verified_at']);
$checks['the code is gone once used'] = (int)$db->query("SELECT COUNT(*) FROM two_factor_challenges WHERE user_id = 1 AND purpose = 'login'")->fetchColumn() === 0;
$checks['a right code takes no slot from the failure limit; the wrong one did'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'verify_user'")->fetchColumn() === 1;
$checks['a used code cannot be replayed'] = refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_EXPIRED';

// Expiry, exhaustion, supersession
$signOut(); $resetThrottles(); $mail->sent = [];
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $tf->sendLoginCode(); $code = $mail->lastCode();
$now += 301;
$checks['a code past its five minutes is refused'] = refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_CODE_EXPIRED' && Auth::user() === null;
$tf->sendLoginCode(); $fresh = $mail->lastCode();
$checks['a new code replaces it, and the old one stays dead'] = $fresh !== $code ? refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_CODE_INVALID' : true;
$now += 61;
$tf->sendLoginCode(); $newest = $mail->lastCode();
$checks['a superseded code no longer works'] = $newest === $fresh || refusal(fn() => $tf->verifyLogin($fresh, null)) === 'TWO_FACTOR_CODE_INVALID';
$guesses = [];
for ($i = 0; $i < 6; $i++) $guesses[] = refusal(fn() => $tf->verifyLogin($newest === '999999' ? '999998' : '999999', null));
$checks['five wrong guesses kill the code'] = $guesses[count($guesses) - 1] === 'TWO_FACTOR_CODE_EXHAUSTED';
$checks['after which even the right one is refused'] = refusal(fn() => $tf->verifyLogin($newest, null)) === 'TWO_FACTOR_CODE_EXHAUSTED' && Auth::user() === null;

// Another sign-in elsewhere replaces this one's code
$signOut(); $resetThrottles(); $mail->sent = []; $now += 61;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $tf->sendLoginCode(); $codeA = $mail->lastCode();
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
    $tf->sendLoginCode(); $c = $mail->lastCode();
    for ($i = 0; $i < 5; $i++) {
        $r = refusal(fn() => $tf->verifyLogin($c === '123123' ? '321321' : '123123', null));
        if ($r === 'TWO_FACTOR_LOCKED') { $locked = $round * 5 + $i; break; }
    }
}
$checks['ten wrong codes in an hour lock the account\'s verification, across new codes'] = $locked === 10;
$checks['and the lock holds for the right code too'] = refusal(fn() => $tf->verifyLogin($c, null)) === 'TWO_FACTOR_LOCKED';
$checks['refusals while locked do not lengthen it'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'verify_user'")->fetchColumn() === 10;

// The hourly email limit
$signOut(); $resetThrottles(); $mail->sent = [];
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$sends = [];
for ($i = 0; $i < 6; $i++) { $now += 61; $sends[] = refusal(fn() => $tf->sendLoginCode()); }
$checks['five code emails an hour per account; the sixth is refused'] = $sends === [null, null, null, null, null, 'TWO_FACTOR_EMAIL_LIMIT'] && count($mail->sent) === 5;
try { $tf->sendLoginCode(); $limitError = null; } catch (TwoFactorError $e) { $limitError = $e; }
$checks['with how long to wait'] = $limitError !== null && $limitError->retryAfter > 3000 && $limitError->retryAfter <= 3600;

// Recovery codes at sign-in
$signOut(); $resetThrottles(); $mail->sent = [];
$plainCodes = array_map(static fn(): string => TwoFactor::generateRecoveryCode(), range(1, 3));
$repo->replaceRecoveryCodes(1, array_map(static fn(string $c): string => TwoFactor::recoveryHash(1, $c), $plainCodes));
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$checks['a wrong recovery code is refused'] = refusal(fn() => $tf->verifyLogin(null, TwoFactor::generateRecoveryCode())) === 'TWO_FACTOR_RECOVERY_INVALID';
$viaRecovery = $tf->verifyLogin(null, strtoupper(TwoFactor::formatRecoveryCode($plainCodes[0])));
$checks['a recovery code signs in without any email'] = Auth::user()['id'] === 1 && $mail->sent === [] && $viaRecovery['method'] === 'recovery';
$checks['and says how many are left'] = $viaRecovery['recoveryCodesLeft'] === 2;
$signOut();
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$checks['a recovery code works once'] = refusal(fn() => $tf->verifyLogin(null, $plainCodes[0])) === 'TWO_FACTOR_RECOVERY_INVALID' && Auth::user() === null;

// A sign-in that waits too long, gives up, or loses its account
$_SESSION['two_factor_login']['at'] = time() - Auth::SECOND_FACTOR_WINDOW - 1;
$checks['a password proven too long ago has to be entered again'] = Auth::pendingSecondFactor() === null
    && refusal(fn() => $tf->sendLoginCode()) === 'TWO_FACTOR_EXPIRED' && Auth::finishSecondFactor($db) === null;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $resetThrottles(); $now += 61; $tf->sendLoginCode(); $code = $mail->lastCode();
$tf->cancelLogin();
$checks['cancelling forgets the sign-in and its code'] = Auth::pendingSecondFactor() === null && refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_EXPIRED'
    && (int)$db->query("SELECT COUNT(*) FROM two_factor_challenges WHERE purpose = 'login'")->fetchColumn() === 0;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin(); $now += 61; $tf->sendLoginCode(); $code = $mail->lastCode();
$db->exec('UPDATE users SET is_active = 0 WHERE id = 1');
$checks['an account disabled while it waits is not signed in, even with the right code'] = refusal(fn() => $tf->verifyLogin($code, null)) === 'TWO_FACTOR_EXPIRED' && Auth::user() === null;
$db->exec('UPDATE users SET is_active = 1 WHERE id = 1');
$checks['finishing with nothing pending signs nobody in'] = Auth::finishSecondFactor($db) === null && Auth::user() === null;

// The mail server is down, refuses, or there is none: never a way round the code
$signOut(); $resetThrottles(); $now += 61;
Auth::login($db, 'alice', 'alice-pass-1234'); $tf->beginLogin();
$mail->fail = new MailException(MailException::UNAVAILABLE, 'smtp: could not connect to smtp.example.com:587 (110 Connection timed out)');
$checks['an outage is reported, and the session stays signed out'] = refusal(fn() => $tf->sendLoginCode()) === 'EMAIL_UNAVAILABLE' && Auth::user() === null;
$checks['an outage keeps its slot: the email may still have gone'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'email_user'")->fetchColumn() === 1;
$checks['and the code it armed is checked as usual, not waved through'] = refusal(fn() => $tf->verifyLogin('000000', null)) === 'TWO_FACTOR_CODE_INVALID' && Auth::user() === null;
$now += 61;
$mail->fail = new MailException(MailException::REJECTED, 'smtp: RCPT TO refused (550 5.1.x)');
$checks['a refused address is reported as such'] = refusal(fn() => $tf->sendLoginCode()) === 'EMAIL_REJECTED';
$checks['and gives its slots back: nothing was sent'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'email_user'")->fetchColumn() === 1;
$mail->fail = null;
$none = new TwoFactor($db, $repo, $limiter, null, $config, $clock);
$checks['with no mail server at all the code cannot be sent'] = refusal(fn() => $none->sendLoginCode()) === 'EMAIL_NOT_CONFIGURED' && Auth::user() === null;
$checks['and the client is told to use a recovery code'] = $none->loginStatus()['emailAvailable'] === false;
$checks['which still works'] = $none->verifyLogin(null, $plainCodes[1])['method'] === 'recovery' && Auth::user()['id'] === 1;

// An account that turned it on when codes went by text message: still on,
// with no address. Only a recovery code -- or an administrator -- gets it in.
$signOut(); $resetThrottles(); $mail->sent = []; $now += 3601;
$db->exec("UPDATE users SET two_factor_enabled_at = '2026-10-01 12:00:00', two_factor_email = NULL WHERE id = 5");
$daveCodes = array_map(static fn(): string => TwoFactor::generateRecoveryCode(), range(1, 3));
$repo->replaceRecoveryCodes(5, array_map(static fn(string $c): string => TwoFactor::recoveryHash(5, $c), $daveCodes));
$checks['an account from text-message days still needs its second step'] = Auth::login($db, 'dave', 'dave-pass-12345') === false
    && Auth::user() === null && (Auth::pendingSecondFactor()['user'] ?? null) === 5;
$daveInfo = $tf->beginLogin();
$checks['the client is told no code can be emailed to it'] = $daveInfo['emailAvailable'] === false && $daveInfo['emailHint'] === null;
$checks['asking for one is refused, and nothing is sent'] = refusal(fn() => $tf->sendLoginCode()) === 'TWO_FACTOR_NO_EMAIL' && $mail->sent === [] && Auth::user() === null;
$tf->verifyLogin(null, $daveCodes[0]);
$checks['a recovery code signs it in'] = Auth::user()['id'] === 5 && isset($_SESSION['two_factor_verified_at']);
// Auth stamps the sign-in with the real time; this test's clock runs ahead.
$_SESSION['two_factor_verified_at'] = $now;
$daveView = $tf->overview(5);
$checks['and the settings show it on, with no address'] = $daveView['enabled'] === true && $daveView['emailHint'] === null && $daveView['recoveryCodesLeft'] === 2;
$daveStart = $tf->startAction(5, 'dave', 'email', 'dave-pass-12345', 'dave@example.com');
$checks['having just used a recovery code, it adds an address with that address\'s code alone'] = $daveStart['stage'] === 'new' && $daveStart['sent'] === true
    && count($mail->sent) === 1 && $mail->sent[0]['to'] === 'dave@example.com';
$tf->confirmAction(5, $mail->lastCode(), null);
$checks['after which codes go there, its recovery codes untouched'] = $repo->state(5)['email'] === 'dave@example.com' && $repo->state(5)['enabled']
    && $repo->recoveryCodesLeft(5) === 2 && count($mail->sent) === 1;
$db->exec("UPDATE users SET two_factor_email = NULL WHERE id = 5");
$now += TwoFactor::RECENT_SECONDS + 61; $mail->sent = [];
$noAddress = $tf->startAction(5, 'dave', 'disable', 'dave-pass-12345', null);
$checks['later, turning it off with no address asks for a recovery code instead'] = $noAddress['stage'] === 'current' && $noAddress['sent'] === false
    && ($noAddress['error']['code'] ?? '') === 'TWO_FACTOR_NO_EMAIL' && $noAddress['recoveryAllowed'] === true && $mail->sent === [];
$checks['and asking for an email anyway is refused'] = refusal(fn() => $tf->resendAction(5)) === 'TWO_FACTOR_NO_EMAIL' && $mail->sent === [];
$tf->confirmAction(5, null, $daveCodes[1]);
$checks['which then turns it off'] = !$repo->state(5)['enabled'];

// --- changing it -------------------------------------------------------------------

$repo->disable(1); $repo->disable(2);
$signOut(); $resetThrottles(); $mail->sent = []; $now += 3601;
Auth::login($db, 'bob', 'bob-pass-12345');
$checks['changes need the current password'] = refusal(fn() => $tf->startAction(2, 'bob', 'email', 'wrong-password', 'bob@example.org')) === 'FORBIDDEN';
$checks['which counts as a failed sign-in'] = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'user'")->fetchColumn() === 1;
$checks['an address that is not one is refused when turning it on'] = refusal(fn() => $tf->startAction(2, 'bob', 'email', 'bob-pass-12345', 'bob at example.org')) === 'VALIDATION_FAILED'
    && refusal(fn() => $tf->startAction(2, 'bob', 'email', 'bob-pass-12345', null)) === 'VALIDATION_FAILED' && $mail->sent === [];
$checks['turning off what is not on is refused'] = refusal(fn() => $tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null)) === 'CONFLICT';
$checks['an unknown change is refused'] = refusal(fn() => $tf->startAction(2, 'bob', 'everything', 'bob-pass-12345', null)) === 'VALIDATION_FAILED'
    && refusal(fn() => $tf->startAction(2, 'bob', 'phone', 'bob-pass-12345', '+31612345678')) === 'VALIDATION_FAILED';
$checks['nor can it be turned on when no email can be sent'] = refusal(fn() => $none->startAction(2, 'bob', 'email', 'bob-pass-12345', 'bob@example.org')) === 'EMAIL_NOT_CONFIGURED';
$start = $tf->startAction(2, 'bob', 'email', 'bob-pass-12345', ' Bob@EXAMPLE.org ');
$checks['turning it on emails the new address, and only that'] = $start['stage'] === 'new' && $start['sent'] === true
    && count($mail->sent) === 1 && $mail->sent[0]['to'] === 'Bob@example.org' && $start['recoveryAllowed'] === false
    && $start['emailHint'] === 'B•••@example.org' && str_contains($mail->sent[0]['body'], 'to confirm this address');
$checks['a recovery code cannot stand in for proving a new address'] = refusal(fn() => $tf->confirmAction(2, null, TwoFactor::generateRecoveryCode())) === 'TWO_FACTOR_RECOVERY_NOT_ALLOWED';
$checks['a code is resent no sooner than a minute later'] = refusal(fn() => $tf->resendAction(2)) === 'TWO_FACTOR_RESEND_COOLDOWN' && count($mail->sent) === 1;
$done = $tf->confirmAction(2, $mail->lastCode(), null);
$checks['the code turns it on, with ten recovery codes shown once'] = $done['done'] === true && $done['enabled'] === true
    && count($done['recoveryCodes'] ?? []) === 10 && $repo->recoveryCodesLeft(2) === 10 && $repo->state(2)['email'] === 'Bob@example.org';
$checks['this session counts as having passed the second factor'] = isset($_SESSION['two_factor_verified_at']);
$checks['the change is over'] = refusal(fn() => $tf->confirmAction(2, $mail->lastCode(), null)) === 'TWO_FACTOR_NO_PENDING_CHANGE';
$bobCodes = $done['recoveryCodes'];
$checks['the same address in another case is the address already in use'] = refusal(fn() => $tf->startAction(2, 'bob', 'email', 'bob-pass-12345', 'bob@example.org')) === 'VALIDATION_FAILED';

// Moving to a new address: just verified, so only the new address's code
$mail->sent = []; $now += 61;
$move = $tf->startAction(2, 'bob', 'email', 'bob-pass-12345', 'bob.new@example.org');
$checks['a session that just proved its address moves with the new one\'s code only'] = $move['stage'] === 'new' && $mail->sent[0]['to'] === 'bob.new@example.org';
$moved = $tf->confirmAction(2, $mail->lastCode(), null);
$checks['moving keeps the recovery codes'] = $moved['done'] && $moved['recoveryCodes'] === null && $repo->recoveryCodesLeft(2) === 10
    && $repo->state(2)['email'] === 'bob.new@example.org';
$checks['and tells the old address that it happened, and where to (masked)'] = end($mail->sent)['to'] === 'Bob@example.org'
    && str_contains(end($mail->sent)['body'], 'was just changed to b•••@example.org') && !str_contains(end($mail->sent)['body'], 'bob.new@');

// Later on, the current address has to be proven first
$now += TwoFactor::RECENT_SECONDS + 61; $mail->sent = [];
$later = $tf->startAction(2, 'bob', 'email', 'bob-pass-12345', 'bob.third@example.org');
$checks['without a recent proof, the current address is asked for first'] = $later['stage'] === 'current' && $mail->sent[0]['to'] === 'bob.new@example.org'
    && str_contains($mail->sent[0]['body'], 'to send your CloudHub sign-in codes to another address') && $later['recoveryAllowed'] === true;
$next = $tf->confirmAction(2, $mail->lastCode(), null);
$checks['then the new address\'s code'] = $next['done'] === false && $next['stage'] === 'new' && end($mail->sent)['to'] === 'bob.third@example.org';
$tf->confirmAction(2, $mail->lastCode(), null);
$checks['and only then does the address change'] = $repo->state(2)['email'] === 'bob.third@example.org';

// Turning it off: always the current address, or a recovery code
$now += 61; $mail->sent = [];
$off = $tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null, 'recovery');
$checks['a recovery code can answer for a mailbox out of reach, and nothing is emailed'] = $off['stage'] === 'current' && $off['sent'] === false && $mail->sent === [];
$checks['a wrong recovery code does not turn it off'] = refusal(fn() => $tf->confirmAction(2, null, TwoFactor::generateRecoveryCode())) === 'TWO_FACTOR_RECOVERY_INVALID'
    && $repo->state(2)['enabled'];
$offDone = $tf->confirmAction(2, null, $bobCodes[0]);
$checks['a right one does, and takes the address and codes with it'] = $offDone['enabled'] === false && $repo->state(2)['enabled'] === false
    && $repo->recoveryCodesLeft(2) === 0 && $repo->state(2)['email'] === null;
$checks['the address is told'] = end($mail->sent)['to'] === 'bob.third@example.org' && str_contains(end($mail->sent)['body'], 'was turned off');

// New recovery codes need the current address too
$resetThrottles();
$tf->startAction(2, 'bob', 'email', 'bob-pass-12345', 'bob.fourth@example.org');
$tf->confirmAction(2, $mail->lastCode(), null);
$now += 61; $mail->sent = [];
$regen = $tf->startAction(2, 'bob', 'recovery', 'bob-pass-12345', null);
$checks['new recovery codes need a code from the current address'] = $regen['stage'] === 'current' && $regen['sent'] === true
    && $mail->sent[0]['to'] === 'bob.fourth@example.org';
$fresh = $tf->confirmAction(2, $mail->lastCode(), null);
$checks['and replace all the old ones'] = count($fresh['recoveryCodes']) === 10 && $repo->recoveryCodesLeft(2) === 10;

// A change waiting too long, or belonging to someone else
$now += 61;
$tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null);
$now += TwoFactor::ACTION_WINDOW + 1;
$checks['a change left waiting too long has to be started again'] = refusal(fn() => $tf->confirmAction(2, $mail->lastCode(), null)) === 'TWO_FACTOR_NO_PENDING_CHANGE';
$now += 61;
$tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null);
$cancelled = $mail->lastCode();
$tf->cancelAction(2);
$checks['a cancelled change takes its code with it'] = refusal(fn() => $tf->confirmAction(2, $cancelled, null)) === 'TWO_FACTOR_NO_PENDING_CHANGE'
    && $repo->state(2)['enabled'] && (int)$db->query("SELECT COUNT(*) FROM two_factor_challenges WHERE user_id = 2")->fetchColumn() === 0;
$now += 61;
$tf->startAction(2, 'bob', 'disable', 'bob-pass-12345', null);
$checks["one account's change cannot be confirmed as another's"] = refusal(fn() => $tf->confirmAction(1, $mail->lastCode(), null)) === 'TWO_FACTOR_NO_PENDING_CHANGE'
    && $repo->state(2)['enabled'];

// Begun while it was off, so no current address was asked for; turned on in
// another session meanwhile; this one must not then move it without that proof.
$repo->disable(4);
$signOut(); $resetThrottles(); $now += 3601;
Auth::login($db, 'carol', 'carol-pass-1234');
$begun = $tf->startAction(4, 'carol', 'email', 'carol-pass-1234', 'carol.race@example.net');
$racing = $mail->lastCode();
$repo->enable(4, 'carol@example.net');
unset($_SESSION['two_factor_verified_at']);
$checks['a change begun while it was off cannot finish once it was turned on elsewhere'] = $begun['stage'] === 'new'
    && refusal(fn() => $tf->confirmAction(4, $racing, null)) === 'TWO_FACTOR_NO_PENDING_CHANGE'
    && $repo->state(4)['email'] === 'carol@example.net';
$signOut();
Auth::login($db, 'bob', 'bob-pass-12345');

// --- an administrator's reset ------------------------------------------------------

$mail->sent = [];
$checks["an administrator's reset needs their own password"] = refusal(fn() => $tf->adminReset(3, 'admin', 'wrong', 2)) === 'FORBIDDEN' && $repo->state(2)['enabled'];
$reset = $tf->adminReset(3, 'admin', 'admin-pass-1234', 2);
$checks['a reset turns it off and emails the owner'] = !$repo->state(2)['enabled'] && end($mail->sent)['to'] === 'bob.fourth@example.org'
    && str_contains(end($mail->sent)['body'], 'An administrator turned off') && $reset['emailHint'] === 'b•••@example.org';
$checks['resetting what is off is refused'] = refusal(fn() => $tf->adminReset(3, 'admin', 'admin-pass-1234', 2)) === 'CONFLICT';
$signOut();
$checks['after a reset the password alone signs in again'] = Auth::login($db, 'bob', 'bob-pass-12345') && Auth::user()['id'] === 2;

// --- one code, end to end through the SMTP client ------------------------------------

$signOut(); $resetThrottles(); $now += 3601;
$wire = new ScriptedSmtp(array_merge(['220 x', '250-x', '250-STARTTLS', '250 OK', '220 Go', '250-x', '250 AUTH PLAIN', '235 OK'], $tail));
$smtpTf = new TwoFactor($db, $repo, $limiter, Mail::fromConfig($base, $wire), $config, $clock);
Auth::login($db, 'alice', 'alice-pass-1234');
$repo->enable(1, 'alice@example.com');
$signOut();
Auth::login($db, 'alice', 'alice-pass-1234'); $smtpTf->beginLogin(); $smtpTf->sendLoginCode();
$wireCode = preg_match('/\r\n    (\d{6})\r\n/', $wire->data, $m) ? $m[1] : '';
[$wireHeaders] = explode("\r\n\r\n", $wire->data, 2);
$checks['over SMTP, the code arrives in the body, and the subject has none'] = $wireCode !== '' && !str_contains($wireHeaders, $wireCode)
    && str_contains($wireHeaders, "\r\nSubject: Your CloudHub sign-in code\r\n") && in_array('RCPT TO:<alice@example.com>', $wire->commands(), true);
$checks['and signs the session in'] = ($smtpTf->verifyLogin($wireCode, null)['user']['id'] ?? null) === 1 && Auth::user()['id'] === 1;

// --- what is written down ------------------------------------------------------------

$codesSeen = [];
foreach ($mail->sent as $s) if (preg_match('/^    (\d{6})$/m', $s['body'], $m)) $codesSeen[] = $m[1];
if ($wireCode !== '') $codesSeen[] = $wireCode;
$trail = json_encode($db->query('SELECT event_type, outcome, username, context_json FROM security_events')->fetchAll(), JSON_UNESCAPED_UNICODE);
$checks['the audit trail records the events'] = str_contains($trail, 'two_factor.enable') && str_contains($trail, 'two_factor.disable')
    && str_contains($trail, 'two_factor.email') && str_contains($trail, 'auth.two_factor') && str_contains($trail, 'two_factor.email_change');
$mentions = static fn(string $text): array => array_filter($addresses, static fn(string $a): bool => stripos($text, $a) !== false);
$checks['with masked addresses at most'] = str_contains($trail, 'a•••@example.com');
$checks['and never a code or an address in full'] = $mentions((string)$trail) === []
    && array_filter($codesSeen, static fn(string $c): bool => str_contains((string)$trail, '"'.$c.'"')) === [];
$log = (string)@file_get_contents($errorLog);
$checks['the error log names the mail server\'s failures'] = str_contains($log, 'two-step code not sent: smtp: could not connect')
    && str_contains($log, 'two-step code not sent: smtp: RCPT TO refused (550 5.1.x)');
$checks['without a code or an address'] = $mentions($log) === []
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
$checks['the start route takes an email address'] = str_contains($index, "\$email = array_key_exists('email', \$b) ? Http::string(\$b, 'email', 1, 254) : null;");
$checks['an administrator cannot reset their own from the Users screen'] =
    str_contains($index, "Turn off your own two-step verification from Security, which asks for the code from your email");
$checks['the sessions of an account that turns it on must have passed it'] =
    str_contains((string)file_get_contents($root.'/src/Services/Auth.php'), "if(!empty(\$status['twoFactor'])&&empty(\$_SESSION['two_factor_verified_at'])){");
$checks['migrate.php adds everything, and drops no column or table'] = str_contains($migrate, "addColumn(\$pdo, 'users', 'two_factor_email', 'VARCHAR(254) NULL')")
    && str_contains($migrate, "addColumn(\$pdo, 'users', 'two_factor_enabled_at'")
    && str_contains($migrate, 'CREATE TABLE IF NOT EXISTS two_factor_challenges')
    && str_contains($migrate, 'CREATE TABLE IF NOT EXISTS two_factor_recovery_codes')
    && str_contains($migrate, "'email_address'") && !preg_match('/DROP (?:TABLE|COLUMN)/i', $migrate)
    && !str_contains($migrate, "addColumn(\$pdo, 'users', 'two_factor_phone'");
$tool = (string)file_get_contents($root.'/tools/reset-two-factor.php');
$checks['the operator\'s reset runs only from the command line'] = str_contains($tool, "if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }");
$checks['and turns it off the same way the web does, on the record'] = str_contains($tool, '$twoFactor->disable($account[\'id\']);')
    && str_contains($tool, "AuditLog::write(\$db, 'two_factor.admin_reset'");
$env = (string)file_get_contents($root.'/.env.example');
$checks['.env.example documents every setting, with no secret in it'] =
    (bool)preg_match('/^SMTP_HOST=$/m', $env) && (bool)preg_match('/^SMTP_PASSWORD=$/m', $env) && (bool)preg_match('/^SMTP_USERNAME=$/m', $env)
    && (bool)preg_match('/^MAIL_FROM_ADDRESS=$/m', $env) && (bool)preg_match('/^TWO_FACTOR_SECRET=$/m', $env)
    && str_contains($env, 'TWO_FACTOR_CODE_TTL_SECONDS=300') && str_contains($env, 'TWO_FACTOR_EMAIL_PER_HOUR=5');
$checks['and nothing of text messages is left'] = !preg_match('/^(?:SMS|TWILIO)_/m', $env)
    && !str_contains((string)file_get_contents($root.'/config/config.php'), "'sms_")
    && !is_dir($root.'/src/Services/Sms') && !is_file($root.'/src/Services/PhoneNumber.php')
    && !str_contains((string)file_get_contents($root.'/public/assets/js/app.js'), 'OTPCredential');

} catch (Throwable $e) {
    $checks['the run reached its end, rather than stopping at '.get_class($e).': '.$e->getMessage()] = false;
}

// --- report --------------------------------------------------------------------------

session_write_close();
array_map('unlink', glob($scratch.'/sessions/*') ?: []);
@rmdir($scratch.'/sessions');
@unlink($errorLog); @rmdir($scratch);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
