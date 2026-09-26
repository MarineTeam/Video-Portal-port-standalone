<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\ServiceProvider;

/**
 * One way of sending a text.
 *
 * A church picks a texting provider by country and price the way it picks an
 * email provider, and switches it for the same reasons — so this is its own
 * slot rather than a settings group. What is particular to a provider is its
 * wire format, its sender kind, and how it signs the callbacks it sends back;
 * everything provider-independent (normalising a number, counting segments,
 * who may be texted at all) lives outside.
 */
interface SmsProvider extends ServiceProvider
{
    /** @throws SmsError with the provider's own sentence about why not */
    public function send(string $to, string $body): SmsResult;

    /**
     * Whether this provider appends the opt-out instruction itself. When it
     * does not, the app adds the one the destination country expects.
     */
    public static function appendsOptOut(): bool;

    /**
     * Whether this provider signs the callbacks it sends.
     *
     * One that does not gets a per-install secret in its callback address
     * instead — which is why the address is shown on the settings screen
     * rather than left for somebody to guess at.
     */
    public static function signsCallbacks(): bool;

    /**
     * Whether a callback this provider sent is genuine.
     *
     * Each scheme is a few lines of pure PHP: Twilio's X-Twilio-Signature,
     * Vonage's signed JWT, MessageBird's signature header, Telnyx's Ed25519.
     *
     * @param array<string, string> $headers lower-cased names
     */
    public function verifyCallback(string $url, string $rawBody, array $headers): bool;

    /**
     * What a delivery receipt means, in the app's words.
     *
     * @param array<string, mixed> $payload the callback's body
     * @return array{messageId: ?string, status: ?string, reason: ?string} status: DELIVERED|FAILED|null
     */
    public function readReceipt(array $payload): array;

    /**
     * A reply, if this provider forwards them.
     *
     * @param array<string, mixed> $payload
     * @return array{from: ?string, body: ?string}
     */
    public function readInbound(array $payload): array;
}
