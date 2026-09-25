<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\HttpResponse;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * What the email services with an HTTPS API share: a "send as" address,
 * a test that checks the key before it sends anything, then sends the
 * administrator one message.
 */
abstract class HttpEmailProvider extends BaseProvider implements EmailProvider
{
    public static function slot(): string
    {
        return 'email';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    /** @return list<array<string, mixed>> the service's own fields */
    abstract protected static function credentialFields(): array;

    public static function configSchema(): array
    {
        return [
            ...static::credentialFields(),
            ['key' => 'from', 'label' => 'Send as', 'type' => 'text', 'required' => true, 'help' => 'e.g. Grace Church <notifications@gracechurch.org>, on a domain the service has verified.'],
        ];
    }

    /** Asks the service whether the credentials work: null when they do, else why not. */
    abstract protected function checkCredentials(): ?string;

    /** @return array{name: ?string, email: string} */
    public static function parseAddress(string $from): array
    {
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $from, $m)) {
            return ['name' => trim($m[1]) !== '' ? trim($m[1]) : null, 'email' => trim($m[2])];
        }
        return ['name' => null, 'email' => trim($from)];
    }

    protected function sender(string $fallback): string
    {
        return $this->str('from', $fallback);
    }

    /** The service's own words for a refusal, where it gives any. */
    protected static function said(HttpResponse $r): string
    {
        $d = $r->json();
        if (is_array($d)) {
            foreach (['message', 'Message', 'error', 'errors', 'detail'] as $key) {
                $v = $d[$key] ?? null;
                if (is_string($v) && $v !== '') {
                    return $v;
                }
                if (is_array($v)) {
                    $first = $v[0] ?? $v;
                    $text = is_array($first) ? ($first['message'] ?? $first['Message'] ?? null) : $first;
                    if (is_string($text) && $text !== '') {
                        return $text;
                    }
                }
            }
        }
        return 'HTTP ' . $r->status;
    }

    public function test(TestContext $context): TestResult
    {
        try {
            $problem = $this->checkCredentials();
        } catch (\Throwable $e) {
            return TestResult::fail('Couldn’t reach ' . static::label() . ' from this host: ' . $e->getMessage());
        }
        if ($problem !== null) {
            return TestResult::fail($problem);
        }
        $steps = ['Credentials accepted'];
        $result = $this->send(Message::plain($context->adminEmail, 'Marine Team email test', 'This is the test message from your site’s ' . static::label() . ' settings.'), $this->str('from'));
        if (!$result->ok()) {
            return TestResult::fail(static::label() . ' refused the test message: ' . $result->error, $steps);
        }
        $steps[] = "Test message sent to {$context->adminEmail}";
        return TestResult::ok("Sent a test message to {$context->adminEmail}.", $steps);
    }
}
