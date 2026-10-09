<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * The gateway .env asks for, built once per request.
 *
 * A driver that is named but cannot work -- missing credentials, the
 * development outbox in production, a webhook over plain HTTP to the internet
 * -- is logged once and treated as no gateway at all. Two-step verification
 * then cannot be turned on, and accounts that already have it can only finish
 * signing in with a recovery code. Sign-in never falls back to the password
 * alone.
 */
final class Sms
{
    public const DRIVERS = ['twilio', 'webhook', 'log'];

    /** The configured sender, or null when there is none that can be used. */
    public static function fromConfig(array $config, string $projectRoot, ?HttpTransport $http = null): ?SmsSender
    {
        $driver = (string)($config['sms_driver'] ?? '');
        if ($driver === '' || $driver === 'none') return null;
        $problem = self::problem($config);
        if ($problem !== null) {
            static $logged = false;
            if (!$logged) { error_log('[sms] '.$problem); $logged = true; }
            return null;
        }

        $http ??= new NativeHttpTransport();
        $timeout = max(2, min(30, (int)($config['sms_timeout_seconds'] ?? 10)));
        return match ($driver) {
            'twilio' => new TwilioSms((string)$config['twilio_account_sid'], (string)$config['twilio_auth_token'],
                (string)($config['sms_from'] ?? ''), (string)($config['twilio_messaging_service_sid'] ?? ''), $http, $timeout),
            'webhook' => new WebhookSms((string)$config['sms_webhook_url'], (string)($config['sms_webhook_token'] ?? ''),
                (string)($config['sms_from'] ?? ''), $http, $timeout),
            'log' => new LogSms(rtrim($projectRoot, '/').'/logs/sms-outbox.log'),
        };
    }

    /**
     * Why the configured driver cannot be used, or null when it can (or when
     * none is configured). Names settings, never their values.
     */
    public static function problem(array $config): ?string
    {
        $driver = (string)($config['sms_driver'] ?? '');
        if ($driver === '' || $driver === 'none') return null;
        if (!in_array($driver, self::DRIVERS, true)) {
            return 'SMS_DRIVER must be one of '.implode(', ', self::DRIVERS).'; text messages are off';
        }
        if ($driver === 'log' && ($config['app_env'] ?? 'production') !== 'development') {
            return 'SMS_DRIVER=log writes codes to a file and only runs with APP_ENV=development; text messages are off';
        }
        if ($driver === 'twilio') {
            if (!preg_match('/^AC[0-9a-f]{32}$/i', (string)($config['twilio_account_sid'] ?? ''))) {
                return 'TWILIO_ACCOUNT_SID is missing or malformed; text messages are off';
            }
            if ((string)($config['twilio_auth_token'] ?? '') === '') return 'TWILIO_AUTH_TOKEN is missing; text messages are off';
            if ((string)($config['sms_from'] ?? '') === '' && (string)($config['twilio_messaging_service_sid'] ?? '') === '') {
                return 'Twilio needs SMS_FROM or TWILIO_MESSAGING_SERVICE_SID; text messages are off';
            }
        }
        if ($driver === 'webhook') {
            $url = (string)($config['sms_webhook_url'] ?? '');
            $parts = filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : false;
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            if (!is_array($parts) || !in_array($scheme, ['https', 'http'], true) || empty($parts['host'])) {
                return 'SMS_WEBHOOK_URL must be an http(s) URL; text messages are off';
            }
            if ($scheme === 'http' && !self::isLocalHost((string)$parts['host'])) {
                return 'SMS_WEBHOOK_URL may use plain http:// only for a gateway on this machine or the local network; text messages are off';
            }
        }
        return null;
    }

    /**
     * Loopback or a private address, where a code crossing the network in
     * clear stays on hardware the operator controls -- an SMS gateway app on
     * the phone CloudHub runs on, or one beside it on the LAN.
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
