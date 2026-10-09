<?php
declare(strict_types=1);

namespace CloudHub\Services\Mail;

use CloudHub\Services\EmailAddress;

/**
 * The SMTP server .env names, built once per request. No SMTP_HOST: no email.
 *
 * Settings that are given but cannot work -- no sender address, a URL where a
 * host name belongs, unencrypted SMTP to the internet -- are logged once, as
 * "[mail] ..." naming the setting at fault (never its value), and treated as
 * no mail server at all. Two-step verification then cannot be turned on, and
 * accounts that already have it can only finish signing in with a recovery
 * code. Sign-in never falls back to the password alone.
 */
final class Mail
{
    /** The configured sender, or null when there is none that can be used. */
    public static function fromConfig(array $config, ?SmtpConnection $connection = null): ?MailSender
    {
        if (!self::configured($config)) return null;
        $problem = self::problem($config);
        if ($problem !== null) {
            static $logged = false;
            if (!$logged) { error_log('[mail] '.$problem); $logged = true; }
            return null;
        }

        $caFile = trim((string)($config['smtp_ca_file'] ?? ''));
        return new SmtpMailer(
            trim((string)$config['smtp_host']),
            self::port($config),
            self::encryption($config),
            (string)($config['smtp_username'] ?? ''),
            (string)($config['smtp_password'] ?? ''),
            (string)EmailAddress::normalize((string)$config['mail_from_address']),
            trim((string)($config['mail_from_name'] ?? '')),
            $connection ?? new StreamSmtpConnection($caFile),
            max(2, min(60, (int)($config['smtp_timeout_seconds'] ?? 10) ?: 10)),
        );
    }

    /**
     * Why the configured mail server cannot be used, or null when it can (or
     * when none is configured). Names settings, never their values.
     */
    public static function problem(array $config): ?string
    {
        if (!self::configured($config)) return null;
        if (EmailAddress::normalize((string)($config['mail_from_address'] ?? '')) === null) {
            return 'MAIL_FROM_ADDRESS is missing or not an email address; email is off';
        }
        $host = trim((string)($config['smtp_host'] ?? ''));
        $bare = trim($host, '[]');
        $ipv6 = filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$ipv6 && preg_match('#[\s/:@]#', $bare) === 1) {
            return 'SMTP_HOST must be a host name or address, without a scheme or port; email is off';
        }
        $port = self::port($config);
        if ($port < 1 || $port > 65535) return 'SMTP_PORT must be a port number; email is off';
        $encryption = self::encryption($config);
        if (!in_array($encryption, SmtpMailer::ENCRYPTIONS, true)) {
            return 'SMTP_ENCRYPTION must be one of '.implode(', ', SmtpMailer::ENCRYPTIONS).'; email is off';
        }
        // The two ports with a fixed meaning: 465 is TLS from the first byte,
        // 587 is plain until STARTTLS. Swapped, the conversation just hangs.
        if ($port === 465 && $encryption === 'tls') return 'SMTP_PORT=465 needs SMTP_ENCRYPTION=ssl (TLS from the first byte); email is off';
        if ($port === 587 && $encryption === 'ssl') return 'SMTP_PORT=587 needs SMTP_ENCRYPTION=tls (STARTTLS); email is off';
        if ($encryption === 'none' && !self::isLocalHost($host)) {
            return 'SMTP_ENCRYPTION=none is allowed only for a mail server on this machine or the local network; email is off';
        }
        $user = (string)($config['smtp_username'] ?? '');
        $password = (string)($config['smtp_password'] ?? '');
        if (($user === '') !== ($password === '')) return 'SMTP_USERNAME and SMTP_PASSWORD go together; email is off';
        $caFile = trim((string)($config['smtp_ca_file'] ?? ''));
        if ($caFile !== '' && !is_readable($caFile)) return 'SMTP_CA_FILE is set but cannot be read; email is off';
        return null;
    }

    private static function configured(array $config): bool
    {
        return trim((string)($config['smtp_host'] ?? '')) !== '';
    }

    private static function port(array $config): int
    {
        $port = (int)($config['smtp_port'] ?? 0);
        if ($port !== 0) return $port;
        return match (self::encryption($config)) { 'ssl' => 465, 'none' => 25, default => 587 };
    }

    private static function encryption(array $config): string
    {
        $encryption = strtolower(trim((string)($config['smtp_encryption'] ?? '')));
        return $encryption === '' ? 'tls' : $encryption;
    }

    /**
     * Loopback or a private address, where a code crossing the network in
     * clear stays on hardware the operator controls -- a relay on this
     * machine, or one beside it on the LAN.
     */
    private static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost') return true;
        if (filter_var($host, FILTER_VALIDATE_IP) === false) return false;
        if (str_starts_with($host, '127.') || $host === '::1') return true;
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }
}
