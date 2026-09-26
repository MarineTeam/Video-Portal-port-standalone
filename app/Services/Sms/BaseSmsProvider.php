<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Core\Http;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;
use App\Support\Sms;

/**
 * What every texting provider shares.
 *
 * Above all the test: each one ends with a text to the administrator's own
 * phone carrying a code they type back, because a provider accepting a
 * message says nothing at all about a carrier delivering it — and a church
 * only finds out which on the Sunday the road is closed.
 */
abstract class BaseSmsProvider extends BaseProvider implements SmsProvider
{
    public static function slot(): string
    {
        return 'sms';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function appendsOptOut(): bool
    {
        return false;
    }

    public static function signsCallbacks(): bool
    {
        return false;
    }

    public function verifyCallback(string $url, string $rawBody, array $headers): bool
    {
        return false;
    }

    public function readReceipt(array $payload): array
    {
        return ['messageId' => null, 'status' => null, 'reason' => null];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => null, 'body' => null];
    }

    /** The account check each provider names for itself, before anything is sent. */
    abstract protected function checkAccount(): TestResult;

    /**
     * Reach the account, then text the administrator and make them prove it
     * arrived. Nothing switches over on a provider's say-so.
     */
    public function test(TestContext $context): TestResult
    {
        $account = $this->checkAccount();
        if (!$account->ok) {
            return $account;
        }
        $steps = $account->steps;
        $to = Sms::normalizePhone((string) ($context->input['test_to'] ?? ''), $this->str('default_country'));
        if ($to === null) {
            return TestResult::fail(
                'Give your own mobile number to text, in full international form (+44 7700 900123). '
                . 'A provider accepting a message says nothing about a carrier delivering it, so the test ends with one arriving.',
                $steps,
            );
        }
        $code = (string) random_int(100000, 999999);
        try {
            $sent = $this->send($to, "Marine Team test. Your confirmation code is $code.");
        } catch (SmsError $e) {
            return TestResult::fail(static::label() . ' refused the test message: ' . $e->getMessage(), $steps);
        }
        $steps[] = 'Test message accepted' . ($sent->messageId !== '' ? ' (' . $sent->messageId . ')' : '');
        return TestResult::needsCode(
            "A text was sent to $to. Type the six-digit code it contains to confirm it arrived.",
            $code,
            $steps,
        );
    }

    /** @param array<string, mixed> $payload */
    protected function post(string $url, array $payload, array $headers = [], bool $form = false): \App\Core\HttpResponse
    {
        try {
            return $form
                ? Http::request('POST', $url, $headers + ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query($payload), ['timeout' => 15])
                : Http::json('POST', $url, $payload, $headers);
        } catch (\Throwable $e) {
            throw new SmsError('Couldn’t reach ' . static::label() . ' from this host: ' . $e->getMessage());
        }
    }

    protected function get(string $url, array $headers = []): \App\Core\HttpResponse
    {
        try {
            return Http::request('GET', $url, $headers + ['Accept' => 'application/json'], null, ['timeout' => 15]);
        } catch (\Throwable $e) {
            throw new SmsError('Couldn’t reach ' . static::label() . ' from this host: ' . $e->getMessage());
        }
    }

    /** The sender as configured: a number, or an alphanumeric sender id. */
    protected function from(): string
    {
        return $this->str('from');
    }

    /** A country's dialling code, for turning national numbers into E.164. */
    protected function defaultCountry(): ?string
    {
        $code = $this->str('default_country');
        return $code === '' ? null : $code;
    }

    /** The fields every provider wants, before its own. */
    protected static function commonSchema(bool $senderId = true): array
    {
        return [
            ['key' => 'from', 'label' => $senderId ? 'Send from (number or sender ID)' : 'Send from (number)', 'type' => 'text', 'required' => true, 'help' => $senderId
                ? 'A number you own at this provider, or a short name where the destination country allows one. Sender IDs need registration in most countries and cannot receive replies.'
                : 'A number you own at this provider, in full international form.'],
            ['key' => 'default_country', 'label' => 'Default dialling code', 'type' => 'text', 'help' => 'For members who typed a national number, e.g. 44. A national number with no code here is refused rather than guessed at.'],
        ];
    }

    /**
     * Basic authentication, which half of these providers use.
     */
    protected static function basic(string $user, string $password): string
    {
        return 'Basic ' . base64_encode($user . ':' . $password);
    }
}
