<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Services\Sms\BulkSmsProvider;
use App\Services\Sms\ClickSendProvider;
use App\Services\Sms\MessageBirdProvider;
use App\Services\Sms\PlivoProvider;
use App\Services\Sms\SinchProvider;
use App\Services\Sms\SmsError;
use App\Services\Sms\SmsProvider;
use App\Services\Sms\TelnyxProvider;
use App\Services\Sms\TextlocalProvider;
use App\Services\Sms\TwilioProvider;
use App\Services\Sms\VonageProvider;
use App\Services\Sms\WebhookProvider;
use App\Support\Jwt;
use PHPUnit\Framework\TestCase;

/**
 * Each texting provider against a fixture of what its API really answers:
 * what it sends, what it calls the message afterwards, and what it does with
 * a refusal — because "SMS failed" beside somebody's name helps nobody, and
 * the provider's own sentence does.
 */
final class SmsProvidersTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        // The webhook gateway goes through the untrusted fetcher, which
        // resolves the host itself before it will connect to it.
        Http::fakeResolver(fn () => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        Http::fakeResolver(null);
    }

    /** @param callable(string, string): HttpResponse $answer */
    private function fake(callable $answer): void
    {
        Http::fake(function (string $method, string $url, array $headers, ?string $body) use ($answer): HttpResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            return $answer($method, $url);
        });
    }

    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }

    private function lastBody(): array
    {
        $body = (string) ($this->calls[count($this->calls) - 1]['body'] ?? '');
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        parse_str($body, $fields);
        return $fields;
    }

    // -- Twilio ------------------------------------------------------------

    public function testTwilioSendsFormFieldsAndKeepsTheMessageSid(): void
    {
        $this->fake(fn () => self::json(201, ['sid' => 'SM123', 'status' => 'queued']));
        $twilio = new TwilioProvider(['account_sid' => 'AC1', 'auth_token' => 'secret', 'from' => '+15550000000']);
        $sent = $twilio->send('+15551234567', 'No service tomorrow.');
        $this->assertSame('SM123', $sent->messageId);
        $this->assertStringContainsString('/Accounts/AC1/Messages.json', $this->calls[0]['url']);
        $this->assertSame(['To' => '+15551234567', 'Body' => 'No service tomorrow.', 'From' => '+15550000000'], $this->lastBody());
        $this->assertSame('Basic ' . base64_encode('AC1:secret'), $this->calls[0]['headers']['Authorization']);
    }

    public function testTwilioPrefersAMessagingServiceWhenOneIsSet(): void
    {
        $this->fake(fn () => self::json(201, ['sid' => 'SM1']));
        (new TwilioProvider(['account_sid' => 'AC1', 'auth_token' => 's', 'from' => '+1555', 'messaging_service_sid' => 'MG9']))->send('+15551234567', 'Hello');
        $body = $this->lastBody();
        $this->assertSame('MG9', $body['MessagingServiceSid']);
        $this->assertArrayNotHasKey('From', $body);
    }

    public function testTwilioPassesOnItsOwnSentenceAndKnowsABadNumberIsPermanent(): void
    {
        $this->fake(fn () => self::json(400, ['code' => 21211, 'message' => "The 'To' number +1555 is not a valid phone number."]));
        $twilio = new TwilioProvider(['account_sid' => 'AC1', 'auth_token' => 's', 'from' => '+1555']);
        try {
            $twilio->send('+1555', 'Hello');
            $this->fail('expected a refusal');
        } catch (SmsError $e) {
            $this->assertStringContainsString('is not a valid phone number', $e->getMessage());
            $this->assertSame('21211', $e->providerCode);
            $this->assertTrue($e->permanent, 'trying again tomorrow fails in exactly the same way');
        }
    }

    public function testTwilioChecksItsOwnSignature(): void
    {
        $twilio = new TwilioProvider(['auth_token' => 'the-token']);
        $url = 'https://church.example.org/api/sms/status/twilio';
        $body = 'MessageStatus=delivered&MessageSid=SM123';
        // Twilio's rule: the URL, then each field in key order.
        $expected = base64_encode(hash_hmac('sha1', $url . 'MessageSidSM123MessageStatusdelivered', 'the-token', true));
        $this->assertTrue($twilio->verifyCallback($url, $body, ['x-twilio-signature' => $expected]));
        $this->assertFalse($twilio->verifyCallback($url, $body, ['x-twilio-signature' => 'nope']));
        $this->assertFalse($twilio->verifyCallback($url, $body, []), 'and an unsigned one is not accepted');
        $this->assertFalse($twilio->verifyCallback($url . '/other', $body, ['x-twilio-signature' => $expected]), 'nor one signed for another address');
    }

    public function testTwilioReadsItsReceiptsAndReplies(): void
    {
        $twilio = new TwilioProvider([]);
        $this->assertSame(['messageId' => 'SM1', 'status' => 'DELIVERED', 'reason' => null], $twilio->readReceipt(['MessageSid' => 'SM1', 'MessageStatus' => 'delivered']));
        $this->assertSame('FAILED', $twilio->readReceipt(['MessageSid' => 'SM1', 'MessageStatus' => 'undelivered'])['status']);
        $this->assertNull($twilio->readReceipt(['MessageSid' => 'SM1', 'MessageStatus' => 'queued'])['status'], 'a status we have no word for is not a verdict');
        $this->assertSame(['from' => '+15551234567', 'body' => 'STOP'], $twilio->readInbound(['From' => '+15551234567', 'Body' => 'STOP']));
    }

    // -- Vonage ------------------------------------------------------------

    public function testVonageReadsTheStatusInsideItsTwoHundred(): void
    {
        // Vonage answers 200 whatever happened; status 0 is the only success.
        $this->fake(fn () => self::json(200, ['messages' => [['status' => '0', 'message-id' => '0A00']]]));
        $sent = (new VonageProvider(['api_key' => 'k', 'api_secret' => 's', 'from' => 'Church']))->send('+447700900123', 'Hello');
        $this->assertSame('0A00', $sent->messageId);
        $this->assertSame('447700900123', $this->lastBody()['to'], 'and it wants the number without its plus');

        $this->fake(fn () => self::json(200, ['messages' => [['status' => '6', 'error-text' => 'Invalid message']]]));
        try {
            (new VonageProvider(['api_key' => 'k', 'api_secret' => 's', 'from' => 'Church']))->send('+447700900123', 'Hello');
            $this->fail('expected a refusal');
        } catch (SmsError $e) {
            $this->assertSame('Invalid message', $e->getMessage());
            $this->assertTrue($e->permanent);
        }
    }

    public function testVonageSaysWhenAMessageNeedsUnicode(): void
    {
        $this->fake(fn () => self::json(200, ['messages' => [['status' => '0', 'message-id' => '1']]]));
        $vonage = new VonageProvider(['api_key' => 'k', 'api_secret' => 's', 'from' => 'Church']);
        $vonage->send('+447700900123', 'Plain');
        $this->assertSame('text', $this->lastBody()['type']);
        $vonage->send('+447700900123', 'don’t');
        $this->assertSame('unicode', $this->lastBody()['type']);
    }

    public function testVonageChecksItsSignedJwt(): void
    {
        $vonage = new VonageProvider(['signature_secret' => 'shh']);
        $body = '{"status":"delivered"}';
        $token = Jwt::signHs256(['payload_hash' => hash('sha256', $body)], 'shh');
        $this->assertTrue($vonage->verifyCallback('https://x/y', $body, ['authorization' => 'Bearer ' . $token]));
        $this->assertFalse($vonage->verifyCallback('https://x/y', '{"tampered":true}', ['authorization' => 'Bearer ' . $token]), 'the body hash ties it to this callback');
        $this->assertFalse($vonage->verifyCallback('https://x/y', $body, ['authorization' => 'Bearer ' . Jwt::signHs256([], 'wrong')]));
        $this->assertFalse((new VonageProvider([]))->verifyCallback('https://x/y', $body, ['authorization' => 'Bearer ' . $token]), 'with no secret it refuses rather than accepting');
    }

    // -- The rest of the wire formats ----------------------------------------

    public function testMessageBirdPostsRecipientsAndReadsItsErrors(): void
    {
        $this->fake(fn () => self::json(201, ['id' => 'mb1']));
        $bird = new MessageBirdProvider(['access_key' => 'key', 'from' => 'Church']);
        $this->assertSame('mb1', $bird->send('+447700900123', 'Hello')->messageId);
        $this->assertSame(['+447700900123'], $this->lastBody()['recipients']);
        $this->assertSame('AccessKey key', $this->calls[0]['headers']['Authorization']);

        $this->fake(fn () => self::json(422, ['errors' => [['code' => 21, 'description' => 'A originator is required']]]));
        $this->expectException(SmsError::class);
        $this->expectExceptionMessage('A originator is required');
        $bird->send('+447700900123', 'Hello');
    }

    public function testMessageBirdRefusesASignatureFromLastWeek(): void
    {
        $bird = new MessageBirdProvider(['signing_key' => 'k']);
        $sign = static function (string $timestamp, string $query, string $body): string {
            return base64_encode(hash_hmac('sha256', implode("\n", [$timestamp, $query, hash('sha256', $body, true)]), 'k', true));
        };
        $now = (string) time();
        $old = (string) (time() - 3600);
        $body = '{"status":"delivered"}';
        $this->assertTrue($bird->verifyCallback('https://x/y?a=1', $body, [
            'messagebird-signature' => $sign($now, 'a=1', $body),
            'messagebird-request-timestamp' => $now,
        ]));
        $this->assertFalse($bird->verifyCallback('https://x/y?a=1', $body, [
            'messagebird-signature' => $sign($old, 'a=1', $body),
            'messagebird-request-timestamp' => $old,
        ]), 'a signature somebody kept cannot be replayed');
    }

    public function testPlivoSendsAndChecksItsNonceSignature(): void
    {
        $this->fake(fn () => self::json(202, ['message_uuid' => ['plivo-1']]));
        $plivo = new PlivoProvider(['auth_id' => 'MA', 'auth_token' => 'tok', 'from' => '+1555']);
        $this->assertSame('plivo-1', $plivo->send('+15551234567', 'Hello')->messageId);
        $this->assertStringContainsString('/Account/MA/Message/', $this->calls[0]['url']);

        $url = 'https://church.example.org/api/sms/status/plivo';
        $signature = base64_encode(hash_hmac('sha256', $url . 'nonce1', 'tok', true));
        $this->assertTrue($plivo->verifyCallback($url, '', ['x-plivo-signature-v3' => $signature, 'x-plivo-signature-v3-nonce' => 'nonce1']));
        $this->assertFalse($plivo->verifyCallback($url, '', ['x-plivo-signature-v3' => $signature, 'x-plivo-signature-v3-nonce' => 'other']));
    }

    public function testSinchPutsTheRegionInTheAddress(): void
    {
        $this->fake(fn () => self::json(201, ['id' => 'batch1']));
        $sinch = new SinchProvider(['service_plan_id' => 'plan', 'api_token' => 'tok', 'region' => 'eu', 'from' => '+46']);
        $this->assertSame('batch1', $sinch->send('+447700900123', 'Hello')->messageId);
        $this->assertStringStartsWith('https://eu.sms.api.sinch.com/xms/v1/plan/batches', $this->calls[0]['url']);
        $this->assertSame(['+447700900123'], $this->lastBody()['to']);
    }

    public function testSinchFallsBackToAKnownRegionRatherThanBuildingANonsenseAddress(): void
    {
        $this->fake(fn () => self::json(201, ['id' => 'b']));
        (new SinchProvider(['service_plan_id' => 'p', 'api_token' => 't', 'region' => 'moon', 'from' => 'x']))->send('+447700900123', 'Hi');
        $this->assertStringStartsWith('https://us.sms.api.sinch.com/', $this->calls[0]['url']);
    }

    public function testTelnyxReadsItsNestedPayload(): void
    {
        $this->fake(fn () => self::json(200, ['data' => ['id' => 'tx1']]));
        $telnyx = new TelnyxProvider(['api_key' => 'k', 'from' => '+1555']);
        $this->assertSame('tx1', $telnyx->send('+15551234567', 'Hello')->messageId);
        $receipt = $telnyx->readReceipt(['data' => ['payload' => ['id' => 'tx1', 'to' => [['status' => 'delivered']]]]]);
        $this->assertSame(['messageId' => 'tx1', 'status' => 'DELIVERED', 'reason' => null], $receipt);
        $this->assertSame(
            ['from' => '+15551234567', 'body' => 'STOP'],
            $telnyx->readInbound(['data' => ['payload' => ['from' => ['phone_number' => '+15551234567'], 'text' => 'STOP']]]),
        );
    }

    public function testTelnyxRefusesACallbackItCannotCheck(): void
    {
        $telnyx = new TelnyxProvider([]);
        $this->assertFalse($telnyx->verifyCallback('https://x', '{}', ['telnyx-signature-ed25519' => 'x', 'telnyx-timestamp' => (string) time()]), 'with no public key it refuses rather than trusting');
    }

    public function testClickSendReadsTheStatusInsideItsAnswer(): void
    {
        $this->fake(fn () => self::json(200, ['data' => ['messages' => [['status' => 'SUCCESS', 'message_id' => 'cs1']]]]));
        $clicksend = new ClickSendProvider(['username' => 'u', 'api_key' => 'k', 'from' => 'Church']);
        $this->assertSame('cs1', $clicksend->send('+447700900123', 'Hello')->messageId);

        $this->fake(fn () => self::json(200, ['data' => ['messages' => [['status' => 'INVALID_RECIPIENT']]]]));
        $this->expectException(SmsError::class);
        $clicksend->send('+1', 'Hello');
    }

    public function testTextlocalAnswersTwoHundredForARefusalToo(): void
    {
        $this->fake(fn () => self::json(200, ['status' => 'success', 'message_id' => 'tl1']));
        $textlocal = new TextlocalProvider(['api_key' => 'k', 'from' => 'CHURCH']);
        $this->assertSame('tl1', $textlocal->send('+447700900123', 'Hello')->messageId);
        $this->assertSame('447700900123', $this->lastBody()['numbers']);

        $this->fake(fn () => self::json(200, ['status' => 'failure', 'errors' => [['code' => 5, 'message' => 'Invalid mobile number']]]));
        try {
            $textlocal->send('+1', 'Hello');
            $this->fail('expected a refusal');
        } catch (SmsError $e) {
            $this->assertSame('Invalid mobile number', $e->getMessage());
            $this->assertTrue($e->permanent);
        }
    }

    public function testBulkSmsSendsJsonAndKeepsItsId(): void
    {
        $this->fake(fn () => self::json(201, [['id' => 'bs1']]));
        $bulk = new BulkSmsProvider(['username' => 'u', 'password' => 'p', 'from' => 'Church']);
        $this->assertSame('bs1', $bulk->send('+447700900123', 'Hello')->messageId);
        $this->assertSame('Basic ' . base64_encode('u:p'), $this->calls[0]['headers']['Authorization']);
        $this->assertSame('DELIVERED', $bulk->readReceipt(['id' => 'bs1', 'status' => ['type' => 'DELIVERED']])['status']);
    }

    public function testTheWebhookGatewayGetsTheThreeFieldsItWasPromised(): void
    {
        $this->fake(fn () => self::json(200, ['id' => 'gw1']));
        $webhook = new WebhookProvider(['url' => 'https://gateway.example.org/send', 'token' => 'tok', 'from' => 'Church']);
        $this->assertSame('gw1', $webhook->send('+447700900123', 'Hello')->messageId);
        $this->assertSame(['to' => '+447700900123', 'from' => 'Church', 'body' => 'Hello'], $this->lastBody());
        $this->assertSame('Bearer tok', $this->calls[0]['headers']['Authorization']);
    }

    public function testEveryProviderSaysWhetherItSignsItsCallbacks(): void
    {
        $signing = [TwilioProvider::class, VonageProvider::class, MessageBirdProvider::class, PlivoProvider::class, TelnyxProvider::class];
        foreach ($signing as $class) {
            $this->assertTrue($class::signsCallbacks(), $class);
        }
        foreach ([ClickSendProvider::class, TextlocalProvider::class, BulkSmsProvider::class, WebhookProvider::class, SinchProvider::class] as $class) {
            // These get a secret in the callback address instead.
            $this->assertFalse($class::signsCallbacks(), $class);
        }
    }

    public function testEveryProviderFillsInTheSlotTheSameWay(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/app/Services/Sms/*Provider.php') ?: [] as $file) {
            $class = 'App\\Services\\Sms\\' . basename($file, '.php');
            if (!is_a($class, SmsProvider::class, true) || (new \ReflectionClass($class))->isAbstract() || !class_exists($class)) {
                continue;
            }
            $this->assertSame('sms', $class::slot());
            $this->assertNotSame('', $class::id());
            $this->assertNotSame('', $class::label());
            $this->assertNotSame('', $class::limits(), $class . ' should say what it cannot do');
            $this->assertTrue($class::requiresOutboundHttps());
            $keys = array_column($class::configSchema(), 'key');
            $this->assertContains('default_country', $keys, $class . ' needs somewhere to read a national number with');
        }
    }
}
