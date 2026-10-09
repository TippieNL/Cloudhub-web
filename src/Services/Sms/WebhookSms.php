<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * Any gateway reachable by one JSON POST -- including an Android phone with a
 * SIM, through an SMS gateway app.
 *
 * SMS_WEBHOOK_FORMAT picks what the POST looks like:
 *
 *   cloudhub (default)  {"to": "+31612345678", "from": "<SMS_FROM>", "message": "..."}
 *                       Authorization: Bearer <SMS_WEBHOOK_TOKEN>, when one is set
 *   traccar             the Traccar SMS Gateway app: {"to": ..., "message": ...}
 *                       Authorization: <SMS_WEBHOOK_TOKEN>, the app's API key
 *   smsgate             SMS Gateway for Android (sms-gate.app), local server:
 *                       {"textMessage": {"text": ...}, "phoneNumbers": [...]}
 *                       Basic authentication, SMS_WEBHOOK_TOKEN = username:password
 *
 * The two apps are spoken to exactly as their own servers expect: Traccar's
 * compares the Authorization header with its key as it stands -- a "Bearer "
 * in front is a wrong key -- and fails on any field it does not know, so it is
 * sent those two and nothing else.
 *
 * The status decides: 2xx means accepted, 400 or 422 means the number was
 * refused, anything else means the gateway is unavailable. A JSON answer may
 * carry {"id": ...}, kept as the message's reference; nothing else in it is
 * read, and no answer's body is logged.
 */
final class WebhookSms implements SmsSender
{
    public const FORMATS = ['cloudhub', 'traccar', 'smsgate'];

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $from,
        private readonly HttpTransport $http,
        private readonly int $timeoutSeconds = 10,
        private readonly string $format = 'cloudhub',
    ) {}

    public function name(): string
    {
        return 'webhook';
    }

    public function send(string $to, string $message): string
    {
        [$headers, $payload] = $this->request($to, $message);

        try {
            [$status, $body] = $this->http->post($this->url, $headers, $payload, $this->timeoutSeconds);
        } catch (\RuntimeException $e) {
            throw new SmsException(SmsException::UNAVAILABLE, 'webhook: no answer ('.$e->getMessage().')', $e);
        }

        if ($status >= 200 && $status < 300) {
            $data = json_decode($body, true);
            $id = is_array($data) && is_scalar($data['id'] ?? null) ? (string)$data['id'] : '';
            return preg_match('/^[\w.:-]{1,100}$/', $id) === 1 ? $id : '';
        }
        $kind = in_array($status, [400, 422], true) ? SmsException::REJECTED : SmsException::UNAVAILABLE;
        throw new SmsException($kind, "webhook: HTTP $status");
    }

    /** @return array{0: list<string>, 1: string} the headers and the body the gateway expects */
    private function request(string $to, string $message): array
    {
        $body = match ($this->format) {
            'traccar' => ['to' => $to, 'message' => $message],
            'smsgate' => ['textMessage' => ['text' => $message], 'phoneNumbers' => [$to]],
            default => ['to' => $to, 'from' => $this->from, 'message' => $message],
        };
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: '.match ($this->format) {
                'traccar' => $this->token,
                'smsgate' => 'Basic '.base64_encode($this->token),
                default => 'Bearer '.$this->token,
            };
        }
        return [$headers, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }
}
