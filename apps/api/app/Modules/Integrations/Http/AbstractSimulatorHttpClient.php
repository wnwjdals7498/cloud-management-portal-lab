<?php
declare(strict_types=1);

namespace App\Modules\Integrations\Http;

use Portal\Shared\Runtime;

abstract class AbstractSimulatorHttpClient
{
    protected readonly string $baseUrl;
    protected readonly string $secret;

    public function __construct(?string $baseUrl = null, ?string $secret = null, protected readonly int $timeoutSeconds = 5)
    {
        $runtime = null;
        if ($baseUrl === null || $secret === null) {
            $runtime = Runtime::config();
        }

        $baseUrl ??= (string) ($runtime['simulator_url'] ?? '');
        $secret ??= (string) ($runtime['simulator_secret'] ?? '');

        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'http'
            || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || trim($secret) === '' || $timeoutSeconds < 1 || $timeoutSeconds > 30) {
            throw new \InvalidArgumentException('Simulator HTTP configuration is invalid.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->secret = $secret;
    }

    /** @return array{status:int,body:string,transport_error:?string} */
    protected function request(string $method, string $path, ?string $body = null): array
    {
        if (! extension_loaded('curl')) {
            return ['status' => 0, 'body' => '', 'transport_error' => 'transport_unavailable'];
        }

        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'transport_error' => 'transport_unavailable'];
        }

        $responseBody = '';
        $headers = ['Accept: application/json', 'X-Portal-Simulator: ' . $this->secret];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->timeoutSeconds, 3) * 1000,
            CURLOPT_TIMEOUT_MS => $this->timeoutSeconds * 1000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($handle);
        $curlError = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($ok === false) {
            return [
                'status' => $status,
                'body' => '',
                'transport_error' => $curlError === CURLE_OPERATION_TIMEDOUT ? 'transport_timeout' : 'transport_unavailable',
            ];
        }

        return ['status' => $status, 'body' => $responseBody, 'transport_error' => null];
    }

    /** @return array<string, mixed>|null */
    protected function decodeObject(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    protected function safeRemoteError(array $body, string $fallback): string
    {
        $code = $body['error']['code'] ?? null;
        return is_string($code) && preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $code) === 1 ? $code : $fallback;
    }
}
