<?php
declare(strict_types=1);

namespace CloudHub\Services\Mail;

/**
 * Plain-text mail through an SMTP server: the provider's submission port
 * (587 with STARTTLS, or 465 with TLS from the first byte), or a relay on
 * this machine or the local network.
 *
 * Encryption is never optional once asked for: with 'tls' a server that does
 * not offer STARTTLS gets nothing, not the credentials and not the message.
 * 'none' is accepted only for a host on this machine or the local network
 * (Mail::problem()). AUTH PLAIN is preferred, LOGIN used where it is all
 * there is.
 *
 * Errors name the step and the status code only. A server's reply can quote
 * the address it refused, so its text is never passed on or logged.
 */
final class SmtpMailer implements MailSender
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly SmtpConnection $connection,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function name(): string
    {
        return 'smtp';
    }

    public function send(string $to, string $subject, string $body): string
    {
        if (preg_match('/[\r\n<>]/', $to) === 1) throw new MailException(MailException::REJECTED, 'smtp: unusable address');
        $messageId = bin2hex(random_bytes(16)).'@'.$this->fromDomain();
        $this->connection->open($this->host, $this->port, $this->encryption === 'ssl', $this->timeoutSeconds);
        try {
            $this->expect(220, 'greeting');
            $capabilities = $this->hello();
            if ($this->encryption === 'tls') {
                if (!isset($capabilities['STARTTLS'])) {
                    throw new MailException(MailException::UNAVAILABLE, 'smtp: '.$this->host.' does not offer STARTTLS, so nothing was sent');
                }
                $this->command('STARTTLS', 220, 'STARTTLS');
                $this->connection->enableTls();
                $capabilities = $this->hello();
            }
            if ($this->username !== '') $this->authenticate($capabilities);
            $this->command('MAIL FROM:<'.$this->fromAddress.'>', 250, 'MAIL FROM');
            $this->recipient($to);
            $this->command('DATA', 354, 'DATA');
            $this->connection->writeRaw($this->message($to, $subject, $body, $messageId));
            $this->expect(250, 'message');
            // Accepted. A failed goodbye changes nothing.
            try {
                $this->connection->writeLine('QUIT');
                $this->reply();
            } catch (MailException) {
            }
        } finally {
            $this->connection->close();
        }
        return $messageId;
    }

    /** EHLO, and what the server says it can do: keyword => its parameters. */
    private function hello(): array
    {
        $this->connection->writeLine('EHLO '.$this->fromDomain());
        [$code, $lines] = $this->reply();
        if ($code !== 250) throw new MailException(MailException::UNAVAILABLE, "smtp: EHLO refused ($code)");
        $capabilities = [];
        foreach (array_slice($lines, 1) as $line) {
            $words = preg_split('/[\s=]+/', trim($line), 2) ?: [];
            if (($words[0] ?? '') !== '') $capabilities[strtoupper($words[0])] = strtoupper($words[1] ?? '');
        }
        return $capabilities;
    }

    private function authenticate(array $capabilities): void
    {
        $mechanisms = preg_split('/\s+/', $capabilities['AUTH'] ?? '') ?: [];
        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command('AUTH PLAIN '.base64_encode("\0".$this->username."\0".$this->password), 235, 'AUTH');
            return;
        }
        if (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN', 334, 'AUTH');
            $this->command(base64_encode($this->username), 334, 'AUTH');
            $this->command(base64_encode($this->password), 235, 'AUTH');
            return;
        }
        throw new MailException(MailException::UNAVAILABLE, 'smtp: '.$this->host.' offers neither AUTH PLAIN nor AUTH LOGIN');
    }

    /**
     * RCPT TO is where a server refuses an address. 5.1.x is the address
     * itself -- no such mailbox, no such domain. 5.7.x is policy -- relaying
     * denied, authentication needed -- which is this server's configuration,
     * not the person's address, and so is reported as unavailable.
     */
    private function recipient(string $to): void
    {
        $this->connection->writeLine('RCPT TO:<'.$to.'>');
        [$code, $lines] = $this->reply();
        if ($code === 250 || $code === 251) return;
        $class = preg_match('/^([245])\.(\d{1,3})\.\d{1,3}\b/', $lines[0] ?? '', $m) === 1 ? $m[1].'.'.$m[2] : '';
        $refused = $code >= 500 && $class !== '5.7' && ($class === '5.1' || in_array($code, [550, 551, 553], true));
        throw new MailException($refused ? MailException::REJECTED : MailException::UNAVAILABLE,
            "smtp: RCPT TO refused ($code".($class !== '' ? " $class.x" : '').')');
    }

    private function command(string $line, int $expected, string $step): void
    {
        $this->connection->writeLine($line);
        $this->expect($expected, $step);
    }

    private function expect(int $expected, string $step): void
    {
        [$code] = $this->reply();
        if ($code !== $expected) throw new MailException(MailException::UNAVAILABLE, "smtp: $step refused ($code)");
    }

    /** @return array{0:int, 1:list<string>} the status and the text of each line */
    private function reply(): array
    {
        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $line = $this->connection->readLine();
            if (preg_match('/^([2-5]\d\d)(?:([ -])(.*))?$/s', $line, $m) !== 1) {
                throw new MailException(MailException::UNAVAILABLE, 'smtp: an answer that is not SMTP');
            }
            $lines[] = $m[3] ?? '';
            if (($m[2] ?? ' ') !== '-') return [(int)$m[1], $lines];
        }
        throw new MailException(MailException::UNAVAILABLE, 'smtp: an answer that does not end');
    }

    /** The message, dot-stuffed and ending with the line that ends DATA. */
    private function message(string $to, string $subject, string $body, string $messageId): string
    {
        $headers = [
            'Date: '.gmdate('D, d M Y H:i:s').' +0000',
            'From: '.$this->mailbox($this->fromName, $this->fromAddress),
            'To: <'.$to.'>',
            'Subject: '.self::encodeHeader($subject),
            'Message-ID: <'.$messageId.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            // An automatic message: no out-of-office replies, no auto-forward loops.
            'Auto-Submitted: auto-generated',
        ];
        $text = quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $body));
        $data = implode("\r\n", $headers)."\r\n\r\n".rtrim($text, "\r\n")."\r\n";
        // A line that starts with a dot gets a second one, or a lone "." in the
        // text would end the message early.
        return (string)preg_replace('/^\./m', '..', $data).".\r\n";
    }

    private function mailbox(string $name, string $address): string
    {
        $name = trim(str_replace(["\r", "\n"], '', $name));
        if ($name === '') return '<'.$address.'>';
        $display = preg_match('/^[\x20-\x7E]+$/', $name) === 1
            ? '"'.addcslashes($name, '"\\').'"'
            : '=?UTF-8?B?'.base64_encode($name).'?=';
        return $display.' <'.$address.'>';
    }

    /** A header value: as is when it is plain ASCII, otherwise an RFC 2047 encoded word. */
    public static function encodeHeader(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1 ? $value : '=?UTF-8?B?'.base64_encode($value).'?=';
    }

    private function fromDomain(): string
    {
        $at = strrpos($this->fromAddress, '@');
        $domain = $at === false ? '' : substr($this->fromAddress, $at + 1);
        return preg_match('/^[A-Za-z0-9.-]{1,253}$/', $domain) === 1 ? $domain : 'localhost';
    }
}
