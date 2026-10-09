<?php
declare(strict_types=1);

namespace CloudHub\Tests\Http;

/**
 * A CloudHub client for the HTTP suite, over real HTTP.
 *
 * It carries the session cookie and the CSRF token the way a browser does,
 * because the interesting failures live exactly there: a rotated session, a
 * missing token, a session that was never signed in. The same shape as
 * Cloudhub-2's tests/http/Client.php, which the Android build's API tests
 * share.
 */
final class Client
{
    private array $cookies = [];
    private string $csrf = '';

    public function __construct(private string $base) {}

    public function csrfToken(): string { return $this->csrf; }

    public function cookie(string $name): ?string { return $this->cookies[$name] ?? null; }

    /** Forget the session, as closing the browser would. */
    public function reset(): void { $this->cookies = []; $this->csrf = ''; }

    public function get(string $route, array $query = [], array $headers = []): Response
    {
        return $this->send('GET', $route, $query, null, $headers);
    }

    public function post(string $route, array $body = [], array $headers = []): Response
    {
        return $this->send('POST', $route, [], $body, $headers);
    }

    public function delete(string $route, array $body = []): Response
    {
        return $this->send('DELETE', $route, [], $body);
    }

    public function patch(string $route, array $body = []): Response
    {
        return $this->send('PATCH', $route, [], $body);
    }

    /** One chunk of a resumable upload; the offset travels in X-Upload-Offset. */
    public function putChunk(string $id, int $offset, string $bytes): Response
    {
        return $this->send('PUT', '/api/uploads/chunk', ['id' => $id], $bytes, ['X-Upload-Offset: '.$offset]);
    }

    /** A request with no CSRF token, to prove the guard is real. */
    public function postWithoutCsrf(string $route, array $body = []): Response
    {
        return $this->send('POST', $route, [], $body, [], false);
    }

    /** A plain GET of a URL exactly as it was handed out, such as a share link. */
    public function fetchUrl(string $url): Response
    {
        return $this->perform($url, 'GET', ['Accept: */*'], null);
    }

    /** WebDAV on a clean /webdav path, with the session and CSRF token like a real client. */
    public function dav(string $method, string $path, array $headers = [], ?string $body = null): Response
    {
        $h = ['Accept: */*'];
        if ($this->cookies) $h[] = 'Cookie: '.$this->cookieHeader();
        if ($this->csrf !== '') $h[] = 'X-CSRF-Token: '.$this->csrf;
        return $this->perform(rtrim($this->base, '/').$path, $method, array_merge($h, $headers), $body);
    }

    /** Sign in and keep the session for everything that follows. */
    public function signIn(string $username, string $password): Response
    {
        // The token is issued by /api/auth/status, which answers without a
        // session -- the same order the real clients use.
        $this->get('/api/auth/status');
        return $this->post('/api/auth/login', ['username' => $username, 'password' => $password]);
    }

    /**
     * The same POST $count times at once, on this session, for the races.
     *
     * @return list<Response>
     */
    public function parallelPost(string $route, array $body, int $count): array
    {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < $count; $i++) {
            $ch = curl_init($this->url($route, []));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => [
                    'Accept: application/json', 'Content-Type: application/json',
                    'Cookie: '.$this->cookieHeader(), 'X-CSRF-Token: '.$this->csrf]]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);
        $answers = [];
        foreach ($handles as $ch) {
            $raw = (string)curl_multi_getcontent($ch);
            $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($raw, $size);
            $decoded = json_decode($body, true);
            $answers[] = new Response((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), substr($raw, 0, $size), $body, is_array($decoded) ? $decoded : null);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $answers;
    }

    private function url(string $route, array $query): string
    {
        // The portable form the front controller understands, and the one the
        // Android client uses: ?route=%2Fapi%2F...
        $url = rtrim($this->base, '/').'/?route='.rawurlencode($route);
        foreach ($query as $key => $value) $url .= '&'.rawurlencode((string)$key).'='.rawurlencode((string)$value);
        return $url;
    }

    private function cookieHeader(): string
    {
        $pairs = [];
        foreach ($this->cookies as $name => $value) $pairs[] = $name.'='.$value;
        return implode('; ', $pairs);
    }

    private function send(string $method, string $route, array $query, array|string|null $body, array $extraHeaders = [], bool $withCsrf = true): Response
    {
        $headers = ['Accept: application/json'];
        if ($this->cookies) $headers[] = 'Cookie: '.$this->cookieHeader();
        if ($withCsrf && $this->csrf !== '' && $method !== 'GET') $headers[] = 'X-CSRF-Token: '.$this->csrf;
        $payload = null;
        if (is_array($body)) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        } elseif (is_string($body)) {
            $payload = $body;
            $headers[] = 'Content-Type: application/octet-stream';
        }
        return $this->perform($this->url($route, $query), $method, array_merge($headers, $extraHeaders), $payload);
    }

    private function perform(string $url, string $method, array $headers, ?string $payload): Response
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("$method $url failed: $error");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string)$raw, 0, $headerSize);
        $responseBody = substr((string)$raw, $headerSize);
        $this->takeCookies($rawHeaders);
        $decoded = json_decode($responseBody, true);
        // The server rotates the session and reissues the token with it --
        // including on the 401 that asks for a sign-in's second step.
        if (is_array($decoded) && isset($decoded['csrfToken']) && is_string($decoded['csrfToken'])) {
            $this->csrf = $decoded['csrfToken'];
        }
        return new Response($status, $rawHeaders, $responseBody, is_array($decoded) ? $decoded : null);
    }

    private function takeCookies(string $rawHeaders): void
    {
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) continue;
            $pair = trim(explode(';', substr($line, 11))[0]);
            if (!str_contains($pair, '=')) continue;
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '' || $value === 'deleted') unset($this->cookies[trim($name)]);
            else $this->cookies[trim($name)] = $value;
        }
    }
}

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $rawHeaders,
        public readonly string $body,
        public readonly ?array $json,
    ) {}

    public function ok(): bool { return $this->status >= 200 && $this->status < 300; }

    /** The API's error envelope: {"error":{"code":"...","message":"..."}}. */
    public function errorCode(): ?string { return $this->json['error']['code'] ?? null; }

    public function header(string $name): ?string
    {
        foreach (explode("\r\n", $this->rawHeaders) as $line) {
            if (stripos($line, $name.':') === 0) return trim(substr($line, strlen($name) + 1));
        }
        return null;
    }

    public function describe(): string
    {
        $note = $this->json['error']['message'] ?? substr($this->body, 0, 160);
        return $this->status.' '.$note;
    }
}
