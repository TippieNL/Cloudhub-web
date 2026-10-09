<?php
declare(strict_types=1);

/**
 * A stand-in SMTP server for tests/http/two_factor_run.php.
 *
 *   php tests/http/smtp_sink.php <port> <dir> [<cert.pem> <key.pem>]
 *
 * Listens on 127.0.0.1:<port>, one connection at a time, and speaks enough
 * ESMTP for a submission client: EHLO, AUTH PLAIN and LOGIN, MAIL, RCPT, DATA,
 * QUIT -- and STARTTLS when given a certificate, after which the conversation
 * really is encrypted. Every message it accepts is appended to
 * <dir>/received.jsonl (sender, recipient, the message as sent, whether the
 * connection was encrypted, the credentials), which is how the suite reads
 * codes. Every conversation is appended to <dir>/sessions.jsonl, with
 * credentials left out, so the suite can see what a client sent before it
 * gave up.
 *
 * <dir>/mode, rewritten by the suite between requests, says how to behave:
 *   ok        accept everything
 *   tempfail  451 4.3.0 at RCPT TO: the server is having trouble
 *   reject    550 5.1.1 at RCPT TO, quoting the address as real servers do
 *   relay     550 5.7.1 at RCPT TO: relaying denied, a configuration problem
 *   authfail  535 for the credentials
 *   close     hang up before saying anything
 *   slow      say nothing for four seconds, longer than the suite's timeout
 *   garbage   answer like a web server
 */
if (PHP_SAPI !== 'cli') exit;

[$port, $dir, $cert, $key] = [(int)($argv[1] ?? 0), (string)($argv[2] ?? ''), (string)($argv[3] ?? ''), (string)($argv[4] ?? '')];
if ($port < 1 || !is_dir($dir)) { fwrite(STDERR, "usage: smtp_sink.php <port> <dir> [<cert> <key>]\n"); exit(1); }
$tlsOffered = $cert !== '' && $key !== '';
$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => $tlsOffered ? ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false] : []]));
if ($server === false) { fwrite(STDERR, "cannot listen on $port: $errstr\n"); exit(1); }
file_put_contents($dir.'/ready', (string)getmypid());

while (true) {
    $conn = @stream_socket_accept($server, 3600);
    if ($conn === false) continue;
    stream_set_timeout($conn, 10);
    $record = ['mode' => trim((string)@file_get_contents($dir.'/mode')) ?: 'ok', 'commands' => [], 'tls' => false,
        'authInClear' => false, 'tlsFailed' => false];
    try {
        converse($conn, $record, $dir, $tlsOffered);
    } catch (Throwable $e) {
        $record['error'] = $e->getMessage();
    }
    @fclose($conn);
    file_put_contents($dir.'/sessions.jsonl', json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
}

/** @param resource $conn */
function converse($conn, array &$record, string $dir, bool $tlsOffered): void
{
    $mode = $record['mode'];
    if ($mode === 'close') return;
    if ($mode === 'slow') sleep(4);
    if ($mode === 'garbage') { fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"); return; }
    $say = static function (string $reply) use ($conn): void { @fwrite($conn, $reply."\r\n"); };
    $say('220 sink.test ESMTP ready');
    $auth = null;
    $from = '';
    $to = [];
    while (($line = fgets($conn, 8192)) !== false) {
        $line = rtrim($line, "\r\n");
        $words = explode(' ', $line);
        $verb = strtoupper($words[0]);
        // Credentials are kept out of the transcript; what was sent is recorded with the message.
        $record['commands'][] = $verb === 'AUTH' ? 'AUTH '.strtoupper($words[1] ?? '') : $line;
        if ($verb === 'EHLO' || $verb === 'HELO') {
            $lines = ['sink.test'];
            if ($tlsOffered && !$record['tls']) $lines[] = 'STARTTLS';
            $lines[] = 'AUTH PLAIN LOGIN';
            $lines[] = '8BITMIME';
            foreach ($lines as $i => $text) $say('250'.($i === count($lines) - 1 ? ' ' : '-').$text);
        } elseif ($verb === 'STARTTLS' && $tlsOffered && !$record['tls']) {
            $say('220 2.0.0 Ready to start TLS');
            if (@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) !== true) {
                $record['tlsFailed'] = true;
                return;
            }
            $record['tls'] = true;
        } elseif ($verb === 'AUTH') {
            $mechanism = strtoupper($words[1] ?? '');
            if (!$record['tls']) $record['authInClear'] = true;
            if ($mechanism === 'PLAIN') {
                $parts = explode("\0", (string)base64_decode($words[2] ?? ''));
                $auth = ['mechanism' => 'PLAIN', 'user' => $parts[1] ?? '', 'pass' => $parts[2] ?? ''];
            } elseif ($mechanism === 'LOGIN') {
                $say('334 VXNlcm5hbWU6');
                $user = base64_decode(rtrim((string)fgets($conn, 1024), "\r\n"));
                $say('334 UGFzc3dvcmQ6');
                $auth = ['mechanism' => 'LOGIN', 'user' => $user, 'pass' => base64_decode(rtrim((string)fgets($conn, 1024), "\r\n"))];
            } else {
                $say('504 5.5.4 Unrecognized authentication type');
                continue;
            }
            $say($mode === 'authfail' ? '535 5.7.8 Username and Password not accepted' : '235 2.7.0 Accepted');
        } elseif ($verb === 'MAIL') {
            $from = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            $to = [];
            $say('250 2.1.0 OK');
        } elseif ($verb === 'RCPT') {
            $address = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            match ($mode) {
                'tempfail' => $say('451 4.3.0 Mail server temporarily rejected message'),
                'reject' => $say("550 5.1.1 <$address>: Recipient address rejected: User unknown in virtual mailbox table"),
                'relay' => $say("550 5.7.1 <$address>: Relay access denied"),
                default => (function () use ($say, &$to, $address): void { $to[] = $address; $say('250 2.1.5 OK'); })(),
            };
        } elseif ($verb === 'DATA') {
            if ($to === []) { $say('554 5.5.1 No valid recipients'); continue; }
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($l = fgets($conn, 8192)) !== false) {
                if ($l === ".\r\n") break;
                $data .= str_starts_with($l, '.') ? substr($l, 1) : $l;
            }
            file_put_contents($dir.'/received.jsonl', json_encode(['from' => $from, 'to' => $to, 'data' => $data,
                'tls' => $record['tls'], 'auth' => $auth], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
            $say('250 2.0.0 Ok: queued as SINK'.random_int(1000, 9999));
        } elseif ($verb === 'RSET' || $verb === 'NOOP') {
            $say('250 2.0.0 OK');
        } elseif ($verb === 'QUIT') {
            $say('221 2.0.0 Bye');
            return;
        } else {
            $say('502 5.5.2 Error: command not recognized');
        }
    }
}
