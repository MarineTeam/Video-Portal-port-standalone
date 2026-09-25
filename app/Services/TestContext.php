<?php

declare(strict_types=1);

namespace App\Services;

/** What a provider's test may need to know: who is testing, and from where. */
final class TestContext
{
    public function __construct(
        public readonly string $adminEmail,
        public readonly bool $https,
        public readonly string $baseUrl,
        /** Extra input the test asks for: a phone number, a share link to resolve. */
        public readonly array $input = [],
    ) {
    }
}
