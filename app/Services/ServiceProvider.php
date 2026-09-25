<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One implementation of a service slot. The admin form for a provider is
 * generated from configSchema(); its test() is what the Services screen runs
 * before it will switch to it.
 */
interface ServiceProvider
{
    /** 'auth' | 'video' | 'email' | 'files' | 'sms' | a plugin's slot */
    public static function slot(): string;

    public static function id(): string;

    public static function label(): string;

    /**
     * The fields of the provider's settings form.
     *
     * @return list<array{key: string, label: string, type: string, secret?: bool, help?: string, required?: bool, options?: array<int|string, string>, default?: mixed}>
     */
    public static function configSchema(): array;

    public static function requiresHttps(): bool;

    public static function requiresOutboundHttps(): bool;

    /**
     * Origins the Content Security Policy must allow while this provider is
     * active, by directive: ['frame' => ['https://player.vimeo.com'], ...].
     *
     * @return array<string, list<string>>
     */
    public static function cspSources(): array;

    /** A sentence for the Services screen about what it can't do. */
    public static function limits(): string;

    /** @param array<string, mixed> $config */
    public function __construct(array $config);

    public function test(TestContext $context): TestResult;
}
