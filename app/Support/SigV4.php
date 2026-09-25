<?php

declare(strict_types=1);

namespace App\Support;

/**
 * AWS Signature Version 4 for S3-compatible storage, in pure PHP: presigned
 * URLs (the browser's PUTs and the player's GETs) and signed server-side
 * requests. Unsigned payloads, as S3 allows over HTTPS.
 */
final class SigV4
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @param array<string, string> $query */
    private static function canonicalQuery(array $query): string
    {
        ksort($query, SORT_STRING);
        $parts = [];
        foreach ($query as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $parts);
    }

    private function signingKey(string $date): string
    {
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', $this->service, $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    /**
     * A presigned URL: anyone holding it may make exactly this request until
     * it expires, and nothing else.
     *
     * @param array<string, string> $query extra query parameters that are part of the request (partNumber, uploadId…)
     */
    public function presign(string $method, string $url, int $expires, array $query = [], ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $p = parse_url($url);
        $host = (string) ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        $path = self::encodePath((string) ($p['path'] ?? '/'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $scope = "$date/{$this->region}/{$this->service}/aws4_request";
        $query += [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, min(604800, $expires)),
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonical = implode("\n", [$method, $path, self::canonicalQuery($query), 'host:' . $host, '', 'host', 'UNSIGNED-PAYLOAD']);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $query['X-Amz-Signature'] = hash_hmac('sha256', $toSign, $this->signingKey($date));
        return ($p['scheme'] ?? 'https') . '://' . $host . $path . '?' . self::canonicalQuery($query);
    }

    /**
     * Headers for a signed server-side request.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers = [], string $body = '', ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $p = parse_url($url);
        $host = (string) ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        parse_str((string) ($p['query'] ?? ''), $query);
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $payload = hash('sha256', $body);
        $headers = array_change_key_case($headers + ['host' => $host, 'x-amz-date' => $amzDate, 'x-amz-content-sha256' => $payload], CASE_LOWER);
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim((string) preg_replace('/\s+/', ' ', (string) $v)) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $scope = "$date/{$this->region}/{$this->service}/aws4_request";
        $canonical = implode("\n", [$method, self::encodePath((string) ($p['path'] ?? '/')), self::canonicalQuery(array_map('strval', (array) $query)), $canonicalHeaders, $signed, $payload]);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $signature = hash_hmac('sha256', $toSign, $this->signingKey($date));
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signed, Signature=$signature";
        unset($headers['host']);
        return $headers;
    }
}
