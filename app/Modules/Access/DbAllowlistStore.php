<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Db;

final class DbAllowlistStore implements AllowlistStore
{
    public function __construct(private readonly Db $db)
    {
    }

    public function find(string $normalizedEmail): ?array
    {
        $row = $this->db->one('SELECT status, organization_exempt FROM {{authorized_emails}} WHERE email = ?', [$normalizedEmail]);
        return $row === null ? null : ['status' => (string) $row['status'], 'organizationExempt' => (bool) $row['organization_exempt']];
    }

    public function adopt(string $normalizedEmail): array
    {
        try {
            $this->db->insert('authorized_emails', [
                'email' => $normalizedEmail,
                'status' => 'ACTIVE',
                'organization_exempt' => false,
                'note' => 'Bootstrap administrator',
                'added_by_email' => 'system',
            ]);
        } catch (\Throwable $e) {
            if (!Db::isDuplicate($e)) {
                throw $e;
            }
        }
        return $this->find($normalizedEmail) ?? ['status' => 'ACTIVE', 'organizationExempt' => false];
    }
}
