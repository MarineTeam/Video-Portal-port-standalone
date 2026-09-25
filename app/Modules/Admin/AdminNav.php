<?php

declare(strict_types=1);

namespace App\Modules\Admin;

/**
 * The admin sidebar: every section with the capability that reveals it. A
 * section is shown only to someone who can use it, and a group whose every
 * link is hidden is dropped, so no heading stands over nothing.
 *
 * 'admin' means ADMIN only; a list means any one of those capabilities.
 */
final class AdminNav
{
    /** @return list<array{label: string, links: list<array{href: string, label: string, needs: string|list<string>}>}> */
    public static function groups(): array
    {
        $content = ['manage_categories', 'manage_series', 'manage_videos', 'manage_files'];
        return [
            ['label' => 'Overview', 'links' => [
                ['href' => '/admin', 'label' => 'Dashboard', 'needs' => 'admin'],
                ['href' => '/admin/analytics', 'label' => 'Analytics', 'needs' => 'view_analytics'],
            ]],
            ['label' => 'Library', 'links' => [
                ['href' => '/admin/categories', 'label' => 'Categories', 'needs' => 'admin'],
                ['href' => '/admin/series', 'label' => 'Series', 'needs' => 'manage_series'],
                ['href' => '/admin/videos', 'label' => 'Videos', 'needs' => 'manage_videos'],
                ['href' => '/admin/files', 'label' => 'Files', 'needs' => 'manage_files'],
                ['href' => '/admin/speakers', 'label' => 'Speakers', 'needs' => 'manage_videos'],
                ['href' => '/admin/video-feeds', 'label' => 'Video feeds', 'needs' => 'manage_videos'],
                ['href' => '/admin/home-rows', 'label' => 'Homepage rows', 'needs' => 'manage_plugins'],
                ['href' => '/admin/media-check', 'label' => 'Media check', 'needs' => 'manage_videos'],
                ['href' => '/admin/trash', 'label' => 'Trash', 'needs' => $content],
            ]],
            ['label' => 'Church life', 'links' => [
                ['href' => '/admin/services', 'label' => 'Service plans', 'needs' => 'manage_files'],
                ['href' => '/admin/teams', 'label' => 'Teams', 'needs' => 'manage_files'],
                ['href' => '/admin/schedules', 'label' => 'Schedules', 'needs' => 'manage_events'],
                ['href' => '/admin/people', 'label' => 'People', 'needs' => 'manage_events'],
                ['href' => '/admin/events', 'label' => 'Events', 'needs' => 'manage_events'],
                ['href' => '/admin/forms', 'label' => 'Forms', 'needs' => 'manage_events'],
                ['href' => '/admin/groups', 'label' => 'Small groups', 'needs' => 'manage_events'],
                ['href' => '/admin/guides', 'label' => 'Discussion guides', 'needs' => 'manage_events'],
                ['href' => '/admin/prayer', 'label' => 'Prayer wall', 'needs' => 'moderate_prayer'],
                ['href' => '/admin/broadcasts', 'label' => 'Broadcasts', 'needs' => 'manage_events'],
                ['href' => '/admin/live', 'label' => 'Live streams', 'needs' => 'manage_plugins'],
            ]],
            ['label' => 'People and access', 'links' => [
                ['href' => '/admin/users', 'label' => 'Members & roles', 'needs' => 'manage_users'],
                ['href' => '/admin/authorized-emails', 'label' => 'Who can sign in', 'needs' => 'manage_users'],
                ['href' => '/admin/access-attempts', 'label' => 'Access attempts', 'needs' => 'view_audit_log'],
                ['href' => '/admin/permissions', 'label' => 'Permissions', 'needs' => 'manage_permissions'],
                ['href' => '/admin/share-links', 'label' => 'Share links', 'needs' => 'share_content'],
                ['href' => '/admin/comments', 'label' => 'Comments', 'needs' => 'moderate_comments'],
                ['href' => '/admin/audit', 'label' => 'Audit log', 'needs' => 'view_audit_log'],
                ['href' => '/admin/api-keys', 'label' => 'API keys', 'needs' => 'manage_api_keys'],
            ]],
            ['label' => 'Site', 'links' => [
                ['href' => '/admin/plugins', 'label' => 'Plugins', 'needs' => 'manage_plugins'],
                ['href' => '/admin/branding', 'label' => 'Branding', 'needs' => 'admin'],
                ['href' => '/admin/appearance', 'label' => 'Appearance', 'needs' => 'admin'],
                ['href' => '/admin/announcements', 'label' => 'Announcements', 'needs' => 'manage_plugins'],
                ['href' => '/admin/downloads', 'label' => 'Downloads', 'needs' => 'manage_plugins'],
                ['href' => '/admin/webhooks', 'label' => 'Webhooks', 'needs' => 'manage_plugins'],
                ['href' => '/admin/query-monitor', 'label' => 'Query monitor', 'needs' => 'manage_plugins'],
            ]],
            ['label' => 'System', 'links' => [
                ['href' => '/admin/providers', 'label' => 'Services', 'needs' => 'admin'],
                ['href' => '/admin/jobs', 'label' => 'Scheduled jobs', 'needs' => 'admin'],
                ['href' => '/admin/email', 'label' => 'Email log', 'needs' => 'admin'],
                ['href' => '/admin/logs', 'label' => 'Logs', 'needs' => 'admin'],
                ['href' => '/admin/system', 'label' => 'System', 'needs' => 'admin'],
                ['href' => '/admin/update', 'label' => 'Update', 'needs' => 'admin'],
                ['href' => '/admin/tools', 'label' => 'Backup & import', 'needs' => 'admin'],
            ]],
        ];
    }

    /**
     * The groups this person sees.
     *
     * @param callable(string): bool $can whether they hold a capability anywhere
     * @return list<array{label: string, links: list<array{href: string, label: string}>}>
     */
    public static function groupsFor(bool $isAdmin, callable $can): array
    {
        $out = [];
        $seen = [];
        foreach (self::groups() as $group) {
            $links = [];
            foreach ($group['links'] as $link) {
                // Service plans and Admin → Services share nothing but a word;
                // the second is listed under System with its own path.
                if (isset($seen[$link['href'] . '|' . $link['label']])) {
                    continue;
                }
                $needs = $link['needs'];
                $visible = $isAdmin || ($needs !== 'admin' && array_filter((array) $needs, $can) !== []);
                if ($visible) {
                    $seen[$link['href'] . '|' . $link['label']] = true;
                    $links[] = ['href' => $link['href'], 'label' => $link['label']];
                }
            }
            if ($links !== []) {
                $out[] = ['label' => $group['label'], 'links' => $links];
            }
        }
        return $out;
    }

    /** The section a path belongs to: the longest link that prefixes it. */
    public static function currentLabel(string $path): string
    {
        $best = null;
        $bestLength = -1;
        foreach (self::groups() as $group) {
            foreach ($group['links'] as $link) {
                $href = $link['href'];
                if (($path === $href || str_starts_with($path, $href . '/')) && strlen($href) > $bestLength) {
                    $best = $link['label'];
                    $bestLength = strlen($href);
                }
            }
        }
        return $best ?? 'Admin';
    }
}
