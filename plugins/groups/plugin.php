<?php
/**
 * Plugin Name: Small groups
 * Slug:        groups
 * Version:     1.0.0
 * Description: A directory of home groups at /groups, with join requests a leader answers. The address is given only to people in the group.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Groups.php';
require_once __DIR__ . '/src/Attendance.php';
require_once __DIR__ . '/src/Guides.php';
require_once __DIR__ . '/src/Thread.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Json;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Plugins\PluginStates;
use App\Modules\Profile\Inbox;
use App\Modules\Push\Push;
use App\Support\Slug;
use MarineTeam\Plugins\Groups\Attendance;
use MarineTeam\Plugins\Groups\Groups;
use MarineTeam\Plugins\Groups\Guides;
use MarineTeam\Plugins\Groups\Thread;

/**
 * The home groups and studies that meet during the week, at /groups.
 *
 * Where it meets is two questions, not one: a district is on the page for
 * anybody, and the address is given only to people actually in the group.
 * Asking to join is a request the leader answers, and that is what keeps
 * the address safe rather than being politeness. A leader is not staff:
 * whoever hosts the Tuesday group answers the people who have asked, on
 * the group's own page.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $manage = Middleware::can($app, 'manage_events');

            $r->get('/groups', fn () => $this->listPage($app));
            $r->get('/groups/[slug]', fn (Request $req, array $p) => $this->groupPage($app, (string) $p['slug']));
            $r->get('/api/groups', fn () => Response::json($this->visibleGroups($app)));
            $r->post('/api/groups/[slug]/join', fn (Request $req, array $p) => $this->ask($app, $req, (string) $p['slug']), [$member]);
            $r->add('DELETE', '/api/groups/[slug]/join', fn (Request $req, array $p) => $this->leave($app, (string) $p['slug']), [$member]);
            $r->get('/api/groups/[slug]/requests', fn (Request $req, array $p) => $this->requests($app, (string) $p['slug']), [$member]);
            $r->add('PATCH', '/api/groups/[slug]/requests/[memberId]', fn (Request $req, array $p) => $this->answer($app, $req, (string) $p['slug'], (string) $p['memberId']), [$member]);
            $r->get('/api/groups/[slug]/messages', fn (Request $req, array $p) => $this->readThread($app, $req, (string) $p['slug']), [$member]);
            $r->post('/api/groups/[slug]/messages', fn (Request $req, array $p) => $this->say($app, $req, (string) $p['slug']), [$member]);
            $r->add('PATCH', '/api/groups/[slug]/messages', fn (Request $req, array $p) => $this->mute($app, $req, (string) $p['slug']), [$member]);
            $r->add('DELETE', '/api/groups/[slug]/messages/[messageId]', fn (Request $req, array $p) => $this->removeMessage($app, (string) $p['slug'], (string) $p['messageId']), [$member]);
            $r->get('/api/groups/[slug]/meetings', fn (Request $req, array $p) => $this->meetings($app, (string) $p['slug']), [$member]);
            $r->post('/api/groups/[slug]/meetings', fn (Request $req, array $p) => $this->recordRoll($app, $req, (string) $p['slug']), [$member]);
            $r->get('/profile/groups', fn () => $this->minePage($app), [$member]);

            $r->get('/guides', fn () => $this->guidesPage($app));
            $r->get('/guides/[slug]', fn (Request $req, array $p) => $this->guidePage($app, (string) $p['slug']));

            $r->get('/admin/groups', fn () => $app->page('groups/admin', ['title' => 'Small groups', 'rows' => $this->adminGroups($app)], 200, 'layouts/admin'), [$manage]);
            $r->get('/admin/groups/[id]', fn (Request $req, array $p) => $this->adminGroupPage($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/groups', fn () => Response::json($this->adminGroups($app)), [$manage]);
            $r->get('/api/admin/groups/[id]', fn (Request $req, array $p) => Response::json($this->adminGroup($app, (string) $p['id'])), [$manage]);
            $r->post('/api/admin/groups/[id]/members', fn (Request $req, array $p) => $this->addMember($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/groups/[id]/members/[memberId]', fn (Request $req, array $p) => $this->removeMember($app, (string) $p['id'], (string) $p['memberId']), [$manage]);
            $r->post('/api/admin/groups', fn (Request $req) => $this->saveGroup($app, $req, null), [$manage]);
            $r->add('PATCH', '/api/admin/groups/[id]', fn (Request $req, array $p) => $this->saveGroup($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/groups/[id]', fn (Request $req, array $p) => $this->deleteGroup($app, (string) $p['id']), [$manage]);
            $r->get('/admin/guides', fn () => $app->page('groups/admin-guides', ['title' => 'Discussion guides', 'rows' => $this->adminGuides($app), 'kinds' => Guides::KINDS], 200, 'layouts/admin'), [$manage]);
            $r->get('/api/admin/guides', fn () => Response::json($this->adminGuides($app)), [$manage]);
            $r->get('/api/admin/guides/[id]', function (Request $req, array $p) use ($app): Response {
                foreach ($this->adminGuides($app) as $guide) {
                    if ((string) $guide['id'] === (string) $p['id']) {
                        return Response::json($guide);
                    }
                }
                throw ApiError::notFound();
            }, [$manage]);
            $r->post('/api/admin/guides', fn (Request $req) => $this->saveGuide($app, $req, null), [$manage]);
            $r->add('PATCH', '/api/admin/guides/[id]', fn (Request $req, array $p) => $this->saveGuide($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/guides/[id]', fn (Request $req, array $p) => $this->deleteGuide($app, (string) $p['id']), [$manage]);
            $r->post('/api/admin/guides/[id]/items', fn (Request $req, array $p) => $this->saveGuideItem($app, $req, (string) $p['id'], null), [$manage]);
            $r->add('PATCH', '/api/admin/guides/[id]/items/[itemId]', fn (Request $req, array $p) => $this->saveGuideItem($app, $req, (string) $p['id'], (string) $p['itemId']), [$manage]);
            $r->add('DELETE', '/api/admin/guides/[id]/items/[itemId]', fn (Request $req, array $p) => $this->deleteGuideItem($app, (string) $p['id'], (string) $p['itemId']), [$manage]);
        });
        $hooks->filter('nav.sections', fn (array $nav) => [...$nav, ['href' => '/groups', 'label' => t('groups.title'), 'icon' => 'people']]);
        $hooks->filter('profile.sections', fn (array $sections, ?array $user) => $user === null ? $sections : [...$sections, ['href' => '/profile/groups', 'label' => t('groups.mine')]]);
        $hooks->filter('sitemap.urls', fn (array $urls) => [...$urls, '/groups', '/guides']);
        $hooks->filter('profile.overview', fn (array $cards, array $user) => [...$cards, [
            'title' => t('groups.mine'),
            'count' => (int) $app->db()->value('SELECT COUNT(*) FROM {{small_group_members}} WHERE user_id = ? AND status = ?', [$user['id'], Groups::ACTIVE]),
            'href' => '/profile/groups',
        ]]);
    }

    private function keepsTheList(App $app): bool
    {
        return $app->currentUser()->can('manage_events');
    }

    private function me(App $app): ?string
    {
        return $app->currentUser()->id();
    }

    private function today(): string
    {
        return gmdate('Y-m-d');
    }

    // -- Reading ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function find(Db $db, string $slug): array
    {
        $row = $db->one('SELECT * FROM {{small_groups}} WHERE slug = ?', [$slug]);
        return $row ?? throw ApiError::notFound();
    }

    /**
     * Every membership row of a group, with the name to show beside it.
     *
     * @return list<array<string, mixed>>
     */
    private function membersOf(Db $db, string $groupId): array
    {
        $profiles = PluginStates::enabled($db, 'profiles');
        $name = $profiles ? 'COALESCE(NULLIF(u.display_name, ""), u.name)' : 'u.name';
        $rows = $db->all(
            "SELECT m.*, $name AS name, u.email AS email FROM {{small_group_members}} m JOIN {{users}} u ON u.id = m.user_id
             WHERE m.group_id = ? ORDER BY m.created_at, m.id",
            [$groupId],
        );
        // A name is a name: never an address, wherever it came from.
        foreach ($rows as $i => $row) {
            $shown = trim((string) $row['name']);
            $rows[$i]['name'] = $shown === '' || str_contains($shown, '@') ? t('groups.aMember') : $shown;
        }
        return $rows;
    }

    /**
     * A group as this reader is given it, always through presentGroup —
     * the only thing that decides whether the address travels.
     *
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function present(App $app, array $group, ?array $members = null): array
    {
        $members ??= $this->membersOf($app->db(), (string) $group['id']);
        $me = $this->me($app);
        $shape = Groups::presentGroup($group, $members, $me, $this->keepsTheList($app));
        $shape['joinState'] = Groups::joinState($group, $members, $me);
        $shape['placesLeft'] = Groups::placesLeft($group, $members);
        if ($shape['standing'] === Groups::WAITLIST && $me !== null) {
            $shape['waitingPosition'] = Groups::waitingPosition($members, $me);
        }
        return $shape;
    }

    /** @return list<array<string, mixed>> */
    private function visibleGroups(App $app): array
    {
        $db = $app->db();
        $where = $this->keepsTheList($app) ? '1 = 1' : 'published = 1';
        return array_map(fn (array $g) => $this->present($app, $g), $db->all("SELECT * FROM {{small_groups}} WHERE $where ORDER BY name LIMIT 200"));
    }

    private function listPage(App $app): Response
    {
        return $app->page('groups/list', [
            'title' => t('groups.title'),
            // Behind sign-in for the names, but the list itself is public:
            // "which groups are there" is what somebody is choosing between.
            'groups' => $this->visibleGroups($app),
        ]);
    }

    private function groupPage(App $app, string $slug): Response
    {
        $db = $app->db();
        $group = $this->find($db, $slug);
        if (!$group['published'] && !$this->keepsTheList($app)) {
            throw ApiError::notFound();
        }
        $members = $this->membersOf($db, (string) $group['id']);
        $me = $this->me($app);
        $shape = $this->present($app, $group, $members);
        $leads = Groups::canLead($members, $me, $this->keepsTheList($app));
        $inThread = Thread::inTheThread($members, $me);
        return $app->page('groups/group', [
            'title' => (string) $group['name'],
            'group' => $shape,
            'threadState' => Thread::threadState($members, $me),
            'messages' => $inThread ? Thread::visibleThread(Thread::latest($this->messagesOf($db, (string) $group['id'])), $members, $me, $this->keepsTheList($app)) : [],
            'muted' => $this->isMuted($members, $me),
            'requests' => $leads ? $this->requestRows($members) : [],
            'canLead' => $leads,
            'meetings' => $inThread ? $this->meetingRows($app, $group, $members) : [],
            'quietlyMissing' => $leads ? $this->quietlyMissing($app, (string) $group['id'], $members) : [],
            'guides' => $leads ? $db->all('SELECT id, title FROM {{discussion_guides}} WHERE published = 1 ORDER BY updated_at DESC LIMIT 50') : [],
            // Only a leader is given anybody's id, and only to mark a roll with.
            'rollMembers' => $leads ? array_map(fn (array $m) => ['userId' => (string) $m['user_id'], 'name' => (string) $m['name']], Groups::activeMembers($members)) : [],
            'today' => $this->today(),
            'script' => $this->asset('groups.js'),
        ]);
    }

    private function minePage(App $app): Response
    {
        $db = $app->db();
        $rows = $db->all(
            'SELECT g.*, m.status AS my_status, m.muted AS my_muted FROM {{small_group_members}} m JOIN {{small_groups}} g ON g.id = m.group_id
             WHERE m.user_id = ? ORDER BY g.name',
            [$this->me($app)],
        );
        return $app->page('groups/mine', [
            'title' => t('groups.mine'),
            'sections' => (new \App\Modules\Profile\Routes($app))->sections(),
            'rows' => array_map(fn (array $g) => [
                'name' => (string) $g['name'],
                'slug' => (string) $g['slug'],
                'status' => (string) $g['my_status'],
                'muted' => (bool) $g['my_muted'],
            ], $rows),
        ]);
    }

    // -- Joining ----------------------------------------------------------

    /**
     * Asking to join is a request the leader answers. When the group is
     * full the name goes on the list instead, and the leader still decides
     * when a place appears — that decision is what the address travels with.
     */
    private function ask(App $app, Request $req, string $slug): Response
    {
        $db = $app->db();
        $group = $this->find($db, $slug);
        if (!$group['published']) {
            throw ApiError::notFound();
        }
        $data = Validator::check($req->input(), ['note' => ['text', 'nullable', 'max' => 1000]]);
        $userId = (string) $this->me($app);
        $status = $db->transaction(function (Db $db) use ($group, $userId, $data): string {
            $db->one('SELECT id FROM {{small_groups}} WHERE id = ? FOR UPDATE', [$group['id']]);
            $members = $this->membersOf($db, (string) $group['id']);
            $state = Groups::joinState($group, $members, $userId);
            if (in_array($state, [Groups::JOIN_IN, Groups::JOIN_ASKED, Groups::JOIN_WAITING], true) && Groups::standingIn($members, $userId) !== Groups::NONE) {
                throw ApiError::conflict(t('groups.join.' . strtolower($state)));
            }
            if (in_array($state, [Groups::JOIN_CLOSED, Groups::JOIN_FULL], true)) {
                throw ApiError::invalid(t('groups.join.' . strtolower($state)));
            }
            // A full group with a waiting list takes the name in order; the
            // ask only reaches the leader when there is a place for it.
            $status = $state === Groups::JOIN_WAITING ? Groups::WAITLIST : Groups::REQUESTED;
            $existing = $db->one('SELECT * FROM {{small_group_members}} WHERE group_id = ? AND user_id = ?', [$group['id'], $userId]);
            $row = ['status' => $status, 'note' => $data['note'] ?? null, 'responded_at' => null, 'role' => Groups::MEMBER];
            if ($existing !== null) {
                $db->update('small_group_members', $row, ['id' => $existing['id']]);
            } else {
                $db->insert('small_group_members', $row + ['id' => Id::new(), 'group_id' => $group['id'], 'user_id' => $userId]);
            }
            return $status;
        });
        $this->tellLeaders($app, $group, $status);
        return Response::json($this->present($app, $group) + ['status' => $status], 201);
    }

    /** @param array<string, mixed> $group */
    private function tellLeaders(App $app, array $group, string $status): void
    {
        if ($status !== Groups::REQUESTED) {
            return;
        }
        $db = $app->db();
        $leaders = array_map(
            fn (array $m) => (string) $m['user_id'],
            array_filter($this->membersOf($db, (string) $group['id']), fn (array $m) => (string) $m['status'] === Groups::ACTIVE && (string) $m['role'] === Groups::LEADER),
        );
        foreach ($leaders as $leaderId) {
            Inbox::add($db, $leaderId, t('groups.askedTitle'), (string) $group['name'], Url::absolute('/groups/' . $group['slug']));
        }
    }

    /** Leaving, or withdrawing a request: the row is kept, so asking again is a conversation. */
    private function leave(App $app, string $slug): Response
    {
        $db = $app->db();
        $group = $this->find($db, $slug);
        $userId = (string) $this->me($app);
        $mine = $db->one('SELECT * FROM {{small_group_members}} WHERE group_id = ? AND user_id = ?', [$group['id'], $userId]);
        if ($mine === null || (string) $mine['status'] === Groups::DECLINED) {
            throw ApiError::notFound();
        }
        $db->update('small_group_members', ['status' => Groups::DECLINED, 'role' => Groups::MEMBER, 'responded_at' => Db::now()], ['id' => $mine['id']]);
        $this->moveTheList($app, $group);
        return Response::json(['ok' => true]);
    }

    /**
     * A place opening moves the longest-waiting name to a request in front
     * of the leader. The leader still decides.
     *
     * @param array<string, mixed> $group
     */
    private function moveTheList(App $app, array $group): void
    {
        $db = $app->db();
        $moved = $db->transaction(function (Db $db) use ($group): array {
            $db->one('SELECT id FROM {{small_groups}} WHERE id = ? FOR UPDATE', [$group['id']]);
            $members = $this->membersOf($db, (string) $group['id']);
            $ids = Groups::promotable($group, $members);
            foreach ($ids as $id) {
                $db->update('small_group_members', ['status' => Groups::REQUESTED], ['id' => $id]);
            }
            return $ids;
        });
        if ($moved !== []) {
            $this->tellLeaders($app, $group, Groups::REQUESTED);
        }
    }

    /** @param list<array<string, mixed>> $members @return list<array<string, mixed>> */
    private function requestRows(array $members): array
    {
        $out = [];
        foreach ($members as $member) {
            if (in_array((string) $member['status'], [Groups::REQUESTED, Groups::WAITLIST], true)) {
                $out[] = [
                    'id' => (string) $member['id'],
                    'name' => (string) $member['name'],
                    'status' => (string) $member['status'],
                    'note' => $member['note'],
                    'askedAt' => Json::instant((string) $member['created_at']),
                ];
            }
        }
        return $out;
    }

    private function requests(App $app, string $slug): Response
    {
        $db = $app->db();
        $group = $this->find($db, $slug);
        $members = $this->membersOf($db, (string) $group['id']);
        if (!Groups::canLead($members, $this->me($app), $this->keepsTheList($app))) {
            throw ApiError::notFound();
        }
        return Response::json($this->requestRows($members));
    }

    /**
     * The leader's answer. Only a yes is a notification: a no is a
     * conversation, and a push saying "you were turned down" is the wrong
     * way for anybody to hear it.
     */
    private function answer(App $app, Request $req, string $slug, string $memberId): Response
    {
        $db = $app->db();
        $group = $this->find($db, $slug);
        $members = $this->membersOf($db, (string) $group['id']);
        if (!Groups::canLead($members, $this->me($app), $this->keepsTheList($app))) {
            throw ApiError::notFound();
        }
        $data = Validator::check($req->input(), [
            'status' => ['enum', 'required', 'enum' => [Groups::ACTIVE, Groups::DECLINED]],
            'role' => ['enum', 'nullable', 'enum' => [Groups::LEADER, Groups::MEMBER]],
        ]);
        $row = null;
        foreach ($members as $member) {
            if ((string) $member['id'] === $memberId) {
                $row = $member;
            }
        }
        if ($row === null) {
            throw ApiError::notFound();
        }
        $set = ['status' => (string) $data['status'], 'responded_at' => Db::now()];
        if (isset($data['role'])) {
            $set['role'] = (string) $data['role'];
        }
        $db->update('small_group_members', $set, ['id' => $row['id']]);
        if ($data['status'] === Groups::ACTIVE) {
            $url = Url::absolute('/groups/' . $group['slug']);
            Inbox::add($db, (string) $row['user_id'], t('groups.welcomeTitle'), (string) $group['name'], $url);
            Push::send($app, [(string) $row['user_id']], ['title' => t('groups.welcomeTitle'), 'body' => (string) $group['name'], 'url' => $url]);
        } else {
            $this->moveTheList($app, $group);
        }
        return Response::json(['status' => (string) $data['status']]);
    }

    // -- The conversation -------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function messagesOf(Db $db, string $groupId, ?string $since = null): array
    {
        $sql = 'SELECT * FROM {{group_messages}} WHERE group_id = ? AND hidden = 0';
        $params = [$groupId];
        if ($since !== null) {
            $sql .= ' AND id > ?';
            $params[] = $since;
        }
        return $db->all($sql . ' ORDER BY id DESC LIMIT ' . Thread::PAGE, $params);
    }

    /** @param list<array<string, mixed>> $members */
    private function isMuted(array $members, ?string $userId): bool
    {
        foreach ($members as $member) {
            if ($userId !== null && (string) $member['user_id'] === $userId) {
                return (bool) $member['muted'];
            }
        }
        return false;
    }

    /**
     * Standing is re-read from the database on every request rather than
     * carried, so somebody removed on Monday has a tab that stops working
     * on Tuesday.
     *
     * @return array{group: array<string, mixed>, members: list<array<string, mixed>>}
     */
    private function inGroup(App $app, string $slug): array
    {
        $db = $app->db();
        $group = $this->find($db, $slug);
        $members = $this->membersOf($db, (string) $group['id']);
        if (!Thread::inTheThread($members, $this->me($app))) {
            // A site manager outside the group gets nothing: an address is
            // an operational fact somebody running the site may need; a
            // conversation isn't.
            throw ApiError::notFound();
        }
        return ['group' => $group, 'members' => $members];
    }

    private function readThread(App $app, Request $req, string $slug): Response
    {
        ['group' => $group, 'members' => $members] = $this->inGroup($app, $slug);
        $data = Validator::check($req->query, ['since' => ['id', 'nullable']]);
        $rows = $this->messagesOf($app->db(), (string) $group['id'], isset($data['since']) ? (string) $data['since'] : null);
        return Response::json([
            'messages' => Thread::visibleThread(Thread::latest($rows), $members, $this->me($app), $this->keepsTheList($app)),
            'muted' => $this->isMuted($members, $this->me($app)),
        ]);
    }

    private function say(App $app, Request $req, string $slug): Response
    {
        ['group' => $group, 'members' => $members] = $this->inGroup($app, $slug);
        $db = $app->db();
        $data = Validator::check($req->input(), ['body' => ['text', 'required', 'min' => 1, 'max' => Thread::MAX_LENGTH * 4]]);
        $body = Thread::cleanGroupMessage((string) $data['body']);
        if ($body === null) {
            throw ApiError::invalid(t('groups.tooLong'));
        }
        $userId = (string) $this->me($app);
        $name = '';
        foreach ($members as $member) {
            if ((string) $member['user_id'] === $userId) {
                $name = trim((string) $member['name']);
            }
        }
        $id = $db->insert('group_messages', [
            'id' => Id::new(),
            'group_id' => $group['id'],
            'user_id' => $userId,
            'author_name' => $name === '' || str_contains($name, '@') ? t('groups.aMember') : mb_substr($name, 0, 255),
            'body' => $body,
        ]);
        $saved = (array) $db->one('SELECT * FROM {{group_messages}} WHERE id = ?', [$id]);
        $this->tellTheGroup($app, $group, $members, $userId, $body);
        return Response::json(Thread::visibleThread([$saved], $members, $userId, $this->keepsTheList($app))[0], 201);
    }

    /**
     * Notifications carry the first line only: a group thread is exactly
     * the place where the whole of a message shouldn't be sitting on a lock
     * screen.
     *
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $members
     */
    private function tellTheGroup(App $app, array $group, array $members, string $authorId, string $body): void
    {
        $told = Thread::notifiable($members, $authorId);
        if ($told === []) {
            return;
        }
        $db = $app->db();
        $title = t('groups.notifyTitle', ['group' => (string) $group['name']]);
        $line = Thread::firstLine($body);
        $url = Url::absolute('/groups/' . $group['slug']);
        foreach ($told as $userId) {
            Inbox::add($db, $userId, $title, $line, $url);
        }
        try {
            Push::send($app, $told, ['title' => $title, 'body' => $line, 'url' => $url]);
        } catch (\Throwable $e) {
            Log::warning('Group thread push failed: ' . $e->getMessage(), ['group' => $group['id']]);
        }
    }

    /** Mute keeps somebody in the group and stops the notifications. */
    private function mute(App $app, Request $req, string $slug): Response
    {
        ['group' => $group] = $this->inGroup($app, $slug);
        $data = Validator::check($req->input(), ['muted' => ['bool', 'required']]);
        $app->db()->run(
            'UPDATE {{small_group_members}} SET muted = ? WHERE group_id = ? AND user_id = ?',
            [$data['muted'] ? 1 : 0, $group['id'], $this->me($app)],
        );
        return Response::json(['muted' => (bool) $data['muted']]);
    }

    /** Taking a message down hides it rather than deleting it. */
    private function removeMessage(App $app, string $slug, string $messageId): Response
    {
        ['group' => $group, 'members' => $members] = $this->inGroup($app, $slug);
        $db = $app->db();
        $message = Id::isValid($messageId) ? $db->one('SELECT * FROM {{group_messages}} WHERE id = ? AND group_id = ?', [$messageId, $group['id']]) : null;
        if ($message === null) {
            throw ApiError::notFound();
        }
        if (!Thread::canRemoveMessage($message, $members, $this->me($app), $this->keepsTheList($app))) {
            throw ApiError::forbidden();
        }
        $db->update('group_messages', ['hidden' => 1], ['id' => $message['id']]);
        return Response::json(['ok' => true]);
    }

    // -- Who came ---------------------------------------------------------

    /**
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $members
     * @return list<array<string, mixed>>
     */
    private function meetingRows(App $app, array $group, array $members): array
    {
        $db = $app->db();
        $me = $this->me($app);
        $leads = Attendance::canKeepRoll($members, $me, $this->keepsTheList($app));
        $meetings = $db->all('SELECT * FROM {{small_group_meetings}} WHERE group_id = ? ORDER BY date DESC LIMIT 26', [$group['id']]);
        if ($meetings === []) {
            return [];
        }
        $ids = array_map('strval', array_column($meetings, 'id'));
        $rows = $db->all(
            'SELECT a.*, u.name AS user_name FROM {{group_attendances}} a JOIN {{users}} u ON u.id = a.user_id WHERE a.meeting_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );
        $byMeeting = [];
        foreach ($rows as $row) {
            $byMeeting[(string) $row['meeting_id']][] = $row;
        }
        $out = [];
        foreach ($meetings as $meeting) {
            $roll = $byMeeting[(string) $meeting['id']] ?? [];
            $visible = Attendance::visibleAttendance($roll, $members, $me, $this->keepsTheList($app));
            $shape = [
                'id' => (string) $meeting['id'],
                'date' => substr((string) $meeting['date'], 0, 10),
                'topic' => $meeting['topic'],
                'cancelled' => (bool) $meeting['cancelled'],
                'attendance' => array_map(fn (array $a) => [
                    'name' => (string) $a['user_name'],
                    'status' => (string) $a['status'],
                    'note' => $a['note'],
                    'mine' => (string) $a['user_id'] === $me,
                ], $visible),
            ];
            if ($leads) {
                // The leader's own notes are never on a member's shape.
                $shape['leaderNotes'] = $meeting['leader_notes'];
                $shape['summary'] = Attendance::summariseRoll($roll, (int) $meeting['visitor_count']);
            }
            $out[] = $shape;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $members
     * @return list<string> names worth a phone call
     */
    private function quietlyMissing(App $app, string $groupId, array $members): array
    {
        $db = $app->db();
        $meetings = array_map(fn (array $m) => [
            'id' => (string) $m['id'],
            'date' => substr((string) $m['date'], 0, 10),
            'cancelled' => (bool) $m['cancelled'],
        ], $db->all('SELECT id, date, cancelled FROM {{small_group_meetings}} WHERE group_id = ? ORDER BY date DESC LIMIT 12', [$groupId]));
        if ($meetings === []) {
            return [];
        }
        $ids = array_column($meetings, 'id');
        $byMeeting = [];
        foreach ($db->all(
            'SELECT meeting_id, user_id, status FROM {{group_attendances}} WHERE meeting_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ) as $row) {
            $byMeeting[(string) $row['meeting_id']][(string) $row['user_id']] = (string) $row['status'];
        }
        $active = Groups::activeMembers($members);
        $missing = Attendance::quietlyMissing($meetings, $byMeeting, array_map(fn (array $m) => (string) $m['user_id'], $active));
        $names = [];
        foreach ($active as $member) {
            if (in_array((string) $member['user_id'], $missing, true)) {
                $names[] = (string) $member['name'];
            }
        }
        return $names;
    }

    private function meetings(App $app, string $slug): Response
    {
        ['group' => $group, 'members' => $members] = $this->inGroup($app, $slug);
        return Response::json($this->meetingRows($app, $group, $members));
    }

    /**
     * The roll a leader writes up on the night. One meeting per group per
     * day under a unique index, so two leaders opening the form at once
     * can't make two half-rolls, and only people currently in the group can
     * be marked — checked against the database rather than the form.
     */
    private function recordRoll(App $app, Request $req, string $slug): Response
    {
        ['group' => $group, 'members' => $members] = $this->inGroup($app, $slug);
        if (!Attendance::canKeepRoll($members, $this->me($app), $this->keepsTheList($app))) {
            throw ApiError::forbidden();
        }
        $data = Validator::check($req->input(), [
            'date' => ['date', 'required'],
            'topic' => ['text', 'nullable', 'max' => 500],
            'visitorCount' => ['int', 'min' => 0, 'max' => 500],
            'cancelled' => ['bool'],
            'leaderNotes' => ['text', 'nullable', 'max' => 5000],
            'guideId' => ['id', 'nullable'],
            'attendance' => ['array', 'nullable', 'max' => 200],
        ]);
        if (!Attendance::canRecordFor((string) $data['date'], $this->today())) {
            throw ApiError::invalid(t('groups.notYet'));
        }
        $db = $app->db();
        $active = array_map(fn (array $m) => (string) $m['user_id'], Groups::activeMembers($members));
        $meetingId = $db->transaction(function (Db $db) use ($group, $data, $active, $app): string {
            $existing = $db->one('SELECT * FROM {{small_group_meetings}} WHERE group_id = ? AND date = ? FOR UPDATE', [$group['id'], $data['date']]);
            $row = [
                'topic' => $data['topic'] ?? null,
                'visitor_count' => max(0, (int) ($data['visitorCount'] ?? 0)),
                'cancelled' => (int) (bool) ($data['cancelled'] ?? false),
                'leader_notes' => $data['leaderNotes'] ?? null,
                'guide_id' => $data['guideId'] ?? null,
                'recorded_by_email' => mb_substr((string) $app->currentUser()->email(), 0, 255),
            ];
            if ($existing !== null) {
                $db->update('small_group_meetings', $row, ['id' => $existing['id']]);
                $id = (string) $existing['id'];
            } else {
                $id = $db->insert('small_group_meetings', $row + ['id' => Id::new(), 'group_id' => $group['id'], 'date' => $data['date']]);
            }
            foreach ((array) ($data['attendance'] ?? []) as $one) {
                if (!is_array($one) || !is_string($one['userId'] ?? null) || !in_array((string) ($one['status'] ?? ''), Attendance::STATUSES, true)) {
                    continue;
                }
                // Only people currently in the group, checked here rather
                // than trusted from the form.
                if (!in_array((string) $one['userId'], $active, true)) {
                    continue;
                }
                $note = is_string($one['note'] ?? null) ? mb_substr(trim($one['note']), 0, 500) : null;
                $db->run(
                    'INSERT INTO {{group_attendances}} (id, meeting_id, user_id, status, note) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)',
                    [Id::new(), $id, $one['userId'], $one['status'], $note],
                );
            }
            return $id;
        });
        return Response::json(['id' => $meetingId, 'meetings' => $this->meetingRows($app, $group, $members)], 201);
    }

    // -- Discussion guides ------------------------------------------------

    private function leadsAnyGroup(App $app): bool
    {
        $me = $this->me($app);
        return $me !== null && $app->db()->value(
            'SELECT 1 FROM {{small_group_members}} WHERE user_id = ? AND status = ? AND role = ?',
            [$me, Groups::ACTIVE, Groups::LEADER],
        ) !== null;
    }

    private function guidesPage(App $app): Response
    {
        $db = $app->db();
        $staff = $this->keepsTheList($app);
        $rows = $db->all('SELECT * FROM {{discussion_guides}} WHERE published = 1' . ($staff ? ' OR 1 = 1' : '') . ' ORDER BY updated_at DESC LIMIT 200');
        $notes = Guides::canSeeLeaderNotes($this->leadsAnyGroup($app), $staff);
        return $app->page('groups/guides', [
            'title' => t('guides.title'),
            'guides' => array_map(function (array $guide) use ($db, $notes) {
                $items = $db->all('SELECT * FROM {{discussion_guide_items}} WHERE guide_id = ?', [$guide['id']]);
                $shape = Guides::presentGuide($guide, $items, $notes);
                unset($shape['items'], $shape['leaderNotes']);
                return $shape;
            }, $rows),
        ]);
    }

    private function guidePage(App $app, string $slug): Response
    {
        $db = $app->db();
        $guide = $db->one('SELECT * FROM {{discussion_guides}} WHERE slug = ?', [$slug]);
        if ($guide === null || !Guides::canOpenGuide($guide, $app->currentUser()->isStaff())) {
            throw ApiError::notFound();
        }
        $items = $db->all('SELECT * FROM {{discussion_guide_items}} WHERE guide_id = ?', [$guide['id']]);
        return $app->page('groups/guide', [
            'title' => (string) $guide['title'],
            // The shape handed to a member page has no leaderNotes on it at
            // all, so a page cannot print an answer it was never given.
            'guide' => Guides::presentGuide($guide, $items, Guides::canSeeLeaderNotes($this->leadsAnyGroup($app), $this->keepsTheList($app))),
        ]);
    }

    // -- Administration ---------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function adminGroups(App $app): array
    {
        $db = $app->db();
        $out = [];
        foreach ($db->all('SELECT * FROM {{small_groups}} ORDER BY name LIMIT 200') as $group) {
            $members = $this->membersOf($db, (string) $group['id']);
            $out[] = Json::row('small_groups', $group) + [
                'memberCount' => count(Groups::activeMembers($members)),
                'waiting' => count(array_filter($members, fn (array $m) => in_array((string) $m['status'], [Groups::REQUESTED, Groups::WAITLIST], true))),
                'leaders' => array_values(array_map(
                    fn (array $m) => (string) $m['name'],
                    array_filter(Groups::activeMembers($members), fn (array $m) => (string) $m['role'] === Groups::LEADER),
                )),
            ];
        }
        return $out;
    }

    /**
     * One group as whoever keeps the list sees it: its fields, and everybody
     * against it — including the people who have only asked, which is the
     * one list a leader normally keeps to themselves.
     *
     * @return array<string, mixed>
     */
    private function adminGroup(App $app, string $id): array
    {
        $db = $app->db();
        $group = Id::isValid($id) ? $db->one('SELECT * FROM {{small_groups}} WHERE id = ?', [$id]) : null;
        if ($group === null) {
            throw ApiError::notFound();
        }
        $members = $this->membersOf($db, (string) $group['id']);
        return Json::row('small_groups', $group) + [
            'members' => array_map(fn (array $m) => [
                'id' => (string) $m['id'],
                'name' => (string) $m['name'],
                'email' => (string) $m['email'],
                'role' => (string) $m['role'],
                'status' => (string) $m['status'],
                'note' => $m['note'],
            ], $members),
            'memberCount' => count(Groups::activeMembers($members)),
        ];
    }

    private function adminGroupPage(App $app, string $id): Response
    {
        $group = $this->adminGroup($app, $id);
        return $app->page('groups/admin-group', [
            'title' => (string) $group['name'],
            'group' => $group,
            'statuses' => Groups::STATUSES,
        ], 200, 'layouts/admin');
    }

    /** Somebody put into a group by whoever keeps the list, which leaves a row saying so. */
    private function addMember(App $app, Request $req, string $id): Response
    {
        $db = $app->db();
        $group = $this->adminGroup($app, $id);
        $data = Validator::check($req->input(), [
            'email' => ['email', 'required'],
            'role' => ['enum', 'nullable', 'enum' => [Groups::LEADER, Groups::MEMBER]],
            'status' => ['enum', 'nullable', 'enum' => Groups::STATUSES],
        ]);
        $user = $db->one('SELECT id FROM {{users}} WHERE email = ?', [$data['email']]);
        if ($user === null) {
            throw ApiError::invalid(t('groups.noSuchMember', ['email' => (string) $data['email']]));
        }
        $db->run(
            'INSERT INTO {{small_group_members}} (id, group_id, user_id, role, status, responded_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), status = VALUES(status), responded_at = VALUES(responded_at)',
            [Id::new(), $group['id'], $user['id'], (string) ($data['role'] ?? Groups::MEMBER), (string) ($data['status'] ?? Groups::ACTIVE), Db::now()],
        );
        Audit::log($db, (string) $app->currentUser()->email(), 'group.member.add', 'SmallGroup', (string) $group['id']);
        return Response::json($this->adminGroup($app, $id), 201);
    }

    private function removeMember(App $app, string $id, string $memberId): Response
    {
        $db = $app->db();
        $group = $this->adminGroup($app, $id);
        if (!Id::isValid($memberId) || $db->value('SELECT 1 FROM {{small_group_members}} WHERE id = ? AND group_id = ?', [$memberId, $group['id']]) === null) {
            throw ApiError::notFound();
        }
        $db->delete('small_group_members', ['id' => $memberId]);
        Audit::log($db, (string) $app->currentUser()->email(), 'group.member.remove', 'SmallGroup', (string) $group['id']);
        $saved = (array) $db->one('SELECT * FROM {{small_groups}} WHERE id = ?', [$group['id']]);
        $this->moveTheList($app, $saved);
        return Response::json(['ok' => true]);
    }

    private function saveGroup(App $app, Request $req, ?string $id): Response
    {
        $data = Validator::check($req->input(), [
            'name' => ['text', 'required', 'min' => 1, 'max' => 255],
            'slug' => ['string', 'nullable', 'max' => 191],
            'description' => ['text', 'nullable', 'max' => 20000],
            'meetsWhen' => ['text', 'nullable', 'max' => 255],
            'area' => ['text', 'nullable', 'max' => 255],
            'address' => ['text', 'nullable', 'max' => 1000],
            'published' => ['bool'],
            'openToJoin' => ['bool'],
            'capacity' => ['int', 'nullable', 'min' => 0, 'max' => 1000],
            'waitlist' => ['bool'],
            'leaderEmail' => ['email', 'nullable'],
        ], partial: $id !== null);
        $db = $app->db();
        $was = $id === null ? null : ($db->one('SELECT * FROM {{small_groups}} WHERE id = ?', [$id]) ?? throw ApiError::notFound());
        $row = [];
        foreach (['name' => 'name', 'description' => 'description', 'meetsWhen' => 'meets_when', 'area' => 'area', 'address' => 'address', 'capacity' => 'capacity'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        foreach (['published' => 'published', 'openToJoin' => 'open_to_join', 'waitlist' => 'waitlist'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = (int) (bool) $data[$key];
            }
        }
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $row['slug'] = Slug::unique((string) $data['slug'], fn (string $s) => $db->value('SELECT 1 FROM {{small_groups}} WHERE slug = ?' . ($id !== null ? ' AND id <> ?' : ''), $id !== null ? [$s, $id] : [$s]) !== null, 'group');
        }
        if ($id === null) {
            $row['slug'] ??= Slug::unique((string) $data['name'], fn (string $s) => $db->value('SELECT 1 FROM {{small_groups}} WHERE slug = ?', [$s]) !== null, 'group');
            $id = $db->insert('small_groups', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('small_groups', $row, ['id' => $id]);
        }
        // Somebody has to be able to answer a request.
        if (isset($data['leaderEmail'])) {
            $user = $db->one('SELECT id FROM {{users}} WHERE email = ?', [$data['leaderEmail']]);
            if ($user === null) {
                throw ApiError::invalid(t('groups.noSuchMember', ['email' => (string) $data['leaderEmail']]));
            }
            $db->run(
                'INSERT INTO {{small_group_members}} (id, group_id, user_id, role, status, responded_at) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), status = VALUES(status)',
                [Id::new(), $id, $user['id'], Groups::LEADER, Groups::ACTIVE, Db::now()],
            );
        }
        $saved = (array) $db->one('SELECT * FROM {{small_groups}} WHERE id = ?', [$id]);
        if ($was !== null && Groups::placesLeft($saved, $this->membersOf($db, $id)) !== 0) {
            $this->moveTheList($app, $saved);
        }
        Audit::log($db, (string) $app->currentUser()->email(), $was === null ? 'group.create' : 'group.update', 'SmallGroup', $id);
        return Response::json(Json::row('small_groups', $saved), $was === null ? 201 : 200);
    }

    private function deleteGroup(App $app, string $id): Response
    {
        $db = $app->db();
        $group = Id::isValid($id) ? $db->one('SELECT * FROM {{small_groups}} WHERE id = ?', [$id]) : null;
        if ($group === null) {
            throw ApiError::notFound();
        }
        $db->delete('small_groups', ['id' => $group['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'group.delete', 'SmallGroup', (string) $group['id']);
        return Response::json(['ok' => true]);
    }

    /** @return list<array<string, mixed>> */
    private function adminGuides(App $app): array
    {
        $db = $app->db();
        return array_map(function (array $guide) use ($db) {
            $items = $db->all('SELECT * FROM {{discussion_guide_items}} WHERE guide_id = ? ORDER BY position, id', [$guide['id']]);
            return Json::row('discussion_guides', $guide) + [
                'items' => Json::rows('discussion_guide_items', $items),
                'summary' => Guides::describeGuide(array_map(fn (array $i) => ['kind' => (string) $i['kind'], 'body' => (string) $i['body'], 'reference' => $i['reference']], array_filter($items, fn (array $i) => Guides::isMemberKind((string) $i['kind'])))),
            ];
        }, $db->all('SELECT * FROM {{discussion_guides}} ORDER BY updated_at DESC LIMIT 200'));
    }

    private function saveGuide(App $app, Request $req, ?string $id): Response
    {
        $data = Validator::check($req->input(), [
            'title' => ['text', 'required', 'min' => 1, 'max' => 255],
            'slug' => ['string', 'nullable', 'max' => 191],
            'description' => ['text', 'nullable', 'max' => 20000],
            'published' => ['bool'],
            'seriesId' => ['id', 'nullable'],
            'videoId' => ['id', 'nullable'],
        ], partial: $id !== null);
        $db = $app->db();
        $was = $id === null ? null : ($db->one('SELECT * FROM {{discussion_guides}} WHERE id = ?', [$id]) ?? throw ApiError::notFound());
        $row = [];
        foreach (['title' => 'title', 'description' => 'description', 'seriesId' => 'series_id', 'videoId' => 'video_id'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        if (array_key_exists('published', $data)) {
            $row['published'] = (int) (bool) $data['published'];
        }
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $row['slug'] = Slug::unique((string) $data['slug'], fn (string $s) => $db->value('SELECT 1 FROM {{discussion_guides}} WHERE slug = ?' . ($id !== null ? ' AND id <> ?' : ''), $id !== null ? [$s, $id] : [$s]) !== null, 'guide');
        }
        if ($id === null) {
            $row['slug'] ??= Slug::unique((string) $data['title'], fn (string $s) => $db->value('SELECT 1 FROM {{discussion_guides}} WHERE slug = ?', [$s]) !== null, 'guide');
            $id = $db->insert('discussion_guides', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('discussion_guides', $row, ['id' => $id]);
        }
        return Response::json(Json::row('discussion_guides', (array) $db->one('SELECT * FROM {{discussion_guides}} WHERE id = ?', [$id])), $was === null ? 201 : 200);
    }

    private function deleteGuide(App $app, string $id): Response
    {
        $db = $app->db();
        $guide = Id::isValid($id) ? $db->one('SELECT * FROM {{discussion_guides}} WHERE id = ?', [$id]) : null;
        if ($guide === null) {
            throw ApiError::notFound();
        }
        $db->delete('discussion_guides', ['id' => $guide['id']]);
        return Response::json(['ok' => true]);
    }

    private function saveGuideItem(App $app, Request $req, string $guideId, ?string $itemId): Response
    {
        $db = $app->db();
        $guide = Id::isValid($guideId) ? $db->one('SELECT * FROM {{discussion_guides}} WHERE id = ?', [$guideId]) : null;
        if ($guide === null) {
            throw ApiError::notFound();
        }
        $data = Validator::check($req->input(), [
            'kind' => ['enum', 'enum' => Guides::KINDS],
            'body' => ['text', 'required', 'min' => 1, 'max' => 5000],
            'reference' => ['text', 'nullable', 'max' => 255],
            'position' => ['int', 'min' => 0, 'max' => 10000],
        ], partial: $itemId !== null);
        $row = [];
        foreach (['kind' => 'kind', 'body' => 'body', 'reference' => 'reference', 'position' => 'position'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        if ($itemId === null) {
            $row['position'] ??= 1 + (int) $db->value('SELECT COALESCE(MAX(position), 0) FROM {{discussion_guide_items}} WHERE guide_id = ?', [$guide['id']]);
            $itemId = $db->insert('discussion_guide_items', $row + ['id' => Id::new(), 'guide_id' => $guide['id'], 'kind' => $row['kind'] ?? Guides::QUESTION]);
        } else {
            if ($db->value('SELECT 1 FROM {{discussion_guide_items}} WHERE id = ? AND guide_id = ?', [$itemId, $guide['id']]) === null) {
                throw ApiError::notFound();
            }
            if ($row !== []) {
                $db->update('discussion_guide_items', $row, ['id' => $itemId]);
            }
        }
        $db->update('discussion_guides', ['updated_at' => Db::now()], ['id' => $guide['id']]);
        return Response::json(Json::row('discussion_guide_items', (array) $db->one('SELECT * FROM {{discussion_guide_items}} WHERE id = ?', [$itemId])), 201);
    }

    private function deleteGuideItem(App $app, string $guideId, string $itemId): Response
    {
        $db = $app->db();
        if (!Id::isValid($guideId) || !Id::isValid($itemId) || $db->value('SELECT 1 FROM {{discussion_guide_items}} WHERE id = ? AND guide_id = ?', [$itemId, $guideId]) === null) {
            throw ApiError::notFound();
        }
        $db->delete('discussion_guide_items', ['id' => $itemId]);
        return Response::json(['ok' => true]);
    }
};
