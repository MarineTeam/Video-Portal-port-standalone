<?php

declare(strict_types=1);

namespace App\Modules\Access;

/** Where the allowlist lives; a fake in tests, the database in the app. */
interface AllowlistStore
{
    /** @return array{status: string, organizationExempt: bool}|null */
    public function find(string $normalizedEmail): ?array;

    /**
     * Records a bootstrap administrator's address as an ordinary, visible,
     * suspendable row — never exempt.
     *
     * @return array{status: string, organizationExempt: bool}
     */
    public function adopt(string $normalizedEmail): array;
}
