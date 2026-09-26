<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\Registry;
use App\Support\Sms;

/**
 * Everything that texts goes through here.
 *
 * Consent is the app's rule, not the provider's, and it is decided before
 * this is reached (planDelivery) — what is decided here is the shape of the
 * message: the number in E.164 or not sent at all, and the opt-out
 * instruction added whenever the provider does not append one itself.
 */
final class Texter
{
    /** What a text says about stopping, where the provider does not say it. */
    public const OPT_OUT = 'Reply STOP to stop.';

    public function __construct(private readonly Registry $services)
    {
    }

    public function isConfigured(): bool
    {
        return $this->services->activeId('sms') !== null;
    }

    public function provider(): ?SmsProvider
    {
        $provider = $this->services->active('sms');
        return $provider instanceof SmsProvider ? $provider : null;
    }

    /** The dialling code a national number is read with, from the provider's own settings. */
    public function defaultCountry(): ?string
    {
        $id = $this->services->activeId('sms');
        $config = $id === null ? [] : $this->services->savedConfig('sms', $id);
        $code = trim((string) ($config['default_country'] ?? ''));
        return $code === '' ? null : $code;
    }

    /**
     * Send one text.
     *
     * @throws SmsError
     */
    public function send(string $to, string $body): SmsResult
    {
        $provider = $this->provider();
        if ($provider === null) {
            throw new SmsError('Texting is not set up.');
        }
        $number = Sms::normalizePhone($to, $this->defaultCountry());
        if ($number === null) {
            throw SmsError::permanent('That is not a number this site can text.');
        }
        return $provider->send($number, $this->withOptOut($body, $provider));
    }

    /**
     * A text carries the opt-out instruction its destination expects whenever
     * the provider does not append one itself — and never twice, because the
     * second one is charged for like any other characters.
     */
    public function withOptOut(string $body, ?SmsProvider $provider = null): string
    {
        $provider ??= $this->provider();
        if ($provider !== null && $provider::appendsOptOut()) {
            return $body;
        }
        if (preg_match('/\bSTOP\b/i', $body) === 1) {
            return $body;
        }
        return rtrim($body) . ' ' . self::OPT_OUT;
    }

    /** What one message will cost, counted the way the carrier charges. */
    public function cost(string $body): array
    {
        return Sms::segments($this->withOptOut($body));
    }
}
