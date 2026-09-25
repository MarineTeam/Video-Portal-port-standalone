<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\App;
use App\Core\Log;

/**
 * The choke point every page and API goes through, once per request.
 *
 * The session only says who somebody claimed to be when they signed in; the
 * allowlist and the role are read from the database on every request, so
 * removing an address takes effect on that person's next request, cookie or
 * no cookie. A session that no longer passes is destroyed and recorded.
 */
final class CurrentUser
{
    private bool $loaded = false;

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private bool $revoked = false;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed>|null the users row, or null for a visitor */
    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        try {
            $session = $this->app->session();
        } catch (\Throwable $e) {
            Log::error('Session unavailable: ' . $e->getMessage());
            return null;
        }
        $userId = $session->userId();
        if ($userId === null) {
            return null;
        }
        $row = $this->app->db()->one('SELECT * FROM {{users}} WHERE id = ?', [$userId]);
        if ($row === null) {
            $session->destroy();
            return null;
        }

        $identity = $session->get('identity');
        $identity = is_array($identity) ? $identity : [];
        $verdict = Access::authorization($this->app)->authorizeIdentity(
            (string) $row['email'],
            isset($identity['membership']) ? (string) $identity['membership'] : null,
            (bool) ($identity['membershipApplicable'] ?? false),
        );
        if (!$verdict['allowed']) {
            $this->revoked = true;
            $session->destroy();
            if ((bool) $row['authorized']) {
                $this->app->db()->update('users', ['authorized' => false], ['id' => $row['id']]);
            }
            $request = $this->app->request();
            AccessAttempts::record($this->app->db(), [
                'email' => (string) $row['email'],
                'sub' => $identity['sub'] ?? null,
                'provider' => $identity['provider'] ?? null,
                'type' => 'SESSION',
                'organizationMember' => $verdict['organizationMember'],
                'emailAuthorized' => $verdict['emailAuthorized'],
                'reason' => (string) $verdict['reason'],
            ], $request->ip, $request->header('user-agent'));
            return null;
        }
        if (!(bool) $row['authorized']) {
            $this->app->db()->update('users', ['authorized' => true], ['id' => $row['id']]);
            $row['authorized'] = 1;
        }
        $this->user = $this->app->hooks->apply('user.resolved', $row);
        $this->app->request()->withAttribute('userId', $row['id']);
        return $this->user;
    }

    public function id(): ?string
    {
        $user = $this->user();
        return $user === null ? null : (string) $user['id'];
    }

    public function email(): ?string
    {
        $user = $this->user();
        return $user === null ? null : (string) $user['email'];
    }

    public function isSignedIn(): bool
    {
        return $this->user() !== null;
    }

    /** True when this request arrived with a session that has just been refused. */
    public function wasRevoked(): bool
    {
        $this->user();
        return $this->revoked;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? null) === 'ADMIN';
    }

    /** @param array{categoryId?: ?string, seriesId?: ?string}|null $scope */
    public function can(string $capability, ?array $scope = null): bool
    {
        return $this->app->permissions()->has($this->user(), $capability, $scope);
    }

    public function canAnywhere(string $capability): bool
    {
        return $this->app->permissions()->hasAnywhere($this->user(), $capability);
    }

    public function isStaff(): bool
    {
        return $this->app->permissions()->isStaff($this->user());
    }

    /** The name shown for this member: their chosen one, then their provider's, then the address. */
    public function displayName(): string
    {
        $user = $this->user();
        if ($user === null) {
            return '';
        }
        // A chosen display name counts only while the Profiles plugin is on.
        $fields = \App\Modules\Plugins\PluginStates::enabled($this->app->db(), 'profiles') ? ['display_name', 'name'] : ['name'];
        foreach ($fields as $field) {
            if (is_string($user[$field] ?? null) && trim($user[$field]) !== '') {
                return trim($user[$field]);
            }
        }
        return (string) $user['email'];
    }
}
