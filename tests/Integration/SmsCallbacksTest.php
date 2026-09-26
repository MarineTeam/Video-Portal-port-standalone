<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * What a texting provider tells us back, through a real server.
 *
 * Both endpoints are unauthenticated by design — a provider's server cannot
 * sign in — so what stands between them and a stranger is the signature, and
 * that is what is tested here: a genuine callback is acted on, a forged one
 * is refused, and a reply that says STOP switches that member's texting off
 * even though the provider handles stop words itself.
 */
final class SmsCallbacksTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    private const TOKEN = 'the-auth-token';

    protected static function prefix(): string
    {
        return 'sc2_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        $db = self::connect(self::prefix());
        $db->update('users', ['phone' => '+447700900123', 'sms_opt_in' => 1], ['id' => self::$ids['ruth']]);
        // Twilio, configured, so its signature is the one being checked.
        $db->insert('services', [
            'id' => \App\Core\Id::new(),
            'slot' => 'sms',
            'provider' => 'twilio',
            'config' => (string) json_encode(['account_sid' => 'AC1', 'auth_token' => self::TOKEN, 'from' => '+15550000000']),
            'active' => 1,
        ]);
        self::flushCache();
    }

    /** Twilio's rule: the URL, then each POST field in key order. */
    private static function signed(string $path, array $fields): array
    {
        ksort($fields);
        $data = self::$base . $path;
        foreach ($fields as $key => $value) {
            $data .= $key . $value;
        }
        return ['X-Twilio-Signature' => base64_encode(hash_hmac('sha1', $data, self::TOKEN, true))];
    }

    private static function post(string $path, array $fields, ?array $headers = null): array
    {
        return self::http('POST', $path, $fields, 'guest', ($headers ?? self::signed($path, $fields)) + ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    public function test_1_a_forged_callback_is_refused(): void
    {
        $fields = ['MessageSid' => 'SM1', 'MessageStatus' => 'delivered'];
        self::assertSame(403, self::post('/api/sms/status/twilio', $fields, ['X-Twilio-Signature' => 'not-it'])['status']);
        self::assertSame(403, self::post('/api/sms/status/twilio', $fields, [])['status'], 'and an unsigned one too');
        // Signed for another address, replayed at this one.
        self::assertSame(403, self::post('/api/sms/status/twilio', $fields, self::signed('/api/sms/status/vonage', $fields))['status']);
        self::assertSame(404, self::post('/api/sms/status/nobody', $fields, ['X-Twilio-Signature' => 'x'])['status'], 'and a provider nobody configured says nothing about what exists');
    }

    public function test_2_a_receipt_turns_sent_into_reached(): void
    {
        $db = self::connect(self::prefix());
        $broadcastId = \App\Core\Id::new();
        $db->insert('broadcasts', [
            'id' => $broadcastId, 'subject' => 'No service', 'body' => 'The road is closed.',
            'channels' => '["SMS"]', 'audience' => 'EVERYONE', 'status' => 'SENT', 'created_by' => 'admin@test.example',
        ]);
        self::$ids['recipient'] = \App\Core\Id::new();
        $db->insert('broadcast_recipients', [
            'id' => self::$ids['recipient'], 'broadcast_id' => $broadcastId, 'user_id' => self::$ids['ruth'],
            'channel' => 'SMS', 'address' => '+447700900123', 'name' => 'Ruth', 'status' => 'SENT',
            'provider' => 'twilio', 'provider_message_id' => 'SM123',
        ]);

        self::assertSame(200, self::post('/api/sms/status/twilio', ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'])['status']);
        $row = $db->one('SELECT * FROM {{broadcast_recipients}} WHERE id = ?', [self::$ids['recipient']]);
        self::assertSame('DELIVERED', $row['delivery_status']);
        self::assertNotNull($row['delivered_at']);

        // A status this app has no word for leaves the row alone.
        self::post('/api/sms/status/twilio', ['MessageSid' => 'SM123', 'MessageStatus' => 'queued']);
        self::assertSame('DELIVERED', (string) $db->value('SELECT delivery_status FROM {{broadcast_recipients}} WHERE id = ?', [self::$ids['recipient']]));
    }

    public function test_3_a_carrier_that_did_not_deliver_says_so(): void
    {
        $db = self::connect(self::prefix());
        self::post('/api/sms/status/twilio', ['MessageSid' => 'SM123', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003']);
        $row = $db->one('SELECT * FROM {{broadcast_recipients}} WHERE id = ?', [self::$ids['recipient']]);
        self::assertSame('FAILED', $row['delivery_status']);
        self::assertStringContainsString('30003', (string) $row['error']);
    }

    public function test_4_a_reply_that_says_stop_switches_texting_off(): void
    {
        $db = self::connect(self::prefix());
        self::assertSame(1, (int) $db->value('SELECT sms_opt_in FROM {{users}} WHERE id = ?', [self::$ids['ruth']]));

        // Twilio answers STOP itself on US numbers; this app honours the
        // reply anyway, because the same number can be here under another
        // provider tomorrow.
        self::assertSame(200, self::post('/api/sms/inbound/twilio', ['From' => '+44 7700 900123', 'Body' => ' Stop '])['status']);
        self::assertSame(0, (int) $db->value('SELECT sms_opt_in FROM {{users}} WHERE id = ?', [self::$ids['ruth']]));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {{audit_logs}} WHERE action = ?', ['sms.stop']));

        // And somebody who changes their mind.
        self::post('/api/sms/inbound/twilio', ['From' => '+447700900123', 'Body' => 'START']);
        self::assertSame(1, (int) $db->value('SELECT sms_opt_in FROM {{users}} WHERE id = ?', [self::$ids['ruth']]));

        // Anything else is not acted on: this app has no inbox for it, and
        // pretending otherwise would lose somebody's message silently.
        self::post('/api/sms/inbound/twilio', ['From' => '+447700900123', 'Body' => 'thanks, see you sunday']);
        self::assertSame(1, (int) $db->value('SELECT sms_opt_in FROM {{users}} WHERE id = ?', [self::$ids['ruth']]));
    }

    public function test_5_a_stop_from_a_number_nobody_here_has_changes_nothing(): void
    {
        $db = self::connect(self::prefix());
        $before = (int) $db->value('SELECT COUNT(*) FROM {{audit_logs}} WHERE action = ?', ['sms.stop']);
        self::assertSame(200, self::post('/api/sms/inbound/twilio', ['From' => '+15559999999', 'Body' => 'STOP'])['status']);
        self::assertSame($before, (int) $db->value('SELECT COUNT(*) FROM {{audit_logs}} WHERE action = ?', ['sms.stop']));
    }
}
