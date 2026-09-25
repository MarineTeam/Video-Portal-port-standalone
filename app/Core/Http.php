<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Outbound HTTP through curl, or PHP streams when curl is missing.
 *
 * Two doors:
 *   request()         for fixed provider hosts written in code.
 *   fetchUntrusted()  for any URL somebody typed — a pasted video link, a
 *                     webhook, an SMS gateway. It resolves the host itself,
 *                     refuses loopback, private, link-local and cloud-metadata
 *                     addresses on every redirect hop, allows only http(s),
 *                     caps the body and times out in ten seconds. The address
 *                     it checked is the address it connects to (curl RESOLVE),
 *                     so DNS can't answer differently the second time.
 */
final class Http
{
    public const UNTRUSTED_TIMEOUT = 10;
    public const UNTRUSTED_MAX_BYTES = 5_000_000;
    public const MAX_REDIRECTS = 5;

    /** @var (callable(string, string, array<string, string>, ?string, array<string, mixed>): HttpResponse)|null test double */
    private static $fake = null;

    /** @var (callable(string): list<string>)|null test double for DNS */
    private static $fakeResolver = null;

    /** @param (callable(string, string, array<string, string>, ?string, array<string, mixed>): HttpResponse)|null $fake the options are passed too, for a fake that honours sink */
    public static function fake(?callable $fake): void
    {
        self::$fake = $fake;
    }

    /** @param (callable(string): list<string>)|null $resolver host => addresses, for tests */
    public static function fakeResolver(?callable $resolver): void
    {
        self::$fakeResolver = $resolver;
    }

    /**
     * @param array<string, string> $headers
     * Two options keep large transfers out of memory: bodyFile sends a file
     * from disk as the body, and sink receives the response body in chunks
     * (after onHeaders has seen the status and headers) instead of it being
     * collected — a file larger than memory_limit passes straight through.
     *
     * @param array{timeout?: int, maxBytes?: int, resolve?: array{host: string, ip: string, port: int}|null, followRedirects?: bool, bodyFile?: string, sink?: callable(string): void, onHeaders?: callable(int, array<string, string>): void} $options
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        if (self::$fake !== null) {
            return (self::$fake)($method, $url, $headers, $body, $options);
        }
        if (function_exists('curl_init')) {
            return self::viaCurl($method, $url, $headers, $body, $options);
        }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new HttpException('This host allows no outbound requests: neither curl nor allow_url_fopen is available.');
        }
        return self::viaStreams($method, $url, $headers, $body, $options);
    }

    /** @param array<string, string> $headers */
    public static function json(string $method, string $url, mixed $payload = null, array $headers = [], int $timeout = 15): HttpResponse
    {
        $headers += ['Accept' => 'application/json'];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return self::request($method, $url, $headers, $body, ['timeout' => $timeout]);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function fetchUntrusted(string $method, string $url, array $headers = [], ?string $body = null, int $maxBytes = self::UNTRUSTED_MAX_BYTES): HttpResponse
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::checkUntrusted($url);
            $response = self::request($method, $url, $headers, $body, [
                'timeout' => self::UNTRUSTED_TIMEOUT,
                'maxBytes' => $maxBytes,
                'resolve' => $target,
                'followRedirects' => false,
            ]);
            if ($response->status >= 300 && $response->status < 400 && ($location = $response->header('location')) !== null) {
                $url = self::resolveRelative($url, $location);
                if ($response->status !== 307 && $response->status !== 308) {
                    $method = $method === 'HEAD' ? 'HEAD' : 'GET';
                    $body = null;
                }
                continue;
            }
            return $response;
        }
        throw new HttpException('Too many redirects.');
    }

    /**
     * Checks a typed URL and resolves its host, refusing anything that isn't
     * a public address.
     *
     * @return array{host: string, ip: string, port: int}
     */
    public static function checkUntrusted(string $url): array
    {
        if (!self::isPublicHttpUrl($url)) {
            throw new HttpException('That address is not a public web address.');
        }
        $parts = parse_url($url);
        $host = strtolower((string) $parts['host']);
        $host = trim($host, '[]');
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw new HttpException("Couldn't find the server $host.");
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new HttpException('That address points inside a private network.');
            }
        }
        return ['host' => $host, 'ip' => $ips[0], 'port' => $port];
    }

    /**
     * The syntactic half of the check, usable when saving a URL: http(s),
     * no credentials, a name that means something on the public internet,
     * and — when the host is written as an address — a public one.
     */
    public static function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }
        // A bare name, or a suffix that only means something on a LAN.
        if (!str_contains($host, '.') || preg_match('/(^|\.)(localhost|local|internal|intranet|lan|home|corp|localdomain|home\.arpa)$/', $host)) {
            return false;
        }
        // Decimal/hex/octal spellings of an address ("2130706433", "0x7f.1").
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (preg_match('/^(0x[0-9a-f]*|\d+)$/', $label)) {
                // Numeric labels mean an address spelled some other way; a
                // real top-level domain never is one.
                if ($label === end($labels) || preg_match('/^0x/', $label)) {
                    return false;
                }
            }
        }
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) === 1;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return false;
            }
            // IPv4-mapped and -compatible: judge the embedded IPv4 address.
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12))) {
                $v4 = inet_ntop(substr($bin, 12));
                return $v4 !== false && $v4 !== '0.0.0.0' && self::isPublicIp($v4);
            }
            $first = ord($bin[0]);
            $second = ord($bin[1]);
            if ($bin === str_repeat("\0", 15) . "\1" || $bin === str_repeat("\0", 16)) {
                return false; // ::1, ::
            }
            if (($first & 0xfe) === 0xfc) {
                return false; // fc00::/7 unique local
            }
            if ($first === 0xfe && ($second & 0xc0) === 0x80) {
                return false; // fe80::/10 link-local
            }
            if ($first === 0xff) {
                return false; // multicast
            }
            if (str_starts_with($bin, "\x20\x01\x0d\xb8")) {
                return false; // documentation
            }
            if (str_starts_with($bin, "\x00\x64\xff\x9b")) {
                return false; // NAT64, reaches IPv4 behind it
            }
            return true;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $blocked = [
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
            ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
            ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
            ['224.0.0.0', 4], ['240.0.0.0', 4],
        ];
        foreach ($blocked as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$fakeResolver !== null) {
            return (self::$fakeResolver)($host);
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }
        return array_values(array_unique($ips));
    }

    public static function resolveRelative(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        return $origin . $dir . $location;
    }

    /**
     * Whether this host can make outbound HTTPS at all — the installer's and
     * the Services screen's probe.
     *
     * @return array{ok: bool, via: string, message: string}
     */
    public static function probeOutbound(string $url = 'https://www.google.com/generate_204'): array
    {
        $via = function_exists('curl_init') ? 'curl' : (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) ? 'streams' : 'none');
        if ($via === 'none') {
            return ['ok' => false, 'via' => $via, 'message' => 'Neither the curl extension nor allow_url_fopen is available, so this host cannot reach other websites. Ask your host to enable curl.'];
        }
        try {
            $response = self::request('HEAD', $url, [], null, ['timeout' => 8]);
            return ['ok' => $response->status > 0 && $response->status < 500, 'via' => $via, 'message' => "Reached $url (HTTP {$response->status})."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'via' => $via, 'message' => 'This host could not reach the internet over HTTPS: ' . $e->getMessage() . '. Services that need it (most sign-in and email APIs) will not work; SMTP email and local sign-in still will.'];
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    private static function viaCurl(string $method, string $url, array $headers, ?string $body, array $options): HttpResponse
    {
        $timeout = (int) ($options['timeout'] ?? 15);
        $maxBytes = (int) ($options['maxBytes'] ?? 20_000_000);
        /** @var array{host: string, ip: string, port: int}|null $resolve */
        $resolve = $options['resolve'] ?? null;
        $follow = (bool) ($options['followRedirects'] ?? true);
        $sink = $options['sink'] ?? null;
        $onHeaders = $options['onHeaders'] ?? null;
        $ch = curl_init($url);
        $received = '';
        $responseHeaders = [];
        $tooBig = false;
        $headersSent = false;
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_USERAGENT => 'MarineTeam/1.0 (+https://github.com/marineteam)',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (preg_match('/^HTTP\//', $line)) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received, &$tooBig, &$headersSent, &$responseHeaders, $maxBytes, $sink, $onHeaders): int {
                if ($sink !== null) {
                    if (!$headersSent && $onHeaders !== null) {
                        $onHeaders((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $responseHeaders);
                    }
                    $headersSent = true;
                    $sink($chunk);
                    return strlen($chunk);
                }
                if (strlen($received) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0;
                }
                $received .= $chunk;
                return strlen($chunk);
            },
        ];
        $upload = null;
        if (isset($options['bodyFile'])) {
            $upload = fopen((string) $options['bodyFile'], 'rb');
            if ($upload === false) {
                throw new HttpException('The file to send couldn’t be read.');
            }
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $upload;
            $opts[CURLOPT_INFILESIZE] = (int) filesize((string) $options['bodyFile']);
            // A long upload is paced by its size, not by the default timeout.
            $opts[CURLOPT_TIMEOUT] = max($timeout, 600);
        } elseif ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($resolve !== null) {
            $ip = str_contains($resolve['ip'], ':') ? '[' . $resolve['ip'] . ']' : $resolve['ip'];
            $opts[CURLOPT_RESOLVE] = [$resolve['host'] . ':' . $resolve['port'] . ':' . $ip];
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (is_resource($upload)) {
            fclose($upload);
        }
        if ($sink !== null && !$headersSent && $onHeaders !== null && $ok !== false) {
            // An empty body: the headers are still news.
            $onHeaders($status, $responseHeaders);
        }
        if ($tooBig) {
            throw new HttpException('The response was larger than allowed.');
        }
        if ($ok === false) {
            throw new HttpException($error !== '' ? $error : 'Request failed.');
        }
        return new HttpResponse($status, $responseHeaders, $received);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    private static function viaStreams(string $method, string $url, array $headers, ?string $body, array $options): HttpResponse
    {
        $timeout = (int) ($options['timeout'] ?? 15);
        $maxBytes = (int) ($options['maxBytes'] ?? 20_000_000);
        $follow = (bool) ($options['followRedirects'] ?? true);
        if (isset($options['bodyFile'])) {
            // PHP's http wrapper can only send a body it holds in memory.
            $body = (string) file_get_contents((string) $options['bodyFile']);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => $follow ? 1 : 0,
                'max_redirects' => self::MAX_REDIRECTS,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw new HttpException(error_get_last()['message'] ?? 'Request failed.');
        }
        $meta = stream_get_meta_data($handle);
        $sink = $options['sink'] ?? null;
        if ($sink !== null) {
            [$status, $responseHeaders] = self::parseWrapperHeaders($meta['wrapper_data'] ?? []);
            if (isset($options['onHeaders'])) {
                ($options['onHeaders'])($status, $responseHeaders);
            }
            while (!feof($handle)) {
                $chunk = fread($handle, 262144);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $sink($chunk);
            }
            fclose($handle);
            return new HttpResponse($status, $responseHeaders, '');
        }
        $data = stream_get_contents($handle, $maxBytes + 1);
        fclose($handle);
        if ($data !== false && strlen($data) > $maxBytes) {
            throw new HttpException('The response was larger than allowed.');
        }
        [$status, $responseHeaders] = self::parseWrapperHeaders($meta['wrapper_data'] ?? []);
        return new HttpResponse($status, $responseHeaders, $data === false ? '' : $data);
    }

    /**
     * @param array<mixed> $lines the http wrapper's header lines, redirects included
     * @return array{0: int, 1: array<string, string>} the last response's status and headers
     */
    private static function parseWrapperHeaders(array $lines): array
    {
        $status = 0;
        $responseHeaders = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (str_contains((string) $line, ':')) {
                [$k, $v] = explode(':', (string) $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        return [$status, $responseHeaders];
    }
}
