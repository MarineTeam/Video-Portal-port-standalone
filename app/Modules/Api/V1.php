<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Profile\DataExport;

/**
 * The read API's shape (lib/api-v1.ts).
 *
 * One envelope, one error shape, cursor paging, and `assertNoSecrets` as the
 * last thing before the bytes leave — the same guard the member data export
 * uses, from the same list, because both are places where a query quietly
 * changing shape becomes a leak. It throws rather than filtering: a
 * credential reaching that point means a query changed, and the right answer
 * is a 500 in the log rather than a quietly trimmed response.
 */
final class V1
{
    /** Every answer, so a caller writes one unwrapping function. */
    public static function ok(array $data, ?string $nextCursor = null, array $extra = []): Response
    {
        // The payload, not the envelope: `data` is itself on the forbidden
        // list (it is the sessions table's column), and the envelope's own
        // key is ours rather than something a query produced.
        DataExport::assertExportSafe($data);
        DataExport::assertExportSafe($extra);
        $body = ['data' => $data] + $extra;
        if ($nextCursor !== null) {
            // Left out entirely on the last page: a null nextCursor reads as
            // "there might be more" to half the clients that meet it.
            $body['nextCursor'] = $nextCursor;
        }
        return self::private(Response::json($body));
    }

    /** One shape, with a code a program can branch on. */
    public static function fail(string $code, string $message, int $status, ?int $retryAfter = null): Response
    {
        $response = self::private(Response::json(['error' => ['code' => $code, 'message' => $message]], $status));
        if ($retryAfter !== null) {
            $response->header('Retry-After', (string) $retryAfter);
        }
        return $response;
    }

    /** A key's answer is that key's: never kept by a shared cache. */
    private static function private(Response $response): Response
    {
        return $response
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * What a page asks the database for: one row more than the page, which
     * is how "is there another page" gets answered without a COUNT over the
     * whole table.
     *
     * @return array{limit: int, fetch: int, cursor: ?string}
     */
    public static function paging(Request $req): array
    {
        $limit = Keys::pageSize($req->query('limit'));
        $cursor = trim((string) ($req->query('cursor') ?? ''));
        return ['limit' => $limit, 'fetch' => $limit + 1, 'cursor' => $cursor === '' ? null : $cursor];
    }

    /**
     * A cursor is opaque and URL-safe, so a caller pastes it back without
     * having to think about encoding a timestamp's space.
     */
    public static function encodeCursor(string $sortValue, string $id): string
    {
        return rtrim(strtr(base64_encode($sortValue . '|' . $id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string}|null */
    public static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || !str_contains($raw, '|')) {
            return null;
        }
        [$sortValue, $id] = explode('|', $raw, 2);
        return [$sortValue, $id];
    }

    /**
     * The page, and the cursor for the next one.
     *
     * @param list<array<string, mixed>> $rows what the database gave back, limit + 1 of them
     * @param callable(array<string, mixed>): string $cursorOf
     * @return array{rows: list<array<string, mixed>>, nextCursor: ?string}
     */
    public static function page(array $rows, int $limit, callable $cursorOf): array
    {
        if (count($rows) <= $limit) {
            return ['rows' => array_values($rows), 'nextCursor' => null];
        }
        $page = array_slice($rows, 0, $limit);
        return ['rows' => $page, 'nextCursor' => $cursorOf($page[count($page) - 1])];
    }

    /**
     * A timestamp filter, ignored rather than refused when it cannot be read.
     *
     * A sync job with a corrupt bookmark should get the whole list back and
     * carry on, not a 400 it will retry for ever.
     */
    public static function since(Request $req, string $name = 'updatedSince'): ?string
    {
        $given = trim((string) ($req->query($name) ?? ''));
        if ($given === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($given, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        } catch (\Throwable) {
            return null;
        }
    }
}
