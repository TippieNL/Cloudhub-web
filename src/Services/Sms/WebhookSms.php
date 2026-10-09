<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * Any gateway reachable by one JSON POST.
 *
 * CloudHub sends
 *
 *     POST <SMS_WEBHOOK_URL>
 *     Authorization: Bearer <SMS_WEBHOOK_TOKEN>      (when one is configured)
 *     {"to": "+31612345678", "from": "<SMS_FROM>", "message": "..."}
 *
 * and reads the status: 2xx means accepted, 400 or 422 means the number was
 * refused, anything else means the gateway is unavailable. A JSON answer may
 * carry {"id": ...}, kept as the message's reference; nothing else in it is
 * read. That is enough to front a provider CloudHub has no driver for, or an
 * SMS gateway app on the phone CloudHub itself runs on.
 */
final class WebhookSms implements SmsSender
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $from,
        private readonly HttpTransport $http,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function name(): string
    {
        return 'webhook';
    }

    public function send(string $to, string $message): string
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->token !== '') $headers[] = 'Authorization: Bearer '.$this->token;
        $payload = json_encode(['to' => $to, 'from' => $this->from, 'message' => $message],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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
}
