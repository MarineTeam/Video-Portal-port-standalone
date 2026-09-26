<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

use App\Core\Http;
use App\Support\Jwt;

/**
 * Reading a Google Sheet with a service account (the original's
 * lib/sheets/ client half). The church shares the sheet with the service
 * account's address, read-only, and nothing here ever writes to it.
 *
 * The account's private key is a stored secret; the token it is exchanged
 * for lives for an hour and is never written down.
 */
final class Sheets
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const API = 'https://sheets.googleapis.com/v4/spreadsheets/';
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    /** @var array<string, array{token: string, until: int}> */
    private static array $tokens = [];

    /**
     * The service account's own access token.
     *
     * @param array<string, mixed> $account the JSON key, decoded
     */
    public static function accessToken(array $account): string
    {
        $email = trim((string) ($account['client_email'] ?? ''));
        $key = (string) ($account['private_key'] ?? '');
        if ($email === '' || $key === '') {
            throw new \RuntimeException('That service account key has no client_email and private_key in it.');
        }
        $cached = self::$tokens[$email] ?? null;
        if ($cached !== null && $cached['until'] > time() + 60) {
            return $cached['token'];
        }
        $now = time();
        $assertion = Jwt::signRs256([], [
            'iss' => $email,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $key);
        $response = Http::request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]));
        $body = $response->json() ?? [];
        if (!$response->ok() || !is_string($body['access_token'] ?? null)) {
            throw new \RuntimeException('Google refused the service account (' . (string) ($body['error_description'] ?? $body['error'] ?? $response->status) . ').');
        }
        self::$tokens[$email] = ['token' => $body['access_token'], 'until' => $now + max(60, (int) ($body['expires_in'] ?? 3600))];
        return $body['access_token'];
    }

    /**
     * The rows of one sheet, exactly as Google gives them.
     *
     * @param array<string, mixed> $account
     * @return list<list<mixed>>
     */
    public static function rows(array $account, string $spreadsheetId, ?string $sheetName = null, ?string $range = null): array
    {
        $a1 = trim(($sheetName !== null && $sheetName !== '' ? "'" . str_replace("'", "''", $sheetName) . "'" : '') . ($range !== null && $range !== '' ? '!' . $range : ''));
        $url = self::API . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($a1 === '' ? 'A:Z' : $a1)
            . '?majorDimension=ROWS&valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=SERIAL_NUMBER';
        $response = Http::request('GET', $url, ['Authorization' => 'Bearer ' . self::accessToken($account), 'Accept' => 'application/json']);
        $body = $response->json() ?? [];
        if (!$response->ok()) {
            $message = (string) ($body['error']['message'] ?? 'Google answered ' . $response->status);
            if ($response->status === 403) {
                $message .= ' — has the sheet been shared with ' . (string) ($account['client_email'] ?? 'the service account') . '?';
            }
            throw new \RuntimeException($message);
        }
        $rows = [];
        foreach ((array) ($body['values'] ?? []) as $row) {
            $rows[] = array_values((array) $row);
        }
        return $rows;
    }

    /** Whether a key looks like a service account key at all, before anything is stored. */
    public static function looksLikeKey(mixed $decoded): bool
    {
        return is_array($decoded)
            && (string) ($decoded['type'] ?? '') === 'service_account'
            && is_string($decoded['client_email'] ?? null)
            && is_string($decoded['private_key'] ?? null)
            && str_contains((string) $decoded['private_key'], 'PRIVATE KEY');
    }
}
