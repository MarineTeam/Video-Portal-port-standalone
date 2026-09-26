<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Core\Http;
use App\Core\HttpResponse;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Sheets.php';

use MarineTeam\Plugins\Schedules\Sheets;

/**
 * The tabs of a spreadsheet, so the sheet is picked rather than typed.
 *
 * The failure case is the one worth pinning: a sheet nobody shared answers
 * 403, and Google's own message does not mention sharing, which is the
 * single most common thing wrong.
 */
final class SheetTabsTest extends TestCase
{
    /**
     * A real key pair, generated once: the token step signs a JWT with it
     * before any of this reaches Google, so a placeholder string fails long
     * before the case under test.
     *
     * @return array<string, mixed>
     */
    private static function account(): array
    {
        static $account = null;
        if ($account !== null) {
            return $account;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        return $account = [
            'client_email' => 'rota@example.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];
    }

    protected function tearDown(): void
    {
        Http::fake(null);
    }

    /** @param callable(string): HttpResponse $answer given the URL */
    private function google(callable $answer): void
    {
        Http::fake(function (string $method, string $url) use ($answer): HttpResponse {
            if (str_contains($url, 'oauth2')) {
                return new HttpResponse(200, [], (string) json_encode(['access_token' => 'tok', 'expires_in' => 3600]));
            }
            return $answer($url);
        });
    }

    public function test_1_the_tabs_come_back_in_the_order_they_sit_in(): void
    {
        $this->google(fn () => new HttpResponse(200, [], (string) json_encode(['sheets' => [
            ['properties' => ['title' => 'Sunday AM']],
            ['properties' => ['title' => 'Sunday PM']],
            ['properties' => ['title' => 'Notes']],
        ]])));
        self::assertSame(['Sunday AM', 'Sunday PM', 'Notes'], Sheets::tabs(self::account(), 'sheet-1'));
    }

    public function test_2_a_sheet_with_no_readable_titles_is_an_empty_list_not_a_crash(): void
    {
        $this->google(fn () => new HttpResponse(200, [], (string) json_encode(['sheets' => [['properties' => []], [], ['properties' => ['title' => '']]]])));
        self::assertSame([], Sheets::tabs(self::account(), 'sheet-1'));
    }

    public function test_3_a_sheet_nobody_shared_says_so_by_name(): void
    {
        $this->google(fn () => new HttpResponse(403, [], (string) json_encode(['error' => ['message' => 'The caller does not have permission']])));
        try {
            Sheets::tabs(self::account(), 'sheet-1');
            self::fail('a refusal should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('The caller does not have permission', $e->getMessage());
            self::assertStringContainsString('rota@example.iam.gserviceaccount.com', $e->getMessage(), 'and names the address to share it with');
        }
    }

    public function test_4_a_spreadsheet_that_is_not_there_is_the_same_advice(): void
    {
        $this->google(fn () => new HttpResponse(404, [], (string) json_encode(['error' => ['message' => 'Requested entity was not found.']])));
        $this->expectExceptionMessageMatches('/shared with rota@/');
        Sheets::tabs(self::account(), 'sheet-1');
    }

    public function test_5_it_asks_only_for_the_titles(): void
    {
        $asked = null;
        $this->google(function (string $url) use (&$asked): HttpResponse {
            $asked = $url;
            return new HttpResponse(200, [], (string) json_encode(['sheets' => []]));
        });
        Sheets::tabs(self::account(), 'sheet 1/2');
        self::assertStringContainsString('sheet%201%2F2', (string) $asked, 'the id is escaped into the path');
        self::assertStringContainsString('fields=sheets.properties.title', urldecode((string) $asked), 'and nothing else is fetched');
    }
}
