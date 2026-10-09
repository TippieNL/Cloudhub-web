<?php
declare(strict_types=1);

/**
 * Two-step verification by email over real HTTP, against a real database.
 *
 *   php tests/http/two_factor_run.php
 *
 * Needs the MySQL/MariaDB database .env points at, migrated with
 * php database/migrate.php. It creates its own accounts (tf<random>_...) and
 * deletes them when it is done, and starts what it talks to:
 *
 *   - CloudHub itself, on PHP's built-in server with four workers so requests
 *     can race, in production mode, sending through SMTP to:
 *   - tests/http/smtp_sink.php, a stand-in SMTP server that records each
 *     message (that is how codes are read here) and can be told to refuse,
 *     fail, stall or hang up; and a second one that speaks STARTTLS with a
 *     certificate from a CA made for this run;
 *   - more CloudHubs: with no mail server, with one that is not allowed
 *     (unencrypted to the internet), and over STARTTLS -- trusting that CA, not
 *     trusting it, under the wrong name, and to a server that offers no TLS.
 *
 * tests/phase54_two_factor_test.php covers the same rules in one process,
 * deterministically; this covers what only a deployment has: cookies, CSRF,
 * sessions on other devices, WebDAV, a mail server over the network, TLS,
 * races.
 */
require dirname(__DIR__, 2).'/config/bootstrap.php';
require __DIR__.'/Client.php';

use CloudHub\Helpers\Db;
use CloudHub\Repositories\UserRepository;
use CloudHub\Services\TwoFactor;
use CloudHub\Tests\Http\Client;
use CloudHub\Tests\Http\Response;

$root = dirname(__DIR__, 2);
$work = sys_get_temp_dir().'/cloudhub-2fa-http-'.bin2hex(random_bytes(4));
foreach (['sink', 'tlssink', 'sessions'] as $sub) mkdir($work.'/'.$sub, 0775, true);

/* ---- accounts of our own -------------------------------------------------- */

$db = Db::connection();
try {
    $db->query('SELECT two_factor_email, two_factor_enabled_at FROM users WHERE 1 = 0');
} catch (PDOException) {
    fwrite(STDERR, "The database has no two-step verification by email yet: run php database/migrate.php first.\n");
    exit(1);
}
$tag = 'tf'.bin2hex(random_bytes(3));
$repo = new UserRepository($db);
$accounts = [
    'editor' => [$tag.'_ed', 'editor-pass-12345', 'editor'],
    'viewer' => [$tag.'_vi', 'viewer-pass-12345', 'viewer'],
    'admin' => [$tag.'_ad', 'admin-pass-123456', 'admin'],
    'second' => [$tag.'_se', 'second-pass-12345', 'editor'],
    'legacy' => [$tag.'_le', 'legacy-pass-12345', 'viewer'],
    'tls' => [$tag.'_tl', 'tlsuser-pass-1234', 'viewer'],
];
$ids = [];
foreach ($accounts as $key => [$name, $password, $role]) $ids[$key] = $repo->create($name, $password, $role)['id'];
// Addresses of our own as well, so the per-address limit starts empty each run.
$address = static fn(string $who): string => $who.'.'.bin2hex(random_bytes(3)).'@example.org';

/* ---- a CA, and a certificate for localhost --------------------------------- */

$opensslConfig = $work.'/openssl.cnf';
file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\n[dn]\n"
    ."[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
    ."[server]\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n"
    ."subjectAltName=DNS:localhost\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
$sslOptions = ['config' => $opensslConfig, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
$caKey = openssl_pkey_new($sslOptions);
$caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'CloudHub test CA '.$tag], $caKey, $sslOptions), null, $caKey, 2,
    $sslOptions + ['x509_extensions' => 'ca'], random_int(1, 1 << 30));
$serverKey = openssl_pkey_new($sslOptions);
$serverCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $serverKey, $sslOptions), $caCert, $caKey, 2,
    $sslOptions + ['x509_extensions' => 'server'], random_int(1, 1 << 30));
openssl_x509_export_to_file($caCert, $work.'/ca.pem');
openssl_x509_export_to_file($serverCert, $work.'/server.pem');
openssl_pkey_export_to_file($serverKey, $work.'/server.key', null, $sslOptions);

/* ---- servers of our own --------------------------------------------------- */

$freePort = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
};
$processes = [];
$serve = static function (array $command, array $env, string $log) use (&$processes, $root): void {
    $process = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), $env));
    if (!is_resource($process)) { fwrite(STDERR, "Could not start ".implode(' ', $command)."\n"); exit(1); }
    $processes[] = $process;
};
$up = static function (string $url): bool {
    for ($i = 0; $i < 80; $i++) {
        if (@file_get_contents($url) !== false) return true;
        usleep(100_000);
    }
    return false;
};
$sinkPort = $freePort();
$serve([PHP_BINARY, __DIR__.'/smtp_sink.php', (string)$sinkPort, $work.'/sink'], [], $work.'/sink.log');
$tlsSinkPort = $freePort();
$serve([PHP_BINARY, __DIR__.'/smtp_sink.php', (string)$tlsSinkPort, $work.'/tlssink', $work.'/server.pem', $work.'/server.key'], [], $work.'/tlssink.log');

$smtpPassword = 'sink-pass-'.bin2hex(random_bytes(6));
$common = [
    'APP_ENV' => 'production', 'APP_URL' => 'https://cloud.example.test', 'TRASH_ENABLED' => 'false',
    'TWO_FACTOR_SECRET' => bin2hex(random_bytes(32)), 'SMTP_TIMEOUT_SECONDS' => '2',
    'SMTP_USERNAME' => 'sink-user', 'SMTP_PASSWORD' => $smtpPassword,
    'MAIL_FROM_ADDRESS' => 'codes@cloudhub.test', 'MAIL_FROM_NAME' => 'CloudHubTest', 'SMTP_CA_FILE' => '',
    // The hourly limits are proven in tests/phase54_two_factor_test.php with a
    // clock it controls. Here every request comes from 127.0.0.1 and this
    // suite sends more than five codes to one account, so they are raised
    // rather than throttle rows being deleted from what may be a real database.
    'TWO_FACTOR_EMAIL_PER_HOUR' => '100', 'TWO_FACTOR_FAILURES_PER_HOUR' => '100',
    'TWO_FACTOR_EMAIL_IP_PER_HOUR' => '1000', 'TWO_FACTOR_IP_FAILURES_PER_HOUR' => '1000', 'LOGIN_RATE_IP_ATTEMPTS' => '1000',
];
$plainSmtp = ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string)$sinkPort, 'SMTP_ENCRYPTION' => 'none'];
$servers = [
    'app' => $plainSmtp + ['PHP_CLI_SERVER_WORKERS' => '4'],
    'quiet' => ['SMTP_HOST' => ''],
    'internet' => ['SMTP_HOST' => 'smtp.example.com', 'SMTP_PORT' => '25', 'SMTP_ENCRYPTION' => 'none'],
    'tls' => ['SMTP_HOST' => 'localhost', 'SMTP_PORT' => (string)$tlsSinkPort, 'SMTP_ENCRYPTION' => 'tls', 'SMTP_CA_FILE' => $work.'/ca.pem'],
    'untrusted' => ['SMTP_HOST' => 'localhost', 'SMTP_PORT' => (string)$tlsSinkPort, 'SMTP_ENCRYPTION' => 'tls'],
    'wrongname' => ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string)$tlsSinkPort, 'SMTP_ENCRYPTION' => 'tls', 'SMTP_CA_FILE' => $work.'/ca.pem'],
    'nostarttls' => ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string)$sinkPort, 'SMTP_ENCRYPTION' => 'tls'],
];
$url = [];
foreach ($servers as $name => $env) {
    $port = $freePort();
    $serve([PHP_BINARY, '-d', 'session.save_path='.$work.'/sessions', '-S', '127.0.0.1:'.$port, 'router.php'], $env + $common, $work.'/'.$name.'.log');
    $url[$name] = 'http://127.0.0.1:'.$port;
}

register_shutdown_function(static function () use (&$processes, $db, $ids, $work): void {
    foreach ($processes as $process) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    foreach ($ids as $id) {
        foreach (['DELETE FROM two_factor_challenges WHERE user_id = ?', 'DELETE FROM two_factor_recovery_codes WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
            try { $db->prepare($sql)->execute([$id]); } catch (Throwable) {}
        }
    }
    foreach (array_merge(glob($work.'/*/*') ?: [], glob($work.'/*') ?: []) as $path) is_dir($path) ? @rmdir($path) : @unlink($path);
    @rmdir($work);
});

$base = $url['app'];
foreach ($url as $server) {
    if (!$up($server.'/?route='.rawurlencode('/api/auth/status'))) { fwrite(STDERR, "$server never answered.\n"); exit(1); }
}
for ($i = 0; $i < 80 && !(is_file($work.'/sink/ready') && is_file($work.'/tlssink/ready')); $i++) usleep(100_000);
if (!is_file($work.'/sink/ready') || !is_file($work.'/tlssink/ready')) { fwrite(STDERR, "The SMTP stand-ins never started.\n"); exit(1); }

/* ---- the harness ---------------------------------------------------------- */

$passed = 0;
$failures = [];
$currentCase = '';

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failures, $currentCase;
    if ($ok) { $passed++; return; }
    $failures[] = $currentCase.': '.$what.($detail !== '' ? ' -- '.$detail : '');
}

function scenario(string $name, callable $body): void
{
    global $currentCase, $failures;
    $currentCase = $name;
    try { $body(); } catch (Throwable $e) { $failures[] = $name.': threw '.get_class($e).' -- '.$e->getMessage(); }
    echo '  '.$name.PHP_EOL;
}

function sink(string $mode, string $which = 'sink'): void
{
    global $work;
    file_put_contents($work.'/'.$which.'/mode', $mode);
}

/** @return list<array> every message a stand-in accepted, oldest first */
function mails(string $which = 'sink'): array
{
    global $work;
    $lines = @file($work.'/'.$which.'/received.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_map(static fn(string $l): array => json_decode($l, true) ?: [], $lines);
}

/** @return list<array> every conversation a stand-in had, oldest first */
function conversations(string $which = 'sink'): array
{
    global $work;
    $lines = @file($work.'/'.$which.'/sessions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_map(static fn(string $l): array => json_decode($l, true) ?: [], $lines);
}

function last_mail(string $which = 'sink'): array
{
    $all = mails($which);
    return $all ? $all[count($all) - 1] : [];
}

function header_of(array $mail, string $name): string
{
    [$headers] = explode("\r\n\r\n", (string)($mail['data'] ?? ''), 2);
    return preg_match('/^'.preg_quote($name, '/').': (.*)$/mi', $headers, $m) ? rtrim($m[1], "\r") : '';
}

function body_of(array $mail): string
{
    $parts = explode("\r\n\r\n", (string)($mail['data'] ?? ''), 2);
    return quoted_printable_decode($parts[1] ?? '');
}

function code_of(array $mail): string
{
    return preg_match('/^    (\d{6})\r?$/m', body_of($mail), $m) ? $m[1] : '';
}

/**
 * Make the session re-read its account on the next request rather than up to
 * a minute later, by editing what the server keeps in the session file.
 */
function recheck_now(Client $client): void
{
    global $work;
    $file = $work.'/sessions/sess_'.$client->cookie('cloudhub_session');
    $data = (string)@file_get_contents($file);
    file_put_contents($file, preg_replace('/account_checked_at\|i:\d+;/', 'account_checked_at|i:1;', $data));
}

/** Sign in to an account with two-step verification on, all the way. */
function sign_in_with_code(Client $client, string $user, string $password, string $which = 'sink'): Response
{
    $login = $client->signIn($user, $password);
    if ($login->errorCode() !== 'TWO_FACTOR_REQUIRED') return $login;
    $client->post('/api/auth/two-factor/send');
    return $client->post('/api/auth/two-factor/verify', ['code' => code_of(last_mail($which))]);
}

/** Turn it on for a signed-in client, through the API. @return array the confirm answer */
function enable(Client $client, string $password, string $email, string $which = 'sink'): array
{
    $client->post('/api/users/me/two-factor/start', ['action' => 'email', 'currentPassword' => $password, 'email' => $email]);
    return $client->post('/api/users/me/two-factor/confirm', ['code' => code_of(last_mail($which))])->json ?? [];
}

[$editor, $editorPass] = $accounts['editor'];
[$viewer, $viewerPass] = $accounts['viewer'];
[$admin, $adminPass] = $accounts['admin'];
[$second, $secondPass] = $accounts['second'];
$editorEmail = $address('editor');
$recovery = [];
$GLOBALS['addresses'] = [$editorEmail];
sink('ok');
echo "CloudHub two-step verification by email over HTTP (app :".parse_url($base, PHP_URL_PORT).", SMTP :$sinkPort, STARTTLS :$tlsSinkPort)".PHP_EOL;

/* ---- nothing changes for accounts without it -------------------------------- */

scenario('an account without two-step verification signs in exactly as before', function () use ($base, $editor, $editorPass) {
    $c = new Client($base);
    $status = $c->get('/api/auth/status');
    check('status: signed out, a token, nothing about a second step', $status->status === 200
        && ($status->json['authenticated'] ?? null) === false && !array_key_exists('twoFactor', $status->json ?? []) && $c->csrfToken() !== '');
    $bad = $c->signIn($editor, 'not-the-password-123');
    check('a wrong password is refused as before', $bad->status === 401 && $bad->errorCode() === 'UNAUTHORIZED'
        && ($bad->json['error']['message'] ?? '') === 'Invalid username or password', $bad->describe());
    $before = count(mails());
    $r = $c->signIn($editor, $editorPass);
    check('the right one signs in, with the same answer as before', $r->status === 200 && ($r->json['success'] ?? false) === true
        && ($r->json['user']['username'] ?? '') === $editor && isset($r->json['csrfToken']) && !isset($r->json['twoFactor']), $r->describe());
    check('and no email is sent', count(mails()) === $before);
    check('the session works', $c->get('/api/files/list', ['path' => '/'])->status === 200);
});

/*
 * Names without an extension, on purpose. Under PHP's built-in server a URL
 * ending in one -- /webdav/notes.txt, a share link /share/TOKEN.txt -- arrives
 * with SCRIPT_NAME set to that path, and Http::basePath() strips it: the
 * request answers "Not found". That predates two-step verification (the
 * original code does the same) and only affects the development server, which
 * this suite runs on; Cloudhub-2's router.php carries the fix.
 */
$stored = '/tf-http-'.bin2hex(random_bytes(4));
$storedBytes = "two-step verification suite\n".str_repeat('0123456789', 100);

scenario('uploads, share links, WebDAV and the password work as before', function () use ($base, $editor, $editorPass, $stored, $storedBytes) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $init = $c->post('/api/uploads/init', ['targetPath' => '/', 'name' => ltrim($stored, '/'), 'size' => strlen($storedBytes),
        'uploadId' => 'tfhttp'.bin2hex(random_bytes(8)), 'conflict' => 'overwrite']);
    $id = (string)($init->json['id'] ?? '');
    check('an upload starts', $init->ok() && $id !== '', $init->describe());
    check('takes its bytes', $c->putChunk($id, 0, $storedBytes)->ok());
    check('and completes', $c->post('/api/uploads/complete', ['id' => $id])->ok());
    $down = $c->get('/api/files/download', ['path' => $stored]);
    check('the file comes back unchanged', $down->body === $storedBytes, strlen($down->body).' bytes');

    $share = $c->post('/api/shares/create', ['filePath' => $stored]);
    check('a share link is made', $share->ok() && str_contains((string)($share->json['url'] ?? ''), '/share/'), $share->describe());
    $public = (new Client($base))->fetchUrl((string)($share->json['url'] ?? ''));
    check('and serves the file to someone with no account', $public->status === 200 && $public->body === $storedBytes, (string)$public->status);
    check('the link is revoked', $c->delete('/api/shares/revoke', ['token' => (string)($share->json['token'] ?? '')])->ok());

    $list = $c->dav('PROPFIND', '/webdav/', ['Depth: 1']);
    check('WebDAV lists the folder', $list->status === 207 && str_contains($list->body, rawurlencode(ltrim($stored, '/'))), (string)$list->status);
    $davName = '/tf-dav-'.bin2hex(random_bytes(4));
    check('WebDAV stores a file', in_array($c->dav('PUT', '/webdav'.$davName, ['Content-Type: text/plain'], 'over webdav')->status, [200, 201, 204], true));
    check('and serves it', $c->dav('GET', '/webdav'.$davName)->body === 'over webdav');
    check('and deletes it', in_array($c->dav('DELETE', '/webdav'.$davName)->status, [200, 204], true));

    $changed = $c->post('/api/users/me/password', ['currentPassword' => $editorPass, 'newPassword' => $editorPass.'-new']);
    check('the password can be changed', $changed->ok(), $changed->describe());
    check('and signs in afterwards', (new Client($base))->signIn($editor, $editorPass.'-new')->ok());
    check('and changed back', $c->post('/api/users/me/password', ['currentPassword' => $editorPass.'-new', 'newPassword' => $editorPass])->ok());
    check('the test file is removed again', $c->delete('/api/files/delete', ['path' => $stored])->ok());
    $out = $c->post('/api/auth/logout');
    check('signing out works', $out->ok() && $c->get('/api/files/list', ['path' => '/'])->status === 401);
});

/* ---- turning it on ------------------------------------------------------------ */

$early = new Client($base);
$early->signIn($editor, $editorPass);
$owner = new Client($base);

scenario('turning it on takes the password and the new address\'s code', function () use ($owner, $editor, $editorPass, $editorEmail, $smtpPassword, &$recovery) {
    $owner->signIn($editor, $editorPass);
    $overview = $owner->get('/api/users/me/two-factor');
    check('it starts off, and the server can send email', ($overview->json['enabled'] ?? null) === false && ($overview->json['available'] ?? null) === true
        && ($overview->json['emailAvailable'] ?? null) === true, $overview->describe());
    $body = ['action' => 'email', 'currentPassword' => $editorPass, 'email' => $editorEmail];
    $noToken = $owner->postWithoutCsrf('/api/users/me/two-factor/start', $body);
    check('without the CSRF token it is refused', $noToken->status === 419, $noToken->describe());
    $crossSite = $owner->post('/api/users/me/two-factor/start', $body, ['Sec-Fetch-Site: cross-site']);
    check('from another site it is refused', $crossSite->status === 403 && $crossSite->errorCode() === 'CROSS_SITE_REQUEST', $crossSite->describe());
    $wrong = $owner->post('/api/users/me/two-factor/start', ['currentPassword' => 'not-the-password-1'] + $body);
    check('with the wrong password it is refused', $wrong->status === 403, $wrong->describe());
    $before = count(mails());
    $invalid = $owner->post('/api/users/me/two-factor/start', ['email' => "someone@example.org\r\nBcc: x@example.net"] + $body);
    check('an address that is not one is refused, and nothing is sent', $invalid->status === 422 && count(mails()) === $before, $invalid->describe());

    [$local, $domain] = explode('@', $editorEmail);
    $start = $owner->post('/api/users/me/two-factor/start', ['email' => '  '.$local.'@'.strtoupper($domain).' '] + $body);
    check('a code goes to the address', $start->ok() && ($start->json['sent'] ?? false) === true && count(mails()) === $before + 1, $start->describe());
    $mail = last_mail();
    check('to the mail server, signed in with its credentials', ($mail['to'] ?? null) === [$editorEmail]
        && ($mail['from'] ?? '') === 'codes@cloudhub.test' && ($mail['auth']['user'] ?? '') === 'sink-user' && ($mail['auth']['pass'] ?? '') === $smtpPassword);
    check('as plain text from CloudHubTest, marked as automatic', header_of($mail, 'From') === '"CloudHubTest" <codes@cloudhub.test>'
        && header_of($mail, 'To') === '<'.$editorEmail.'>' && str_starts_with(header_of($mail, 'Content-Type'), 'text/plain; charset=UTF-8')
        && header_of($mail, 'Auto-Submitted') === 'auto-generated');
    check('saying what it is for, with the code in the body only', str_contains(body_of($mail), 'to confirm this address for CloudHubTest two-step verification')
        && code_of($mail) !== '' && header_of($mail, 'Subject') === 'Confirm this address for CloudHubTest sign-in codes'
        && !str_contains(explode("\r\n\r\n", (string)$mail['data'], 2)[0], code_of($mail)));
    check('the answer shows the address masked', ($start->json['emailHint'] ?? '') === $editorEmail[0].'•••@example.org'
        && !str_contains($start->body, $local));
    $wrongCode = $owner->post('/api/users/me/two-factor/confirm', ['code' => code_of($mail) === '000000' ? '000001' : '000000']);
    check('a wrong code is refused, with the tries left', $wrongCode->status === 422 && ($wrongCode->json['error']['details']['attemptsLeft'] ?? null) === 4, $wrongCode->describe());
    $done = $owner->post('/api/users/me/two-factor/confirm', ['code' => code_of($mail)]);
    check('the right one turns it on', ($done->json['done'] ?? false) === true && ($done->json['enabled'] ?? false) === true, $done->describe());
    $recovery = $done->json['recoveryCodes'] ?? [];
    check('with ten recovery codes, shown this once', count($recovery) === 10);
    $overview = $owner->get('/api/users/me/two-factor');
    check('it reads as on, without the address in full', ($overview->json['enabled'] ?? null) === true && ($overview->json['recoveryCodesLeft'] ?? null) === 10
        && ($overview->json['emailHint'] ?? '') === $editorEmail[0].'•••@example.org' && !str_contains($overview->body, $local));
});

scenario('sessions that only ever proved the password end; the one that turned it on does not', function () use ($early, $owner) {
    recheck_now($early);
    recheck_now($owner);
    $ended = $early->get('/api/files/list', ['path' => '/']);
    check('a session signed in earlier with the password alone is signed out', $ended->status === 401, $ended->describe());
    check('the one that proved the address carries on', $owner->get('/api/files/list', ['path' => '/'])->status === 200);
});

/* ---- signing in with it ----------------------------------------------------------- */

scenario('the password alone no longer signs in, anywhere', function () use ($base, $editor, $editorPass, $editorEmail) {
    $c = new Client($base);
    $c->get('/api/auth/status');
    $cookieBefore = $c->cookie('cloudhub_session');
    $before = count(mails());
    $login = $c->post('/api/auth/login', ['username' => $editor, 'password' => $editorPass]);
    check('the right password is answered 401 TWO_FACTOR_REQUIRED', $login->status === 401 && $login->errorCode() === 'TWO_FACTOR_REQUIRED', $login->describe());
    check('in the error envelope an older client shows as is', is_string($login->json['error']['message'] ?? null) && ($login->json['success'] ?? null) === false
        && str_contains((string)$login->json['error']['message'], 'Enter the code sent to your email'));
    check('with what the code step needs, and the address masked', ($login->json['twoFactor']['emailHint'] ?? '') === $editorEmail[0].'•••@example.org'
        && ($login->json['twoFactor']['codeLength'] ?? 0) === 6 && ($login->json['twoFactor']['emailAvailable'] ?? null) === true
        && !str_contains($login->body, explode('@', $editorEmail)[0]));
    check('no email is sent until it is asked for', count(mails()) === $before);
    check('the session id was replaced', $c->cookie('cloudhub_session') !== null && $c->cookie('cloudhub_session') !== $cookieBefore);

    foreach ([
        'the file list' => fn() => $c->get('/api/files/list', ['path' => '/']),
        'a download' => fn() => $c->get('/api/files/download', ['path' => '/']),
        'an upload' => fn() => $c->post('/api/uploads/init', ['targetPath' => '/', 'name' => 'x.txt', 'size' => 1, 'uploadId' => 'tfhttpxxxxxxxxxx1']),
        'a share link' => fn() => $c->post('/api/shares/create', ['filePath' => '/x.txt']),
        'the password change' => fn() => $c->post('/api/users/me/password', ['currentPassword' => $editorPass, 'newPassword' => $editorPass.'x']),
        'the two-step settings' => fn() => $c->get('/api/users/me/two-factor'),
        'turning two-step off' => fn() => $c->post('/api/users/me/two-factor/start', ['action' => 'disable', 'currentPassword' => $editorPass]),
        'the Users list' => fn() => $c->get('/api/users'),
        'signing out' => fn() => $c->post('/api/auth/logout'),
        'WebDAV' => fn() => $c->dav('PROPFIND', '/webdav/', ['Depth: 1']),
        'the player' => fn() => $c->get('/play', ['path' => '/x.mp4']),
    ] as $what => $request) {
        $r = $request();
        check("while waiting for the code, $what is refused", $r->status === 401, $what.': '.$r->describe());
    }
    $status = $c->get('/api/auth/status');
    check('status: not signed in, but waiting for a code', ($status->json['authenticated'] ?? null) === false && isset($status->json['twoFactor'])
        && array_key_exists('user', $status->json ?? []) && $status->json['user'] === null);
});

scenario('signing in with the emailed code', function () use ($base, $editor, $editorPass, $editorEmail) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $noToken = $c->postWithoutCsrf('/api/auth/two-factor/send');
    check('asking for a code needs the CSRF token', $noToken->status === 419, $noToken->describe());
    $crossSite = $c->post('/api/auth/two-factor/send', [], ['Sec-Fetch-Site: cross-site']);
    check('and is refused from another site', $crossSite->status === 403, $crossSite->describe());
    $before = count(mails());
    $send = $c->post('/api/auth/two-factor/send');
    check('a code is sent, for five minutes, with a minute before another', $send->ok() && ($send->json['sent'] ?? false) === true
        && ($send->json['expiresIn'] ?? 0) === 300 && ($send->json['resendIn'] ?? 0) === 60 && count(mails()) === $before + 1, $send->describe());
    $mail = last_mail();
    check('to the account\'s address, as a sign-in code', ($mail['to'] ?? null) === [$editorEmail]
        && header_of($mail, 'Subject') === 'Your CloudHubTest sign-in code' && str_contains(body_of($mail), 'It expires in 5 minutes'));
    $again = $c->post('/api/auth/two-factor/send');
    check('asking again at once is refused, with Retry-After', $again->status === 429 && $again->errorCode() === 'TWO_FACTOR_RESEND_COOLDOWN'
        && (int)$again->header('Retry-After') > 50 && count(mails()) === $before + 1, $again->describe());
    $noTokenVerify = $c->postWithoutCsrf('/api/auth/two-factor/verify', ['code' => code_of($mail)]);
    check('checking a code needs the CSRF token too', $noTokenVerify->status === 419);
    $bad = $c->post('/api/auth/two-factor/verify', ['code' => code_of($mail) === '111111' ? '111112' : '111111']);
    check('a wrong code is refused', $bad->status === 422 && $bad->errorCode() === 'TWO_FACTOR_CODE_INVALID', $bad->describe());
    check('and still nothing is reachable', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    $cookieBefore = $c->cookie('cloudhub_session');
    $ok = $c->post('/api/auth/two-factor/verify', ['code' => code_of($mail)]);
    check('the right code signs in', $ok->status === 200 && ($ok->json['success'] ?? false) === true && ($ok->json['user']['username'] ?? '') === $editor, $ok->describe());
    check('with another new session id', $c->cookie('cloudhub_session') !== $cookieBefore);
    check('and everything works', $c->get('/api/files/list', ['path' => '/'])->status === 200
        && $c->dav('PROPFIND', '/webdav/', ['Depth: 1'])->status === 207);
    $replay = $c->post('/api/auth/two-factor/verify', ['code' => code_of($mail)]);
    check('the code cannot be used again', $replay->status === 401, $replay->describe());
    $elsewhere = new Client($base);
    $elsewhere->get('/api/auth/status');
    $stolen = $elsewhere->post('/api/auth/two-factor/verify', ['code' => code_of($mail)]);
    check('nor by a session that never gave the password', $stolen->status === 401 && $elsewhere->get('/api/files/list', ['path' => '/'])->status === 401);
});

scenario('expired, exhausted and replaced codes', function () use ($base, $db, $editor, $editorPass, $ids) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $c->post('/api/auth/two-factor/send');
    $code = code_of(last_mail());
    $db->prepare("UPDATE two_factor_challenges SET expires_at = ? WHERE user_id = ? AND purpose = 'login'")
        ->execute([gmdate('Y-m-d H:i:s', time() - 1), $ids['editor']]);
    $late = $c->post('/api/auth/two-factor/verify', ['code' => $code]);
    check('a code past its time is refused', $late->status === 410 && $late->errorCode() === 'TWO_FACTOR_CODE_EXPIRED', $late->describe());

    $c2 = new Client($base);
    $c2->signIn($editor, $editorPass);
    $c2->post('/api/auth/two-factor/send');
    $code = code_of(last_mail());
    $wrong = $code === '222222' ? '222223' : '222222';
    $answers = [];
    for ($i = 0; $i < 6; $i++) $answers[] = $c2->post('/api/auth/two-factor/verify', ['code' => $wrong])->errorCode();
    check('five wrong guesses use the code up', $answers[4] === 'TWO_FACTOR_CODE_INVALID' && $answers[5] === 'TWO_FACTOR_CODE_EXHAUSTED', implode(',', $answers));
    $right = $c2->post('/api/auth/two-factor/verify', ['code' => $code]);
    check('after which the right one is refused too', $right->status === 410 && $c2->get('/api/files/list', ['path' => '/'])->status === 401, $right->describe());
    check('each wrong guess was counted', (int)$db->query("SELECT COUNT(*) FROM security_events WHERE event_type = 'auth.two_factor' AND outcome = 'failure' AND user_id = ".(int)$ids['editor'])->fetchColumn() >= 5);

    $first = new Client($base);
    $first->signIn($editor, $editorPass);
    $first->post('/api/auth/two-factor/send');
    $firstCode = code_of(last_mail());
    $later = new Client($base);
    $later->signIn($editor, $editorPass);
    $replaced = $first->post('/api/auth/two-factor/verify', ['code' => $firstCode]);
    check('a later sign-in replaces an earlier one\'s code', $replaced->status === 410 && $first->get('/api/files/list', ['path' => '/'])->status === 401, $replaced->describe());
});

scenario('a code for one purpose is no good for another', function () use ($base, $editor, $editorPass) {
    $signedIn = new Client($base);
    sign_in_with_code($signedIn, $editor, $editorPass);
    $change = $signedIn->post('/api/users/me/two-factor/start', ['action' => 'recovery', 'currentPassword' => $editorPass]);
    check('a change emails its own code', ($change->json['sent'] ?? false) === true, $change->describe());
    $changeCode = code_of(last_mail());
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $r = $c->post('/api/auth/two-factor/verify', ['code' => $changeCode]);
    check('which does not sign anyone in', $r->status !== 200 && $c->get('/api/files/list', ['path' => '/'])->status === 401, $r->describe());
    $signedIn->post('/api/users/me/two-factor/cancel');
    check('and a cancelled change\'s code confirms nothing', $signedIn->post('/api/users/me/two-factor/confirm', ['code' => $changeCode])->status === 409);
});

scenario('recovery codes sign in once each', function () use ($base, $editor, $editorPass, &$recovery) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $before = count(mails());
    $r = $c->post('/api/auth/two-factor/verify', ['recoveryCode' => strtoupper(str_replace('-', ' ', (string)($recovery[0] ?? '')))]);
    check('a recovery code, typed loosely, signs in', $r->status === 200 && ($r->json['recoveryCodesLeft'] ?? null) === 9, $r->describe());
    check('with no email sent', count(mails()) === $before);
    $c2 = new Client($base);
    $c2->signIn($editor, $editorPass);
    $again = $c2->post('/api/auth/two-factor/verify', ['recoveryCode' => (string)($recovery[0] ?? '')]);
    check('the same one is refused the second time', $again->status === 422 && $again->errorCode() === 'TWO_FACTOR_RECOVERY_INVALID', $again->describe());
    $both = $c2->post('/api/auth/two-factor/verify', ['code' => '123456', 'recoveryCode' => (string)($recovery[1] ?? '')]);
    check('a code and a recovery code together is refused', $both->status === 422);
    $c2->post('/api/auth/two-factor/cancel');
    check('cancelling ends the wait', $c2->post('/api/auth/two-factor/verify', ['recoveryCode' => (string)($recovery[1] ?? '')])->status === 401);
});

scenario('racing requests: one code checked once, one email sent once', function () use ($base, $editor, $editorPass) {
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    $before = count(mails());
    $sends = $c->parallelPost('/api/auth/two-factor/send', [], 5);
    $sendStatuses = array_map(static fn(Response $r): int => $r->status, $sends);
    check('five requests for a code at once send exactly one email', count(mails()) === $before + 1
        && count(array_filter($sendStatuses, static fn(int $s): bool => $s === 200)) === 1
        && count(array_filter($sends, static fn(Response $r): bool => $r->errorCode() === 'TWO_FACTOR_RESEND_COOLDOWN')) === 4, implode(',', $sendStatuses));
    $answers = $c->parallelPost('/api/auth/two-factor/verify', ['code' => code_of(last_mail())], 5);
    $statuses = array_map(static fn(Response $r): int => $r->status, $answers);
    check('exactly one of five racing requests with the right code signs in', count(array_filter($statuses, static fn(int $s): bool => $s === 200)) === 1, implode(',', $statuses));
});

/* ---- the mail server misbehaving ------------------------------------------------------ */

scenario('a mail server that fails, refuses, stalls or hangs up never lets anyone in', function () use ($base, $db, $editor, $editorPass, $editorEmail, $ids, $root, $smtpPassword) {
    // config/bootstrap.php sends PHP's error log to logs/php-error.log; what
    // this scenario adds to it is read at the end.
    $errorLog = $root.'/logs/php-error.log';
    clearstatcache();
    $logFrom = is_file($errorLog) ? (int)filesize($errorLog) : 0;
    $c = new Client($base);
    $c->signIn($editor, $editorPass);
    // Each try waits out the resend cooldown rather than sixty real seconds.
    $clearCooldown = static function () use ($db, $ids): void {
        $db->prepare("UPDATE two_factor_challenges SET sent_at = NULL WHERE purpose = 'login' AND user_id = ?")->execute([$ids['editor']]);
    };
    $before = count(mails());
    sink('tempfail');
    $down = $c->post('/api/auth/two-factor/send');
    check('a server in trouble is a 503 that says so, with a wait', $down->status === 503 && $down->errorCode() === 'EMAIL_UNAVAILABLE'
        && (int)$down->header('Retry-After') > 0, $down->describe());
    check('and the session is still signed out', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    foreach (['relay' => 'relaying denied', 'authfail' => 'refused credentials', 'close' => 'a server that hangs up', 'garbage' => 'something that is not SMTP'] as $mode => $what) {
        sink($mode);
        $clearCooldown();
        $r = $c->post('/api/auth/two-factor/send');
        check("$what is a 503 too", $r->status === 503 && $r->errorCode() === 'EMAIL_UNAVAILABLE', $mode.': '.$r->describe());
    }
    sink('reject');
    $clearCooldown();
    $refused = $c->post('/api/auth/two-factor/send');
    check('a refused address is a 422 that says so', $refused->status === 422 && $refused->errorCode() === 'EMAIL_REJECTED', $refused->describe());
    sink('slow');
    $clearCooldown();
    $started = microtime(true);
    $slow = $c->post('/api/auth/two-factor/send');
    check('a server past SMTP_TIMEOUT_SECONDS is given up on', $slow->status === 503 && microtime(true) - $started < 3.8, $slow->describe());
    sleep(3);   // the stand-in finishes its stall before it can answer again
    sink('ok');
    check('nothing was delivered', count(mails()) === $before);
    check('none of it signed the session in', $c->get('/api/files/list', ['path' => '/'])->status === 401
        && $c->post('/api/auth/two-factor/verify', ['code' => '123456'])->status !== 200 && $c->get('/api/files/list', ['path' => '/'])->status === 401);
    $clearCooldown();
    $recovered = $c->post('/api/auth/two-factor/send');
    check('once the server is back, a code is sent and works', $recovered->ok()
        && $c->post('/api/auth/two-factor/verify', ['code' => code_of(last_mail())])->ok(), $recovered->describe());
    // The operator is told the email failed -- which also proves this is the
    // log PHP writes to -- and never told the address, the code or the password.
    $errors = (string)@file_get_contents($errorLog, false, null, $logFrom);
    check('the error log says the email was not sent, and why', str_contains($errors, 'two-step code not sent: smtp: RCPT TO refused (451 4.3.x)')
        && str_contains($errors, 'two-step code not sent: smtp: RCPT TO refused (550 5.1.x)') && str_contains($errors, 'two-step code not sent: smtp: AUTH refused (535)'));
    check('but not to which address, nor the server\'s own words, nor the password', !str_contains($errors, explode('@', $editorEmail)[0])
        && !str_contains($errors, 'virtual mailbox table') && !str_contains($errors, $smtpPassword));
});

scenario('a server with no mail server asks for a recovery code instead', function () use ($url, $editor, $editorPass, &$recovery) {
    $c = new Client($url['quiet']);
    $login = $c->signIn($editor, $editorPass);
    check('the password still only gets as far as the code', $login->status === 401 && ($login->json['twoFactor']['emailAvailable'] ?? null) === false, $login->describe());
    $send = $c->post('/api/auth/two-factor/send');
    check('no code can be sent', $send->status === 503 && $send->errorCode() === 'EMAIL_NOT_CONFIGURED', $send->describe());
    check('nothing is reachable', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    $r = $c->post('/api/auth/two-factor/verify', ['recoveryCode' => (string)($recovery[2] ?? '')]);
    check('a recovery code still signs in', $r->status === 200, $r->describe());
    $overview = $c->get('/api/users/me/two-factor');
    check('and the settings say email cannot be sent', ($overview->json['emailAvailable'] ?? null) === false && ($overview->json['enabled'] ?? null) === true
        && ($overview->json['available'] ?? null) === false);
});

scenario('unencrypted SMTP to the internet is refused, and the operator told why', function () use ($url, $root, $viewer, $viewerPass, $smtpPassword) {
    $c = new Client($url['internet']);
    $c->signIn($viewer, $viewerPass);
    $overview = $c->get('/api/users/me/two-factor');
    check('nobody can turn it on there', ($overview->json['available'] ?? null) === false && ($overview->json['emailAvailable'] ?? null) === false, $overview->describe());
    $start = $c->post('/api/users/me/two-factor/start', ['action' => 'email', 'currentPassword' => $viewerPass, 'email' => 'v@example.org']);
    check('and trying says so', $start->status === 503 && $start->errorCode() === 'EMAIL_NOT_CONFIGURED', $start->describe());
    $log = (string)@file_get_contents($root.'/logs/php-error.log');
    check('the log names the setting at fault', str_contains($log, '[mail] SMTP_ENCRYPTION=none is allowed only for a mail server on this machine or the local network; email is off'));
    check('but not the password', !str_contains($log, $smtpPassword));
});

/* ---- STARTTLS ------------------------------------------------------------------- */

scenario('over STARTTLS, with a certificate the server trusts', function () use ($url, $accounts, $address, $smtpPassword) {
    [$name, $password] = $accounts['tls'];
    $email = $address('tls');
    $GLOBALS['addresses'][] = $email;
    $c = new Client($url['tls']);
    $c->signIn($name, $password);
    $before = count(mails('tlssink'));
    $on = enable($c, $password, $email, 'tlssink');
    check('the code arrives, and turns it on', ($on['enabled'] ?? false) === true && count(mails('tlssink')) === $before + 1, json_encode($on));
    $mail = last_mail('tlssink');
    check('encrypted before the credentials and the message were sent', ($mail['tls'] ?? false) === true
        && ($mail['auth']['pass'] ?? '') === $smtpPassword && ($mail['to'] ?? null) === [$email]);
    $talk = conversations('tlssink');
    $last = $talk[count($talk) - 1] ?? [];
    check('STARTTLS came first, then EHLO again', array_slice($last['commands'] ?? [], 0, 3) === ['EHLO cloudhub.test', 'STARTTLS', 'EHLO cloudhub.test']
        && ($last['authInClear'] ?? true) === false);
    $d = new Client($url['tls']);
    check('and signing in works the same way', sign_in_with_code($d, $name, $password, 'tlssink')->ok() && ($d->get('/api/files/list', ['path' => '/'])->status === 200));
});

scenario('over STARTTLS, nothing is sent to a server that cannot prove who it is', function () use ($url, $accounts, $address) {
    [$name, $password] = $accounts['viewer'];
    foreach (['untrusted' => 'a certificate from a CA this server does not trust', 'wrongname' => 'a certificate for another name',
              'nostarttls' => 'a server that does not offer STARTTLS'] as $server => $what) {
        $which = $server === 'nostarttls' ? 'sink' : 'tlssink';
        $mailsBefore = count(mails($which));
        $talksBefore = count(conversations($which));
        $c = new Client($url[$server]);
        $c->signIn($name, $password);
        $start = $c->post('/api/users/me/two-factor/start', ['action' => 'email', 'currentPassword' => $password, 'email' => $address('viewer')]);
        check("$what: the code is not sent", $start->ok() && ($start->json['sent'] ?? null) === false
            && ($start->json['error']['code'] ?? '') === 'EMAIL_UNAVAILABLE' && count(mails($which)) === $mailsBefore, $start->describe());
        usleep(200_000);
        $talk = array_slice(conversations($which), $talksBefore);
        $sentAnything = array_filter($talk, static fn(array $t): bool => (bool)preg_grep('/^(AUTH|MAIL|RCPT|DATA)/', $t['commands'] ?? []));
        check("$what: and neither are the credentials, nor the address", count($talk) === 1 && $sentAnything === [], json_encode($talk));
        $c->post('/api/users/me/two-factor/cancel');
    }
});

/* ---- an account from text-message days ---------------------------------------- */

scenario('an account that had text-message codes on keeps a second step, by recovery code', function () use ($base, $db, $accounts, $ids, $address) {
    [$name, $password] = $accounts['legacy'];
    $id = (int)$ids['legacy'];
    // As the update leaves it: on, with no address. two_factor_phone, where a
    // database still has it, plays no part.
    $db->prepare('UPDATE users SET two_factor_enabled_at = UTC_TIMESTAMP(), two_factor_email = NULL WHERE id = ?')->execute([$id]);
    $codes = [TwoFactor::generateRecoveryCode(), TwoFactor::generateRecoveryCode()];
    $insert = $db->prepare('INSERT INTO two_factor_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())');
    foreach ($codes as $code) $insert->execute([$id, TwoFactor::recoveryHash($id, $code)]);
    $c = new Client($base);
    $login = $c->signIn($name, $password);
    check('the password alone does not sign it in', $login->status === 401 && $login->errorCode() === 'TWO_FACTOR_REQUIRED'
        && ($login->json['twoFactor']['emailAvailable'] ?? null) === false && array_key_exists('emailHint', $login->json['twoFactor'] ?? [])
        && $login->json['twoFactor']['emailHint'] === null, $login->describe());
    $before = count(mails());
    $send = $c->post('/api/auth/two-factor/send');
    check('no code can be emailed: there is no address', $send->status === 409 && $send->errorCode() === 'TWO_FACTOR_NO_EMAIL' && count(mails()) === $before, $send->describe());
    check('nothing is reachable', $c->get('/api/files/list', ['path' => '/'])->status === 401);
    $in = $c->post('/api/auth/two-factor/verify', ['recoveryCode' => $codes[0]]);
    check('a recovery code signs it in', $in->ok() && ($in->json['recoveryCodesLeft'] ?? null) === 1, $in->describe());
    $email = $address('legacy');
    $GLOBALS['addresses'][] = $email;
    $start = $c->post('/api/users/me/two-factor/start', ['action' => 'email', 'currentPassword' => $password, 'email' => $email]);
    check('it adds an address with that address\'s code alone, having just signed in', ($start->json['stage'] ?? '') === 'new'
        && ($start->json['sent'] ?? false) === true && (last_mail()['to'] ?? null) === [$email], $start->describe());
    $done = $c->post('/api/users/me/two-factor/confirm', ['code' => code_of(last_mail())]);
    check('which keeps it on, and its recovery code', ($done->json['done'] ?? false) === true && ($done->json['enabled'] ?? false) === true
        && array_key_exists('recoveryCodes', $done->json ?? []) && $done->json['recoveryCodes'] === null
        && ($done->json['recoveryCodesLeft'] ?? null) === 1, $done->describe());
    check('after which signing in takes the emailed code', sign_in_with_code(new Client($base), $name, $password)->ok());
});

/* ---- an older client ------------------------------------------------------------- */

scenario('a client that knows nothing of two-step verification', function () use ($base, $editor, $editorPass) {
    // What the Android app shipped before 4.3 did: POST login, and read
    // `success` and `csrfToken` from a 2xx, or the error message from anything else.
    $before = count(mails());
    $c = new Client($base);
    $r = $c->post('/api/auth/login', ['username' => $editor, 'password' => $editorPass]);
    check('is not told it succeeded', !$r->ok() && ($r->json['success'] ?? false) === false);
    check('gets a message it can show', str_contains((string)($r->json['error']['message'] ?? ''), 'two-step verification'));
    check('costs no email', count(mails()) === $before);
    check('and is not signed in', $c->get('/api/files/list', ['path' => '/'])->status === 401);
});

/* ---- changing it ------------------------------------------------------------------- */

scenario('turning it off needs the current address, or a recovery code', function () use ($base, $second, $secondPass, $address) {
    $c = new Client($base);
    $c->signIn($second, $secondPass);
    $email = $address('second');
    $GLOBALS['addresses'][] = $email;
    $on = enable($c, $secondPass, $email);
    check('it is on', ($on['enabled'] ?? false) === true);
    $start = $c->post('/api/users/me/two-factor/start', ['action' => 'disable', 'currentPassword' => $secondPass]);
    check('turning it off emails the current address', ($start->json['stage'] ?? '') === 'current' && ($start->json['sent'] ?? false) === true
        && (last_mail()['to'] ?? null) === [$email] && str_contains(body_of(last_mail()), 'to turn off two-step verification'), $start->describe());
    $off = $c->post('/api/users/me/two-factor/confirm', ['code' => code_of(last_mail())]);
    check('whose code turns it off', ($off->json['enabled'] ?? null) === false, $off->describe());
    check('and the address is told', (last_mail()['to'] ?? null) === [$email] && str_contains(body_of(last_mail()), 'was turned off'));
    $plain = new Client($GLOBALS['base']);
    check('the password alone signs in again', $plain->signIn($second, $secondPass)->ok());

    $on = enable($c, $secondPass, $email);
    $codes = $on['recoveryCodes'] ?? [];
    $before = count(mails());
    $lost = $c->post('/api/users/me/two-factor/start', ['action' => 'disable', 'currentPassword' => $secondPass, 'method' => 'recovery']);
    check('with the mailbox out of reach, nothing is emailed', ($lost->json['sent'] ?? true) === false && count(mails()) === $before, $lost->describe());
    $off = $c->post('/api/users/me/two-factor/confirm', ['recoveryCode' => (string)($codes[0] ?? '')]);
    check('and a recovery code turns it off', ($off->json['enabled'] ?? null) === false, $off->describe());
});

scenario('an administrator can reset it, and only that way round', function () use ($base, $editor, $editorPass, $viewer, $viewerPass, $admin, $adminPass, $editorEmail, $ids) {
    $v = new Client($base);
    $v->signIn($viewer, $viewerPass);
    $refused = $v->delete('/api/users/'.$ids['editor'].'/two-factor', ['currentPassword' => $viewerPass]);
    check('a viewer cannot reset anyone\'s', $refused->status === 403, $refused->describe());
    $a = new Client($base);
    check('the administrator signs in', $a->signIn($admin, $adminPass)->ok());
    $list = $a->get('/api/users');
    $row = array_values(array_filter($list->json ?? [], static fn(array $u): bool => $u['username'] === $editor))[0] ?? [];
    check('the Users list says who has it on', ($row['twoFactorEnabled'] ?? null) === true && !str_contains($list->body, explode('@', $editorEmail)[0]));
    $self = $a->delete('/api/users/'.$ids['admin'].'/two-factor', ['currentPassword' => $adminPass]);
    check('not for their own account', $self->status === 409, $self->describe());
    $wrong = $a->delete('/api/users/'.$ids['editor'].'/two-factor', ['currentPassword' => 'not-the-password-1']);
    check('not without their own password', $wrong->status === 403, $wrong->describe());
    // CloudHub has no self-service password reset; an administrator setting a
    // new password is the nearest thing, and must not be a way round the code.
    $newPassword = $editorPass.'-reset';
    $set = $a->patch('/api/users/'.$ids['editor'], ['password' => $newPassword]);
    $afterReset = (new Client($base))->signIn($editor, $newPassword);
    check('a password set by an administrator still needs the code', $set->ok() && $afterReset->status === 401
        && $afterReset->errorCode() === 'TWO_FACTOR_REQUIRED', $set->describe().' / '.$afterReset->describe());
    check('and the password is put back', $a->patch('/api/users/'.$ids['editor'], ['password' => $editorPass])->ok());
    $before = count(mails());
    $reset = $a->delete('/api/users/'.$ids['editor'].'/two-factor', ['currentPassword' => $adminPass]);
    check('a reset turns it off', $reset->ok(), $reset->describe());
    check('and emails the owner', count(mails()) === $before + 1 && (last_mail()['to'] ?? null) === [$editorEmail]
        && str_contains(body_of(last_mail()), 'An administrator turned off'));
    $events = $a->get('/api/security/events', ['limit' => 200])->body;
    check('and is in the audit trail, with no code or address in it', str_contains($events, 'two_factor.admin_reset')
        && !str_contains($events, $editorEmail));
});

scenario('after the reset the password alone signs in again', function () use ($base, $editor, $editorPass) {
    $c = new Client($base);
    $r = $c->signIn($editor, $editorPass);
    check('as it did before it was turned on', $r->status === 200 && ($r->json['success'] ?? false) === true, $r->describe());
});

scenario('the command-line reset, for when nobody can do it from the web', function () use ($base, $root, $db, $second, $secondPass, $address, $ids) {
    $c = new Client($base);
    $c->signIn($second, $secondPass);
    check('it is on', (enable($c, $secondPass, $address('second'))['enabled'] ?? false) === true);
    $run = static function (string $user) use ($root): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/reset-two-factor.php').' '.escapeshellarg($user).' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    };
    [$code, $out] = $run($second);
    check('the tool turns it off', $code === 0 && str_contains($out, 'is off for'), $out);
    $row = $db->prepare('SELECT two_factor_email, two_factor_enabled_at FROM users WHERE id = ?');
    $row->execute([$ids['second']]);
    $state = $row->fetch();
    $left = $db->prepare('SELECT COUNT(*) FROM two_factor_recovery_codes WHERE user_id = ?');
    $left->execute([$ids['second']]);
    check('taking the address and the recovery codes with it', $state['two_factor_email'] === null && $state['two_factor_enabled_at'] === null
        && (int)$left->fetchColumn() === 0);
    check('after which the password alone signs in', (new Client($base))->signIn($second, $secondPass)->ok());
    [$code, $out] = $run($second);
    check('running it again says so, and succeeds', $code === 0 && str_contains($out, 'already off'), $out);
    [$code] = $run('no-such-account-'.bin2hex(random_bytes(3)));
    check('an unknown account is an error', $code === 1);
});

scenario('codes, addresses and the SMTP password stay out of the logs and the trail', function () use ($root, $db, $work, $smtpPassword) {
    $codes = array_values(array_filter(array_map('code_of', array_merge(mails(), mails('tlssink')))));
    $logs = (string)@file_get_contents($root.'/logs/php-error.log');
    foreach (glob($work.'/*.log') ?: [] as $serverLog) $logs .= (string)@file_get_contents($serverLog);
    $trail = json_encode($db->query("SELECT context_json FROM security_events WHERE event_type LIKE '%two_factor%' OR event_type LIKE 'auth.%' ORDER BY id DESC LIMIT 500")->fetchAll(), JSON_UNESCAPED_UNICODE);
    $addresses = $GLOBALS['addresses'];
    check('there were codes and addresses to look for', count($codes) >= 10 && count($addresses) >= 4);
    check('no address in the logs', array_filter($addresses, static fn(string $a): bool => stripos($logs, $a) !== false) === []);
    check('no code in the logs', array_filter($codes, static fn(string $c): bool => (bool)preg_match('/(?<![0-9A-Za-z])'.$c.'(?![0-9A-Za-z])/', $logs)) === []);
    check('no SMTP password in the logs', !str_contains($logs, $smtpPassword));
    check('no code in the audit trail', array_filter($codes, static fn(string $c): bool => str_contains((string)$trail, '"'.$c.'"')) === []);
    check('no address in the audit trail, only masked ones', array_filter($addresses, static fn(string $a): bool => stripos((string)$trail, $a) !== false) === []
        && str_contains((string)$trail, '•••@example.org'));
});

/* ---- report ----------------------------------------------------------------- */

$sent = count(mails()) + count(mails('tlssink'));
echo PHP_EOL.$passed.' checks passed, '.count($failures).' failed ('.$sent.' emails through the stand-in SMTP servers).'.PHP_EOL;
foreach ($failures as $failure) echo '  FAIL '.$failure.PHP_EOL;
exit($failures ? 1 : 0);
