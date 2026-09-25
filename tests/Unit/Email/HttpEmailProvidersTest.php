<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Services\Email\BrevoProvider;
use App\Services\Email\GraphProvider;
use App\Services\Email\HttpEmailProvider;
use App\Services\Email\MailgunProvider;
use App\Services\Email\Message;
use App\Services\Email\PostmarkProvider;
use App\Services\Email\SendGridProvider;
use App\Services\Email\SesProvider;
use App\Services\TestContext;
use PHPUnit\Framework\TestCase;

/** Each HTTPS email service against the request and answer shapes it documents. */
final class HttpEmailProvidersTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, string>, 3: ?string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        \App\Core\Cache::configure(null);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
    }

    /** @param array<string, HttpResponse> $routes "METHOD url-prefix" => answer */
    private function fake(array $routes): void
    {
        $this->requests = [];
        Http::fake(function (string $method, string $url, array $headers, ?string $body) use ($routes): HttpResponse {
            $this->requests[] = [$method, $url, $headers, $body];
            foreach ($routes as $key => $answer) {
                [$m, $prefix] = explode(' ', $key, 2);
                if ($m === $method && str_starts_with($url, $prefix)) {
                    return $answer;
                }
            }
            return new HttpResponse(599, [], 'no fixture for ' . $method . ' ' . $url);
        });
    }

    private static function json(mixed $data, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['content-type' => 'application/json'], (string) json_encode($data));
    }

    private static function message(): Message
    {
        return new Message('ruth@example.org', 'Hello', 'Plain words', '<p>Plain words</p>', 'office@example.org');
    }

    /** @return array<string, mixed> */
    private function lastJson(): array
    {
        return (array) json_decode((string) end($this->requests)[3], true);
    }

    public function test_parse_address(): void
    {
        self::assertSame(['name' => 'Grace Church', 'email' => 'a@b.org'], HttpEmailProvider::parseAddress('Grace Church <a@b.org>'));
        self::assertSame(['name' => 'Grace, Church', 'email' => 'a@b.org'], HttpEmailProvider::parseAddress('"Grace, Church" <a@b.org>'));
        self::assertSame(['name' => null, 'email' => 'a@b.org'], HttpEmailProvider::parseAddress(' a@b.org '));
    }

    public function test_mailgun_posts_a_form_with_basic_auth_to_the_right_region(): void
    {
        $this->fake(['POST https://api.eu.mailgun.net/v3/mg.example.org/messages' => self::json(['id' => '<20260925.1@mg.example.org>', 'message' => 'Queued'])]);
        $p = new MailgunProvider(['api_key' => 'key-1', 'domain' => 'mg.example.org', 'region' => 'eu', 'from' => 'Grace <n@mg.example.org>']);
        $r = $p->send(self::message(), 'x@y.org');
        self::assertTrue($r->ok());
        self::assertSame('20260925.1@mg.example.org', $r->messageId);
        [, , $headers, $body] = $this->requests[0];
        self::assertSame('Basic ' . base64_encode('api:key-1'), $headers['Authorization']);
        parse_str((string) $body, $fields);
        self::assertSame(['Grace <n@mg.example.org>', 'ruth@example.org', 'office@example.org'], [$fields['from'], $fields['to'], $fields['h:Reply-To']]);
    }

    public function test_mailgun_names_a_missing_domain(): void
    {
        $this->fake(['GET https://api.mailgun.net/v4/domains/nope.example.org' => self::json(['message' => 'Domain not found'], 404)]);
        $result = (new MailgunProvider(['api_key' => 'k', 'domain' => 'nope.example.org', 'from' => 'a@b.org']))->test(new TestContext('admin@example.org', true, 'https://x', []));
        self::assertFalse($result->ok);
        self::assertStringContainsString('no sending domain nope.example.org', $result->message);
    }

    public function test_sendgrid_sends_both_parts_and_reads_the_id_header(): void
    {
        $this->fake(['POST https://api.sendgrid.com/v3/mail/send' => new HttpResponse(202, ['x-message-id' => 'sg-1'], '')]);
        $r = (new SendGridProvider(['api_key' => 'SG.x', 'from' => 'Grace Church <n@example.org>']))->send(self::message(), 'x@y.org');
        self::assertSame('sg-1', $r->messageId);
        $body = $this->lastJson();
        self::assertSame(['email' => 'n@example.org', 'name' => 'Grace Church'], $body['from']);
        self::assertSame(['text/plain', 'text/html'], array_column($body['content'], 'type'));
        self::assertSame('Bearer SG.x', $this->requests[0][2]['Authorization']);
    }

    public function test_sendgrid_wants_the_mail_send_scope(): void
    {
        $this->fake(['GET https://api.sendgrid.com/v3/scopes' => self::json(['scopes' => ['stats.read']])]);
        $result = (new SendGridProvider(['api_key' => 'SG.x', 'from' => 'a@b.org']))->test(new TestContext('admin@example.org', true, 'https://x', []));
        self::assertStringContainsString('Mail Send', $result->message);
    }

    public function test_postmark_uses_the_outbound_stream_and_reports_its_error_code(): void
    {
        $this->fake(['POST https://api.postmarkapp.com/email' => self::json(['ErrorCode' => 0, 'MessageID' => 'pm-1'])]);
        $r = (new PostmarkProvider(['server_token' => 'tok', 'from' => 'n@example.org']))->send(self::message(), 'x@y.org');
        self::assertSame('pm-1', $r->messageId);
        self::assertSame('outbound', $this->lastJson()['MessageStream']);
        self::assertSame('tok', $this->requests[0][2]['X-Postmark-Server-Token']);
        $this->fake(['POST https://api.postmarkapp.com/email' => self::json(['ErrorCode' => 300, 'Message' => 'Invalid email request'], 422)]);
        self::assertSame('Invalid email request', (new PostmarkProvider(['server_token' => 'tok', 'from' => 'n@example.org']))->send(self::message(), 'x')->error);
    }

    public function test_ses_signs_the_v2_call_for_its_region(): void
    {
        $this->fake(['POST https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails' => self::json(['MessageId' => 'ses-1'])]);
        $r = (new SesProvider(['access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => 's', 'region' => 'eu-west-1', 'from' => 'n@example.org']))->send(self::message(), 'x');
        self::assertSame('ses-1', $r->messageId);
        $auth = $this->requests[0][2]['authorization'] ?? $this->requests[0][2]['Authorization'] ?? '';
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/', $auth);
        self::assertStringContainsString('/eu-west-1/ses/aws4_request', $auth);
        self::assertSame(['office@example.org'], $this->lastJson()['ReplyToAddresses']);
    }

    public function test_brevo_names_the_sender_and_reads_the_message_id(): void
    {
        $this->fake(['POST https://api.brevo.com/v3/smtp/email' => self::json(['messageId' => '<br-1@smtp-relay>'], 201)]);
        $r = (new BrevoProvider(['api_key' => 'xkeysib', 'from' => 'Grace <n@example.org>']))->send(self::message(), 'x');
        self::assertSame('<br-1@smtp-relay>', $r->messageId);
        self::assertSame(['email' => 'n@example.org', 'name' => 'Grace'], $this->lastJson()['sender']);
        self::assertSame('xkeysib', $this->requests[0][2]['api-key']);
    }

    public function test_graph_gets_a_client_credentials_token_then_sends_as_the_mailbox(): void
    {
        $this->fake([
            'POST https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => self::json(['access_token' => 'graph-tok', 'expires_in' => 3599]),
            'POST https://graph.microsoft.com/v1.0/users/office%40example.org/sendMail' => new HttpResponse(202, ['request-id' => 'g-1'], ''),
        ]);
        $r = (new GraphProvider(['tenant_id' => 'tenant-1', 'client_id' => 'c', 'client_secret' => 's', 'from' => 'Office <office@example.org>']))->send(self::message(), 'x');
        self::assertTrue($r->ok());
        parse_str((string) $this->requests[0][3], $form);
        self::assertSame(['client_credentials', 'https://graph.microsoft.com/.default'], [$form['grant_type'], $form['scope']]);
        self::assertSame('Bearer graph-tok', $this->requests[1][2]['Authorization']);
        self::assertSame('HTML', $this->lastJson()['message']['body']['contentType']);
    }

    public function test_a_refused_token_fails_the_test_with_microsofts_words(): void
    {
        $this->fake(['POST https://login.microsoftonline.com/' => self::json(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.'], 401)]);
        $result = (new GraphProvider(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 'bad', 'from' => 'a@b.org']))->test(new TestContext('admin@example.org', true, 'https://x', []));
        self::assertFalse($result->ok);
        self::assertStringContainsString('Invalid client secret', $result->message);
    }
}
