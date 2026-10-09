<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * One HTTP POST, and the answer.
 *
 * The gateways speak HTTP and nothing more is needed of it, so this stands in
 * for an HTTP client library. Tests hand the gateways a fake that answers the
 * way a real one does -- success, refusal, an outage, nonsense -- without a
 * network.
 */
interface HttpTransport
{
    /**
     * @param list<string> $headers "Name: value" lines
     * @return array{0:int,1:string} the status code and the body
     * @throws \RuntimeException when no answer arrived at all: DNS, connection,
     *         TLS or the timeout
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array;
}
