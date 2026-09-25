<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\App;
use App\Core\Db;
use App\Core\Id;

/**
 * Turns a verified Identity into a signed-in member, or into a refusal.
 *
 * Whatever the provider, this is the same four steps: decideLinking picks
 * the member (sub first; email only when verified); authorizeIdentity decides
 * whether they may come in; the member and identity rows are written; and
 * only then is the session cookie issued, on a fresh id. A refusal records
 * why and issues nothing.
 */
final class SignIn
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param 'LOGIN'|'SIGNUP' $type
     * @return array{ok: bool, userId: ?string, reason: ?string}
     */
    public function complete(Identity $identity, string $type = 'LOGIN'): array
    {
        $db = $this->app->db();
        $email = Authorization::normalizeEmail($identity->email);

        $bySub = $db->value('SELECT user_id FROM {{user_identities}} WHERE sub = ?', [$identity->sub]);
        $byEmail = $db->value('SELECT id FROM {{users}} WHERE email = ?', [$email]);
        $decision = IdentityLinking::decideLinking(
            $bySub === null ? null : (string) $bySub,
            $byEmail === null ? null : (string) $byEmail,
            $identity->emailVerified,
        );
        if ($decision['action'] === 'refuse') {
            $this->refuse($identity, $type, Authorization::EMAIL_NOT_AUTHORIZED, false, false, 'Unverified email matches an existing member.');
            return ['ok' => false, 'userId' => null, 'reason' => 'refused'];
        }

        $verdict = Access::authorization($this->app)->authorizeIdentity($email, $identity->membership, $identity->membershipApplicable);
        if (!$verdict['allowed']) {
            $this->refuse($identity, $type, (string) $verdict['reason'], $verdict['organizationMember'], $verdict['emailAuthorized']);
            return ['ok' => false, 'userId' => null, 'reason' => 'refused'];
        }

        $isBootstrapAdmin = in_array($email, Access::bootstrapAdmins($this->app), true);
        $userId = $db->transaction(function (Db $db) use ($decision, $identity, $email, $isBootstrapAdmin) {
            $userId = $decision['userId'];
            if ($decision['action'] === 'create') {
                $userId = $db->insert('users', [
                    'email' => $email,
                    'name' => $identity->name,
                    'picture' => $identity->picture,
                    'role' => $isBootstrapAdmin ? 'ADMIN' : 'MEMBER',
                    'authorized' => true,
                    'email_verified_at' => $identity->emailVerified ? Db::now() : null,
                    'auth0_id' => $identity->provider === 'auth0' ? $identity->sub : null,
                ]);
            } else {
                $set = ['authorized' => true];
                if ($identity->name !== null && $identity->name !== '') {
                    $set['name'] = mb_substr($identity->name, 0, 255);
                }
                if ($identity->picture !== null) {
                    $set['picture'] = mb_substr($identity->picture, 0, 2000);
                }
                if ($isBootstrapAdmin) {
                    $set['role'] = 'ADMIN';
                }
                if ($identity->provider === 'auth0') {
                    $set['auth0_id'] = $identity->sub;
                }
                $db->update('users', $set, ['id' => $userId]);
            }
            $db->run(
                'INSERT INTO {{user_identities}} (id, user_id, sub, provider, email, email_verified, last_login_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE email = VALUES(email), email_verified = VALUES(email_verified), last_login_at = VALUES(last_login_at)',
                [Id::new(), $userId, $identity->sub, mb_substr($identity->provider, 0, 64), $email, $identity->emailVerified, Db::now()],
            );
            return (string) $userId;
        });

        $session = $this->app->session();
        $session->login($userId);
        $session->set('identity', $identity->toSession());
        \App\Core\Cache::forgetMemo();
        $this->app->hooks->do('user.signed_in', $userId, $identity);
        return ['ok' => true, 'userId' => $userId, 'reason' => null];
    }

    private function refuse(Identity $identity, string $type, string $reason, bool $org, bool $email, ?string $detail = null): void
    {
        $request = $this->app->request();
        AccessAttempts::record($this->app->db(), [
            'email' => $identity->email,
            'sub' => $identity->sub,
            'provider' => $identity->provider,
            'type' => $type,
            'organizationMember' => $org,
            'emailAuthorized' => $email,
            'reason' => $reason,
            'detail' => $detail,
        ], $request->ip, $request->header('user-agent'), Access::mailer($this->app), Access::bootstrapAdmins($this->app));
        $this->app->hooks->do('auth.refused', ['email' => $identity->email, 'provider' => $identity->provider, 'reason' => $reason]);
    }
}
