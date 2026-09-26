<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Forms through a real server: built from rows rather than code, filled in
 * without an account, the server having the last word on a valid answer, a
 * renamed question keeping its answers, a retired one keeping its column
 * after the live ones, and responses marked dealt with by name.
 */
final class FormsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'fm_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::member('ruth@test.example', 'ruth');
        self::flushCache();
    }

    private static function addField(string $formId, array $field): string
    {
        $r = self::api('POST', "/api/admin/forms/$formId/fields", $field);
        self::assertSame(201, $r['status'], (string) $r['body']);
        return (string) $r['json']['id'];
    }

    public function test_1_a_form_is_built_from_rows(): void
    {
        $created = self::api('POST', '/api/admin/forms', ['title' => 'Connect card', 'description' => 'Tell us about yourself.']);
        self::assertSame(201, $created['status']);
        self::$ids['form'] = $created['json']['id'];
        self::assertSame('connect-card', $created['json']['slug']);
        self::assertSame([], $created['json']['fields']);

        self::$ids['name'] = self::addField(self::$ids['form'], ['label' => 'Your name', 'type' => 'TEXT', 'required' => true]);
        self::$ids['email'] = self::addField(self::$ids['form'], ['label' => 'Email', 'type' => 'EMAIL']);
        self::$ids['fax'] = self::addField(self::$ids['form'], ['label' => 'Fax number', 'type' => 'PHONE']);
        self::$ids['when'] = self::addField(self::$ids['form'], ['label' => 'Best time to ring', 'type' => 'RADIO', 'options' => "Morning\nAfternoon"]);
        $refused = self::api('POST', '/api/admin/forms/' . self::$ids['form'] . '/fields', ['label' => 'Empty choice', 'type' => 'SELECT']);
        self::assertSame(400, $refused['status'], 'a choice needs choices');
        self::assertSame(0, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{form_fields}} WHERE label = ?', ['Empty choice']), 'and the refusal leaves nothing behind');
        self::assertSame(400, self::api('PATCH', '/api/admin/forms/' . self::$ids['form'], ['notifyEmails' => 'office@test.example, not-an-address'])['status']);
        self::assertSame(200, self::api('PATCH', '/api/admin/forms/' . self::$ids['form'], ['notifyEmails' => 'office@test.example', 'published' => true])['status']);

        self::assertSame(403, self::api('POST', '/api/admin/forms', ['title' => 'Not mine'], 'ruth')['status']);
    }

    public function test_2_anybody_may_fill_it_in_and_the_server_has_the_last_word(): void
    {
        $page = self::http('GET', '/forms/connect-card', null, 'guest');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Your name', $page['body']);
        self::assertStringContainsString('data-form="connect-card"', $page['body']);

        $send = fn (array $answers, string $who = 'guest') => self::api('POST', '/api/forms/connect-card', ['answers' => $answers], $who);
        self::assertSame(400, $send([])['status'], 'a required question is asked again');
        self::assertSame(400, $send([self::$ids['name'] => 'Ruth', self::$ids['email'] => 'not-an-address'])['status']);
        self::assertSame(400, $send([self::$ids['name'] => 'Ruth', self::$ids['when'] => 'Midnight'])['status'], 'a fourth answer to a three-way question');

        $sent = $send([self::$ids['name'] => '  Ruth  ', self::$ids['email'] => 'ruth@visitor.example', self::$ids['fax'] => '01234 567890', self::$ids['when'] => 'Morning', 'smuggled' => 'nonsense']);
        self::assertSame(201, $sent['status']);
        self::assertSame('Thank you. We have got that.', $sent['json']['confirmation']);
        self::assertSame(201, self::api('POST', '/api/forms/connect-card', ['answers' => [self::$ids['name'] => 'Spam'], 'website' => 'http://spam.example'], 'guest')['status']);

        $rows = (array) self::api('GET', '/api/admin/forms/' . self::$ids['form'] . '/submissions')['json'];
        self::assertCount(1, $rows, 'the honeypot one was never stored');
        self::assertSame(['Ruth', 'ruth@visitor.example', '01234 567890', 'Morning'], array_column($rows[0]['cells'], 'value'));
        self::assertFalse($rows[0]['member']);
        self::$ids['submission'] = (string) $rows[0]['id'];
    }

    public function test_3_renaming_keeps_the_answers_and_retiring_keeps_the_column(): void
    {
        $form = self::$ids['form'];
        // Renaming a question doesn't rewrite history.
        self::assertSame(200, self::api('PATCH', "/api/admin/forms/$form/fields/" . self::$ids['name'], ['label' => 'Your full name'])['status']);
        $rows = (array) self::api('GET', "/api/admin/forms/$form/submissions")['json'];
        self::assertSame('Your full name', $rows[0]['cells'][0]['label']);
        self::assertSame('Ruth', $rows[0]['cells'][0]['value']);

        // Stopping a question keeps its answers, and its column moves after
        // the live ones rather than disappearing.
        self::assertSame(['retired' => true], self::api('DELETE', "/api/admin/forms/$form/fields/" . self::$ids['fax'])['json']);
        $rows = (array) self::api('GET', "/api/admin/forms/$form/submissions")['json'];
        self::assertSame(['Your full name', 'Email', 'Best time to ring', 'Fax number'], array_column($rows[0]['cells'], 'label'));
        self::assertSame('01234 567890', $rows[0]['cells'][3]['value']);
        self::assertTrue($rows[0]['cells'][3]['retired']);
        self::assertStringNotContainsString('Fax number', self::http('GET', '/forms/connect-card', null, 'guest')['body'], 'and it is no longer asked');

        // A question nobody has answered is simply removed.
        $spare = self::addField($form, ['label' => 'Spare', 'type' => 'TEXT']);
        self::assertSame(['retired' => false], self::api('DELETE', "/api/admin/forms/$form/fields/$spare")['json']);

        $csv = self::api('GET', "/api/admin/forms/$form/submissions?format=csv");
        self::assertStringContainsString('"Your full name","Email","Best time to ring","Fax number (retired)","Sent","Member","Dealt with by"', $csv['body']);
        self::assertStringContainsString('"Ruth","ruth@visitor.example","Morning","01234 567890"', $csv['body']);
    }

    public function test_4_dealt_with_by_name_and_sent_only_once(): void
    {
        $form = self::$ids['form'];
        $id = self::$ids['submission'];
        self::assertSame(['handled' => true], self::api('PATCH', "/api/admin/forms/$form/submissions/$id", ['handled' => true])['json']);
        $rows = (array) self::api('GET', "/api/admin/forms/$form/submissions")['json'];
        self::assertSame('admin@test.example', $rows[0]['handledBy'], 'so two people don\'t each assume the other rang');
        self::assertSame(['handled' => false], self::api('PATCH', "/api/admin/forms/$form/submissions/$id", ['handled' => false])['json']);

        // Once only, for a member: a camp application, not a connect card.
        self::assertSame(200, self::api('PATCH', "/api/admin/forms/$form", ['multiple' => false])['status']);
        self::assertSame(201, self::api('POST', '/api/forms/connect-card', ['answers' => [self::$ids['name'] => 'Ruth']], 'ruth')['status']);
        self::assertSame(409, self::api('POST', '/api/forms/connect-card', ['answers' => [self::$ids['name'] => 'Ruth again']], 'ruth')['status']);
        self::assertStringContainsString('already sent', self::http('GET', '/forms/connect-card', null, 'ruth')['body']);

        // Members only is invisible rather than refused.
        self::assertSame(200, self::api('PATCH', "/api/admin/forms/$form", ['memberOnly' => true])['status']);
        self::assertSame(404, self::http('GET', '/forms/connect-card', null, 'guest')['status']);
        self::assertStringNotContainsString('Connect card', self::http('GET', '/forms', null, 'guest')['body']);
        self::assertSame(404, self::api('POST', '/api/forms/connect-card', ['answers' => []], 'guest')['status']);

        self::assertSame(200, self::api('DELETE', "/api/admin/forms/$form/submissions/$id")['status']);
        self::assertSame(200, self::api('DELETE', "/api/admin/forms/$form")['status']);
        self::assertSame(0, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{form_answers}}'), 'and its answers go with it');
    }
}
