<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * Twilio Programmable Messaging, over its REST API.
 *
 * One POST to /Messages.json with HTTP basic authentication -- small enough
 * that Twilio's SDK, and the Composer dependency it would bring, buys nothing.
 *
 * Twilio answers 201 with the message's SID when it has accepted the message,
 * and 4xx with {"code", "message", ...} when it has not. Its message text quotes
 * the destination number, so failures are logged by status and code only.
 */
final class TwilioSms implements SmsSender
{
    /**
     * Twilio's codes for "this number cannot be texted": invalid, not mobile,
     * unreachable, opted out, or in a region this account may not send to.
     * Sending again will not help. Anything else -- bad credentials, a bad
     * sender, a full queue, an outage -- may recover, so it is not the number's
     * fault.
     */
    private const NUMBER_ERRORS = [21211, 21214, 21217, 21401, 21407, 21408, 21610, 21612, 21614];

    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $from,
        private readonly string $messagingServiceSid,
        private readonly HttpTransport $http,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function send(string $to, string $message): string
    {
        $fields = ['To' => $to, 'Body' => $message];
        // A messaging service picks the sender itself, from its own pool.
        if ($this->messagingServiceSid !== '') $fields['MessagingServiceSid'] = $this->messagingServiceSid;
        else $fields['From'] = $this->from;

        try {
            [$status, $body] = $this->http->post(
                'https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($this->accountSid).'/Messages.json',
                [
                    'Authorization: Basic '.base64_encode($this->accountSid.':'.$this->authToken),
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                $this->timeoutSeconds,
            );
        } catch (\RuntimeException $e) {
            throw new SmsException(SmsException::UNAVAILABLE, 'twilio: no answer ('.$e->getMessage().')', $e);
        }

        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];

        if ($status >= 200 && $status < 300) {
            // Accepted means a message SID. A 2xx without one is not something
            // to report as sent: nobody can say what happened to the message.
            $sid = $data['sid'] ?? null;
            if (!is_string($sid) || !preg_match('/^(?:SM|MM)[0-9a-f]{32}$/i', $sid)) {
                throw new SmsException(SmsException::UNAVAILABLE, "twilio: HTTP $status without a message SID");
            }
            if (in_array($data['status'] ?? null, ['failed', 'undelivered'], true)) {
                throw new SmsException(SmsException::REJECTED, "twilio: HTTP $status, message $sid already ".$data['status']);
            }
            return $sid;
        }

        $code = is_int($data['code'] ?? null) ? $data['code'] : 0;
        $kind = $status === 400 && in_array($code, self::NUMBER_ERRORS, true) ? SmsException::REJECTED : SmsException::UNAVAILABLE;
        throw new SmsException($kind, "twilio: HTTP $status, error $code");
    }
}
