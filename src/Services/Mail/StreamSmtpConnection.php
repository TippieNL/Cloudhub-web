<?php
declare(strict_types=1);

namespace CloudHub\Services\Mail;

/**
 * An SMTP connection over PHP's own sockets: nothing beyond the openssl
 * extension, which an Android/KSWEB PHP carries where cURL may not.
 *
 * Certificates are always verified against the host name; there is no switch
 * to turn that off. $caFile names a CA bundle for builds whose OpenSSL has
 * none configured, which is common on phones -- without one, verification
 * fails and nothing is sent, rather than sending without it.
 */
final class StreamSmtpConnection implements SmtpConnection
{
    /** @var resource|null */
    private $stream = null;
    private string $host = '';
    private float $deadline = 0.0;

    public function __construct(private readonly string $caFile = '') {}

    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void
    {
        $this->close();
        $this->host = $host;
        $this->deadline = microtime(true) + max(1, $timeoutSeconds);
        $target = str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host;
        $context = stream_context_create(['ssl' => $this->tlsOptions()]);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(($implicitTls ? 'ssl://' : 'tcp://').$target.':'.$port, $errno, $errstr,
            $this->remaining(), STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($stream)) {
            throw new MailException(MailException::UNAVAILABLE,
                'smtp: could not connect to '.$host.':'.$port.($implicitTls ? ' over TLS' : '').' ('.$errno.' '.trim($errstr).')');
        }
        $this->stream = $stream;
    }

    public function enableTls(): void
    {
        $stream = $this->live();
        foreach ($this->tlsOptions() as $option => $value) stream_context_set_option($stream, 'ssl', $option, $value);
        $this->arm();
        $ok = @stream_socket_enable_crypto($stream, true, $this->cryptoMethod());
        if ($ok !== true) {
            throw new MailException(MailException::UNAVAILABLE,
                'smtp: TLS with '.$this->host.' failed -- is its certificate valid for that name, and trusted (SMTP_CA_FILE)?');
        }
    }

    public function writeLine(string $line): void
    {
        $this->writeRaw($line."\r\n");
    }

    public function writeRaw(string $data): void
    {
        $stream = $this->live();
        while ($data !== '') {
            $this->arm();
            $written = @fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new MailException(MailException::UNAVAILABLE, 'smtp: the connection closed while sending'.$this->timedOut($stream));
            }
            $data = (string)substr($data, $written);
        }
    }

    public function readLine(): string
    {
        $stream = $this->live();
        $this->arm();
        $line = fgets($stream, 4096);
        if ($line === false) {
            throw new MailException(MailException::UNAVAILABLE, 'smtp: no answer'.$this->timedOut($stream));
        }
        return rtrim($line, "\r\n");
    }

    public function close(): void
    {
        if (is_resource($this->stream)) @fclose($this->stream);
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return array<string, mixed> */
    private function tlsOptions(): array
    {
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim($this->host, '[]'),
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'crypto_method' => $this->cryptoMethod(),
        ];
        if ($this->caFile !== '') $options['cafile'] = $this->caFile;
        return $options;
    }

    /** TLS 1.2 or later; nothing older is negotiated. */
    private function cryptoMethod(): int
    {
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        return $method;
    }

    /** @return resource */
    private function live()
    {
        if (!is_resource($this->stream)) throw new MailException(MailException::UNAVAILABLE, 'smtp: not connected');
        return $this->stream;
    }

    /** Give the next read or write only what is left of the deadline. */
    private function arm(): void
    {
        $left = $this->remaining();
        if ($left <= 0) throw new MailException(MailException::UNAVAILABLE, 'smtp: timed out');
        $seconds = (int)floor($left);
        stream_set_timeout($this->live(), $seconds, (int)(($left - $seconds) * 1_000_000));
    }

    private function remaining(): float
    {
        return $this->deadline - microtime(true);
    }

    /** @param resource $stream */
    private function timedOut($stream): string
    {
        $meta = @stream_get_meta_data($stream);
        return !empty($meta['timed_out']) ? ' (timed out)' : '';
    }
}
