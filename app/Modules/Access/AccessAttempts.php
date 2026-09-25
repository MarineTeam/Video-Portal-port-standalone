<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Db;
use App\Core\Log;
use App\Core\Url;
use App\Services\Email\Mailer;
use App\Services\Email\Message;

/**
 * Refused sign-ins, sign-ups and revoked sessions. No credential material of
 * any kind: who was refused and why. The first refusal for an address emails
 * the administrators; that address is then left alone for an hour, and no
 * more than ten alerts go out an hour overall. Pruned after 90 days.
 */
final class AccessAttempts
{
    public const RETENTION_DAYS = 90;
    public const PER_ADDRESS_COOLDOWN = 3600;
    public const MAX_ALERTS_PER_HOUR = 10;

    /**
     * @param array{email?: ?string, sub?: ?string, provider?: ?string, type: string, organizationMember?: bool, emailAuthorized?: bool, reason: string, detail?: ?string} $attempt
     */
    public static function record(Db $db, array $attempt, ?string $ip, ?string $userAgent, ?Mailer $mailer = null, array $adminEmails = []): void
    {
        $email = isset($attempt['email']) ? Authorization::normalizeEmail((string) $attempt['email']) : null;
        try {
            $id = $db->insert('unauthorized_access_attempts', [
                'email' => $email,
                'auth0_user_id' => isset($attempt['sub']) ? mb_substr((string) $attempt['sub'], 0, 255) : null,
                'provider' => $attempt['provider'] ?? null,
                'attempt_type' => $attempt['type'],
                'organization_member' => (bool) ($attempt['organizationMember'] ?? false),
                'email_authorized' => (bool) ($attempt['emailAuthorized'] ?? false),
                'reason' => $attempt['reason'],
                'detail' => isset($attempt['detail']) ? mb_substr(Log::mask((string) $attempt['detail']), 0, 2000) : null,
                'ip_address' => $ip === null ? null : substr($ip, 0, 45),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not record an access attempt: ' . $e->getMessage());
            return;
        }
        if ($mailer === null || $adminEmails === [] || $email === null || !$mailer->isConfigured()) {
            return;
        }
        $hourAgo = Db::datetime(new \DateTimeImmutable('-' . self::PER_ADDRESS_COOLDOWN . ' seconds'));
        $recent = (int) $db->value('SELECT COUNT(*) FROM {{unauthorized_access_attempts}} WHERE email = ? AND notified_at > ?', [$email, $hourAgo]);
        $overall = (int) $db->value('SELECT COUNT(*) FROM {{unauthorized_access_attempts}} WHERE notified_at > ?', [$hourAgo]);
        if ($recent > 0 || $overall >= self::MAX_ALERTS_PER_HOUR) {
            return;
        }
        $db->update('unauthorized_access_attempts', ['notified_at' => Db::now()], ['id' => $id]);
        $text = "Somebody was refused access to the site.\n\nAddress: $email\nReason: {$attempt['reason']}\n\nIf they should be let in, add their address to “Who can sign in”.";
        foreach ($adminEmails as $admin) {
            try {
                $mailer->send(Message::plain($admin, 'Refused sign-in: ' . $email, $text, Url::absolute('/admin/access-attempts'), 'Review access attempts'));
            } catch (\Throwable $e) {
                Log::warning('Could not email an admin about a refusal: ' . $e->getMessage());
            }
        }
    }

    public static function prune(Db $db): int
    {
        return $db->run(
            'DELETE FROM {{unauthorized_access_attempts}} WHERE created_at < ?',
            [Db::datetime(new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))],
        )->rowCount();
    }
}
