<?php

declare(strict_types=1);

namespace Tests\Unit\Profile;

use App\Modules\Profile\DataExport;
use App\Modules\Profile\ExportQueries;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/data-export.test.ts */
final class DataExportTest extends TestCase
{
    // unsafeKeysIn ------------------------------------------------------------------

    #[TestDox('unsafeKeysIn finds a credential nested inside an array')]
    public function test_finds_a_credential_nested_inside_an_array(): void
    {
        $this->assertSame(['$.pushDevices[1].endpoint'], DataExport::unsafeKeysIn(['pushDevices' => [['service' => 'x'], ['endpoint' => 'https://fcm.googleapis.com/abc']]]));
    }

    #[TestDox('unsafeKeysIn finds one buried several objects deep')]
    public function test_finds_one_buried_several_objects_deep(): void
    {
        $this->assertSame(['$.a.b.c.tokenHash'], DataExport::unsafeKeysIn(['a' => ['b' => ['c' => ['tokenHash' => 'x']]]]));
    }

    #[TestDox('unsafeKeysIn matches the whole key, not a prefix of it')]
    public function test_matches_the_whole_key_not_a_prefix_of_it(): void
    {
        $this->assertSame([], DataExport::unsafeKeysIn(['tokens' => 1, 'author' => 'x', 'authority' => 'y', 'hasPassword' => true, 'endpointService' => 'z']));
    }

    #[TestDox('unsafeKeysIn reports every offender, not just the first')]
    public function test_reports_every_offender_not_just_the_first(): void
    {
        $this->assertSame(['$.calendarToken', '$.shareLinks[0].passwordHash', '$.televisions[0].tokenHash'], DataExport::unsafeKeysIn([
            'calendarToken' => 'x',
            'shareLinks' => [['passwordHash' => 'scrypt$…']],
            'televisions' => [['tokenHash' => 'y']],
        ]));
    }

    #[TestDox('unsafeKeysIn is quiet on a document that only holds facts')]
    public function test_is_quiet_on_a_document_that_only_holds_facts(): void
    {
        $this->assertSame([], DataExport::unsafeKeysIn(['account' => ['email' => 'a@b.test'], 'notes' => [['body' => 'token auth endpoint']]]));
    }

    #[TestDox('unsafeKeysIn covers every key it claims to')]
    public function test_covers_every_key_it_claims_to(): void
    {
        foreach (DataExport::FORBIDDEN_KEYS as $key) {
            $this->assertSame(["\$.x[0].$key"], DataExport::unsafeKeysIn(['x' => [[$key => 'v']]]), $key);
        }
    }

    // assertExportSafe --------------------------------------------------------------

    #[TestDox('assertExportSafe throws, naming the path, rather than quietly stripping it')]
    public function test_throws_naming_the_path(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('$.pushDevices[0].p256dh');
        DataExport::assertExportSafe(['pushDevices' => [['p256dh' => 'BNc…']]]);
    }

    #[TestDox('assertExportSafe passes a clean document')]
    public function test_passes_a_clean_document(): void
    {
        DataExport::assertExportSafe(['account' => ['email' => 'a@b.test'], 'pushDevices' => [['service' => 'https://fcm.googleapis.com']]]);
        $this->addToAssertionCount(1);
    }

    // exportFilename ----------------------------------------------------------------

    #[TestDox('exportFilename names the file after the member and the day')]
    public function test_names_the_file_after_the_member_and_the_day(): void
    {
        $this->assertSame('marine-team-ruth-ann-2026-09-25.json', DataExport::exportFilename('Ruth Ann', 'r@x.test', new \DateTimeImmutable('2026-09-25 23:00:00')));
        $this->assertSame('marine-team-ruth-smith-2026-09-25.json', DataExport::exportFilename(null, 'Ruth.Smith@x.test', new \DateTimeImmutable('2026-09-25')));
    }

    #[TestDox('exportFilename keeps the name safe for a Content-Disposition header')]
    public function test_keeps_the_name_safe_for_a_content_disposition_header(): void
    {
        $name = DataExport::exportFilename("José \"Pepe\"\r\nX-Evil: 1; ñ", 'j@x.test', new \DateTimeImmutable('2026-01-02'));
        $this->assertMatchesRegularExpression('/^marine-team-[a-z0-9-]+-2026-01-02\.json$/', $name);
    }

    #[TestDox('exportFilename falls back when there is nothing nameable in the address')]
    public function test_falls_back_when_there_is_nothing_nameable(): void
    {
        $this->assertSame('marine-team-member-2026-01-02.json', DataExport::exportFilename(null, '+++@x.test', new \DateTimeImmutable('2026-01-02')));
        $this->assertSame('marine-team-member-2026-01-02.json', DataExport::exportFilename('   ', '中文@x.test', new \DateTimeImmutable('2026-01-02')));
    }

    // pushServiceOf -----------------------------------------------------------------

    #[TestDox('pushServiceOf keeps the service and drops the part that identifies the browser')]
    public function test_keeps_the_service(): void
    {
        $this->assertSame('https://fcm.googleapis.com', DataExport::pushServiceOf('https://fcm.googleapis.com/fcm/send/dW5pcXVl:APA91b'));
        $this->assertSame('https://updates.push.services.mozilla.com', DataExport::pushServiceOf('https://updates.push.services.mozilla.com/wpush/v2/gAAAA'));
    }

    #[TestDox('pushServiceOf says unknown rather than passing an unparseable endpoint through whole')]
    public function test_says_unknown(): void
    {
        foreach (['not a url', 'http://insecure.example/x', '', 'https:///nohost'] as $bad) {
            $this->assertSame('unknown', DataExport::pushServiceOf($bad), $bad);
        }
    }

    // totalRecords ------------------------------------------------------------------

    #[TestDox('totalRecords counts nested lists, not just top-level ones')]
    public function test_counts_nested_lists(): void
    {
        $this->assertSame(5, DataExport::totalRecords(['account' => ['email' => 'x'], 'playlists' => [['items' => [1, 2]], ['items' => []]], 'notes' => [['body' => 'x']]]));
    }

    #[TestDox('totalRecords is zero for a document holding no lists')]
    public function test_is_zero_without_lists(): void
    {
        $this->assertSame(0, DataExport::totalRecords(['account' => ['email' => 'x', 'name' => null]]));
    }

    // the queries behind the export --------------------------------------------------

    /** @return array<string, string> section => SQL, read from the file as text */
    private static function queriesFromSource(string $source): array
    {
        // Comments are prose: strip them before looking for code.
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
        preg_match_all("/'([A-Za-z]+)' => \\['[a-z_]+', '((?:[^'\\\\]|\\\\.)*)'\\]/", $code, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $section, $sql]) {
            $out[$section] = $sql;
        }
        return $out;
    }

    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Profile/ExportQueries.php');
    }

    #[TestDox('the queries behind the export scope every read to one member')]
    public function test_scopes_every_read_to_one_member(): void
    {
        foreach (self::queriesFromSource(self::source()) as $section => $sql) {
            $this->assertMatchesRegularExpression('/\bWHERE\b[^;]*\b[a-z_.]+ = :(user|email)\b/', $sql, "$section is not scoped to one member");
            $this->assertDoesNotMatchRegularExpression('/\bOR\b/i', $sql, "$section widens its scope with OR");
            $this->assertSame(1, preg_match_all('/:(user|email)\b/', $sql), "$section must use its member parameter exactly once");
        }
    }

    #[TestDox('the queries behind the export name every column they select')]
    public function test_names_every_column_it_selects(): void
    {
        foreach (self::queriesFromSource(self::source()) as $section => $sql) {
            $this->assertDoesNotMatchRegularExpression('/SELECT\s+(DISTINCT\s+)?\*|[a-z]\.\*|,\s*\*/i', $sql, "$section selects *");
        }
    }

    #[TestDox('the queries behind the export: the checker ignores prose, not code')]
    public function test_ignores_prose_not_code(): void
    {
        $source = "// 'fake' => ['users', 'SELECT * FROM {{users}}'],\n/* 'also' => ['users', 'SELECT * FROM {{users}}'], */\n'real' => ['users', 'SELECT id FROM {{users}} WHERE id = :user'],";
        $this->assertSame(['real'], array_keys(self::queriesFromSource($source)));
    }

    #[TestDox('the queries behind the export: the checker finds the calls it is checking')]
    public function test_finds_the_calls_it_is_checking(): void
    {
        $found = self::queriesFromSource(self::source());
        $this->assertSame(array_keys(ExportQueries::QUERIES), array_keys($found), 'every query in the class is read from the file');
        $this->assertGreaterThan(40, count($found));
    }

    #[TestDox('every table keyed to a member is read by some query (completeness)')]
    public function test_every_table_keyed_to_a_member_is_exported(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Migrations/0001_init.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS \{\{(\w+)\}\} \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER);
        // Not facts about the member: one-time credentials and half-finished uploads.
        $exempt = ['auth_tokens' => 'single-use sign-in and reset tokens', 'uploads' => 'unfinished chunked uploads, swept daily'];
        $all = implode("\n", array_column(ExportQueries::QUERIES, 1));
        foreach ($tables as [, $table, $body]) {
            if (!preg_match('/REFERENCES \{\{users\}\}/', $body) && !preg_match('/^\s*user_id\s/m', $body)) {
                continue;
            }
            if (isset($exempt[$table])) {
                continue;
            }
            $this->assertStringContainsString('{{' . $table . '}}', $all, "$table is keyed to a member but no export query reads it");
        }
    }
}
