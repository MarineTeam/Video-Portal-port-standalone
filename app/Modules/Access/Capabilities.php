<?php

declare(strict_types=1);

namespace App\Modules\Access;

/** The fixed set of grantable capabilities (Appendix E.2); plugins may add more. */
final class Capabilities
{
    /** @var array<string, array{label: string, hint: string, siteWideOnly: bool}> */
    private static array $all = [
        'manage_categories' => ['label' => 'Manage categories', 'hint' => 'Create, reorder, and delete categories', 'siteWideOnly' => true],
        'manage_series' => ['label' => 'Manage series', 'hint' => 'Create, edit, and delete series', 'siteWideOnly' => false],
        'manage_videos' => ['label' => 'Manage videos', 'hint' => 'Upload, edit, and delete videos', 'siteWideOnly' => false],
        'manage_files' => ['label' => 'Manage files', 'hint' => 'Upload, edit, and delete files', 'siteWideOnly' => false],
        'publish_content' => ['label' => 'Publish content', 'hint' => 'Publish/unpublish, feature, and pin content', 'siteWideOnly' => false],
        'moderate_comments' => ['label' => 'Moderate comments', 'hint' => 'Delete or hide any comment, not just your own', 'siteWideOnly' => false],
        'share_content' => ['label' => 'Share restricted content', 'hint' => 'Create share links that grant access to member-only or restricted content', 'siteWideOnly' => false],
        'manage_users' => ['label' => 'Manage users', 'hint' => 'Grant access and change roles', 'siteWideOnly' => true],
        'manage_permissions' => ['label' => 'Manage permissions', 'hint' => 'Create groups and assign them to users', 'siteWideOnly' => true],
        'manage_plugins' => ['label' => 'Manage plugins', 'hint' => 'Enable or disable optional features', 'siteWideOnly' => true],
        'manage_events' => ['label' => 'Manage events, forms and groups', 'hint' => 'Publish events and see who signed up, build forms, and keep the small-group list', 'siteWideOnly' => true],
        'moderate_prayer' => ['label' => 'Moderate the prayer wall', 'hint' => 'Approve, hide and mark answered the requests members post', 'siteWideOnly' => true],
        'view_audit_log' => ['label' => 'View audit log', 'hint' => 'See the history of admin/editor actions', 'siteWideOnly' => true],
        'manage_api_keys' => ['label' => 'Manage API keys', 'hint' => 'Create and revoke keys that let another system read this one', 'siteWideOnly' => true],
        'view_analytics' => ['label' => 'View analytics', 'hint' => 'See the views dashboard and trending content', 'siteWideOnly' => true],
    ];

    /** What the simpler per-category/per-series "content editor" grant confers. */
    public const EDITOR_GRANT = ['manage_series', 'manage_videos', 'manage_files', 'publish_content'];

    /** @return array<string, array{label: string, hint: string, siteWideOnly: bool}> */
    public static function all(): array
    {
        return self::$all;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::$all);
    }

    public static function exists(string $key): bool
    {
        return isset(self::$all[$key]);
    }

    public static function siteWideOnly(string $key): bool
    {
        return self::$all[$key]['siteWideOnly'] ?? true;
    }

    /** capabilities.register: a plugin adds its own. ADMIN can never be one. */
    public static function register(string $key, string $label, string $hint, bool $siteWideOnly = true): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $key) || $key === 'admin' || isset(self::$all[$key])) {
            return;
        }
        self::$all[$key] = ['label' => $label, 'hint' => $hint, 'siteWideOnly' => $siteWideOnly];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public static function sanitize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter($raw, fn ($k) => is_string($k) && self::exists($k))));
    }
}
