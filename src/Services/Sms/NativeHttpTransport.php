<?php
declare(strict_types=1);

namespace CloudHub\Services\Sms;

/**
 * HTTP POST with cURL where the build has it, and PHP's own streams where it
 * does not -- an Android/KSWEB PHP is not guaranteed to carry cURL, and the
 * stream wrapper needs nothing beyond allow_url_fopen.
 *
 * Either way redirects are never followed: a gateway's answer that points
 * somewhere else would otherwise carry the credentials and the code with it.
 * Certificates are always verified.
 */
final class NativeHttpTransport implements HttpTransport
{
    public function __construct(private readonly bool $preferCurl = true) {}

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $timeout = max(1, $timeoutSeconds);
        return $this->preferCurl && function_exists('curl_init')
            ? $this->viaCurl($url, $headers, $body, $timeout)
            : $this->viaStream($url, $headers, $body, $timeout);
    }

    /** @return array{0:int,1:string} */
    private function viaCurl(string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('cURL could not start');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $answer = curl_exec($ch);
        if (!is_string($answer)) {
            $error = curl_error($ch) ?: 'no answer';
            curl_close($ch);
            throw new \RuntimeException($error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $answer];
    }

    /** @return array{0:int,1:string} */
    private function viaStream(string $url, array $headers, string $body, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                // A 4xx or 5xx answer is still an answer: the body says why.
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                // HTTP/1.0, so no gateway answers in chunks the wrapper would
                // have to reassemble.
                'protocol_version' => 1.0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $answer = @file_get_contents($url, false, $context);
        $lines = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        if (!is_string($answer) || $lines === []) {
            $error = error_get_last()['message'] ?? 'no answer';
            throw new \RuntimeException(preg_replace('/^file_get_contents\([^)]*\): /', '', $error) ?? 'no answer');
        }
        // The last status line is the one that counts.
        $status = 0;
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
        }
        return [$status, $answer];
    }
}
