<?php
/**
 * Plugin Name: Forms
 * Slug:        forms
 * Version:     1.0.0
 * Description: Connect cards and sign-up forms built here rather than in code, filled in at /forms, with the responses kept and exportable.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Forms.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Json;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Plugins\BasePlugin;
use App\Services\Email\Message;
use App\Support\Slug;
use MarineTeam\Plugins\Forms\Forms;

/**
 * A form is built at /admin/forms and filled in at /forms/<name>. The
 * questions are rows somebody adds, not code somebody deploys: they change
 * every term, and "add a box for dietary requirements" shouldn't need a
 * release.
 *
 * An account is not needed, which is the whole point of a connect card:
 * the person it is for walked in twenty minutes ago. Renaming a question
 * doesn't rewrite history, stopping one keeps its answers, and the server
 * has the last word on what a valid answer is.
 */
return new class (__DIR__) extends BasePlugin {
    /** Submissions from one address in an hour. */
    public const PER_HOUR_IP = 20;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $manage = Middleware::can($app, 'manage_events');

            $r->get('/forms', fn () => $this->listPage($app));
            $r->get('/forms/[slug]', fn (Request $req, array $p) => $this->formPage($app, (string) $p['slug']));
            $r->post('/api/forms/[slug]', fn (Request $req, array $p) => $this->submit($app, $req, (string) $p['slug']));

            $r->get('/admin/forms', fn () => $app->page('forms/admin', ['title' => 'Forms', 'rows' => $this->adminForms($app)], 200, 'layouts/admin'), [$manage]);
            $r->get('/admin/forms/[id]', fn (Request $req, array $p) => $this->adminFormPage($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/forms', fn () => Response::json($this->adminForms($app)), [$manage]);
            $r->post('/api/admin/forms', fn (Request $req) => $this->saveForm($app, $req, null), [$manage]);
            $r->get('/api/admin/forms/[id]', fn (Request $req, array $p) => Response::json($this->presentAdmin($app, $this->find($app->db(), (string) $p['id']))), [$manage]);
            $r->add('PATCH', '/api/admin/forms/[id]', fn (Request $req, array $p) => $this->saveForm($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/forms/[id]', fn (Request $req, array $p) => $this->deleteForm($app, (string) $p['id']), [$manage]);
            $r->post('/api/admin/forms/[id]/fields', fn (Request $req, array $p) => $this->saveField($app, $req, (string) $p['id'], null), [$manage]);
            $r->add('PATCH', '/api/admin/forms/[id]/fields/[fieldId]', fn (Request $req, array $p) => $this->saveField($app, $req, (string) $p['id'], (string) $p['fieldId']), [$manage]);
            $r->add('DELETE', '/api/admin/forms/[id]/fields/[fieldId]', fn (Request $req, array $p) => $this->retireField($app, (string) $p['id'], (string) $p['fieldId']), [$manage]);
            $r->get('/api/admin/forms/[id]/submissions', fn (Request $req, array $p) => $this->submissions($app, $req, (string) $p['id']), [$manage]);
            $r->add('PATCH', '/api/admin/forms/[id]/submissions/[submissionId]', fn (Request $req, array $p) => $this->handled($app, $req, (string) $p['id'], (string) $p['submissionId']), [$manage]);
            $r->add('DELETE', '/api/admin/forms/[id]/submissions/[submissionId]', fn (Request $req, array $p) => $this->deleteSubmission($app, (string) $p['id'], (string) $p['submissionId']), [$manage]);
        });
        $hooks->filter('nav.sections', fn (array $nav) => [...$nav, ['href' => '/forms', 'label' => t('forms.title'), 'icon' => 'card']]);
        $hooks->filter('sitemap.urls', function (array $urls) use ($app) {
            foreach ($app->db()->all('SELECT slug FROM {{forms}} WHERE published = 1 AND member_only = 0') as $form) {
                $urls[] = '/forms/' . $form['slug'];
            }
            return $urls;
        });
    }

    // -- Reading ----------------------------------------------------------

    /** A members-only form is invisible to anybody not signed in, rather than refused. */
    private function visibleSql(App $app): string
    {
        return 'published = 1' . ($app->currentUser()->isSignedIn() ? '' : ' AND member_only = 0');
    }

    /** @return array<string, mixed> */
    private function find(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{forms}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /**
     * @return list<array<string, mixed>> the questions, live first
     */
    private function fields(Db $db, string $formId, bool $liveOnly = true): array
    {
        return $db->all(
            'SELECT * FROM {{form_fields}} WHERE form_id = ?' . ($liveOnly ? ' AND deleted_at IS NULL' : '') . ' ORDER BY deleted_at IS NOT NULL, position, created_at',
            [$formId],
        );
    }

    private function listPage(App $app): Response
    {
        $rows = $app->db()->all('SELECT * FROM {{forms}} WHERE ' . $this->visibleSql($app) . ' ORDER BY title LIMIT 200');
        return $app->page('forms/list', [
            'title' => t('forms.title'),
            'forms' => array_map(fn (array $f) => ['title' => (string) $f['title'], 'slug' => (string) $f['slug'], 'description' => $f['description'], 'memberOnly' => (bool) $f['member_only']], $rows),
        ]);
    }

    private function formPage(App $app, string $slug): Response
    {
        $db = $app->db();
        $form = $db->one('SELECT * FROM {{forms}} WHERE slug = ? AND ' . $this->visibleSql($app), [$slug]);
        if ($form === null) {
            throw ApiError::notFound();
        }
        $sent = $this->alreadySent($app, $form);
        return $app->page('forms/form', [
            'title' => (string) $form['title'],
            'form' => Json::row('forms', $form, ['notify_emails']),
            // The options as a list, in place of the one-per-line text they are stored as.
            'fields' => array_map(fn (array $f) => ['options' => Forms::optionsOf($f)] + Json::row('form_fields', $f), $this->fields($db, (string) $form['id'])),
            'alreadySent' => $sent,
            'script' => $this->asset('forms.js'),
        ]);
    }

    /** @param array<string, mixed> $form */
    private function alreadySent(App $app, array $form): bool
    {
        $userId = $app->currentUser()->id();
        return !(bool) $form['multiple'] && $userId !== null
            && $app->db()->value('SELECT 1 FROM {{form_submissions}} WHERE form_id = ? AND user_id = ?', [$form['id'], $userId]) !== null;
    }

    // -- Filling one in ---------------------------------------------------

    private function submit(App $app, Request $req, string $slug): Response
    {
        $db = $app->db();
        $form = $db->one('SELECT * FROM {{forms}} WHERE slug = ? AND ' . $this->visibleSql($app), [$slug]);
        if ($form === null) {
            throw ApiError::notFound();
        }
        $input = $req->input();
        if (trim((string) ($input['website'] ?? '')) !== '') {
            // The honeypot: a person never sees this field.
            return Response::json(['ok' => true], 201);
        }
        $user = $app->currentUser()->user();
        if ($user === null && !(new RateLimiter($db))->hit(RateLimiter::bucket('form', 'ip:' . $req->ip), self::PER_HOUR_IP, 3600)) {
            throw new ApiError(t('forms.slowDown'), 429, 'rate_limited');
        }
        if ($this->alreadySent($app, $form)) {
            throw ApiError::conflict(t('forms.onlyOnce'));
        }
        $fields = $this->fields($db, (string) $form['id']);
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $checked = Forms::validateSubmission($fields, $answers);
        if ($checked['problems'] !== []) {
            throw new ApiError(implode(' ', $checked['problems']), 400, 'invalid');
        }
        $id = $db->transaction(function (Db $db) use ($form, $user, $checked): string {
            $id = $db->insert('form_submissions', ['id' => Id::new(), 'form_id' => $form['id'], 'user_id' => $user['id'] ?? null]);
            foreach ($checked['answers'] as $fieldId => $value) {
                $db->insert('form_answers', ['id' => Id::new(), 'submission_id' => $id, 'field_id' => $fieldId, 'value' => $value]);
            }
            return $id;
        });
        $this->tell($app, $form, $fields, $checked['answers']);
        return Response::json([
            'ok' => true,
            'id' => $id,
            'confirmation' => (string) ($form['confirmation'] ?? '') !== '' ? (string) $form['confirmation'] : t('forms.thanks'),
        ], 201);
    }

    /**
     * Somebody is told, at the addresses the form itself names — different
     * forms reach different people, and the person who knows which is the
     * one editing the form.
     *
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $fields
     * @param array<string, string> $answers
     */
    private function tell(App $app, array $form, array $fields, array $answers): void
    {
        $to = array_values(array_filter(array_map('trim', explode(',', (string) ($form['notify_emails'] ?? ''))), fn (string $a) => $a !== ''));
        $mailer = $app->mailer();
        if ($to === [] || !$mailer->isConfigured()) {
            return;
        }
        $lines = [];
        foreach (Forms::submissionRow(Forms::columnsFor($fields), $answers) as $row) {
            $lines[] = $row['label'] . ': ' . str_replace("\n", ', ', $row['value']);
        }
        $body = implode("\n", $lines) . "\n\n" . Url::absolute('/admin/forms/' . $form['id']) . "\n";
        foreach ($to as $address) {
            try {
                $mailer->send(new Message($address, t('forms.mail.subject', ['title' => (string) $form['title']]), $body));
            } catch (\Throwable $e) {
                Log::warning('Form notification failed: ' . $e->getMessage(), ['form' => $form['id']]);
            }
        }
    }

    // -- Building one -----------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function adminForms(App $app): array
    {
        $rows = $app->db()->all(
            'SELECT f.*, (SELECT COUNT(*) FROM {{form_submissions}} s WHERE s.form_id = f.id) AS submissions,
                    (SELECT COUNT(*) FROM {{form_submissions}} s WHERE s.form_id = f.id AND s.handled_at IS NULL) AS waiting
             FROM {{forms}} f ORDER BY f.title LIMIT 200',
        );
        return array_map(fn (array $f) => Json::row('forms', $f), $rows);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function presentAdmin(App $app, array $form): array
    {
        return Json::row('forms', $form) + [
            'fields' => array_map(
                fn (array $f) => Json::row('form_fields', $f) + ['choices' => Forms::optionsOf($f)],
                $this->fields($app->db(), (string) $form['id'], liveOnly: false),
            ),
        ];
    }

    private function adminFormPage(App $app, string $id): Response
    {
        $db = $app->db();
        $form = $this->find($db, $id);
        return $app->page('forms/admin-form', [
            'title' => (string) $form['title'],
            'form' => $this->presentAdmin($app, $form),
            'types' => Forms::TYPES,
            'choiceTypes' => Forms::CHOICES,
            'columns' => Forms::columnsFor($this->fields($db, (string) $form['id'], liveOnly: false)),
            'submissions' => $this->submissionRows($app, (string) $form['id']),
        ], 200, 'layouts/admin');
    }

    private function saveForm(App $app, Request $req, ?string $id): Response
    {
        $data = Validator::check($req->input(), [
            'title' => ['text', 'required', 'min' => 1, 'max' => 255],
            'slug' => ['string', 'nullable', 'max' => 191],
            'description' => ['text', 'nullable', 'max' => 20000],
            'published' => ['bool'],
            'memberOnly' => ['bool'],
            'multiple' => ['bool'],
            'confirmation' => ['text', 'nullable', 'max' => 2000],
            'notifyEmails' => ['string', 'nullable', 'max' => 2000],
        ], partial: $id !== null);
        $db = $app->db();
        $was = $id === null ? null : $this->find($db, $id);
        $row = [];
        foreach (['title' => 'title', 'description' => 'description', 'confirmation' => 'confirmation'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        foreach (['published' => 'published', 'memberOnly' => 'member_only', 'multiple' => 'multiple'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = (int) (bool) $data[$key];
            }
        }
        if (array_key_exists('notifyEmails', $data)) {
            $row['notify_emails'] = $this->addresses((string) ($data['notifyEmails'] ?? ''));
        }
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $row['slug'] = $this->uniqueSlug($db, (string) $data['slug'], $id);
        }
        if ($id === null) {
            $row['slug'] ??= $this->uniqueSlug($db, (string) $data['title']);
            $id = $db->insert('forms', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('forms', $row, ['id' => $id]);
        }
        Audit::log($db, (string) $app->currentUser()->email(), $was === null ? 'form.create' : 'form.update', 'Form', $id);
        return Response::json($this->presentAdmin($app, $this->find($db, $id)), $was === null ? 201 : 200);
    }

    /** Addresses a submission is sent to, checked so a typo isn't stored as one. */
    private function addresses(string $raw): ?string
    {
        $out = [];
        foreach (explode(',', $raw) as $one) {
            $address = trim($one);
            if ($address === '') {
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw ApiError::invalid(t('forms.badAddress', ['address' => $address]));
            }
            $out[] = $address;
        }
        return $out === [] ? null : implode(', ', $out);
    }

    private function uniqueSlug(Db $db, string $wanted, ?string $exceptId = null): string
    {
        return Slug::unique($wanted, fn (string $slug) => $db->value(
            'SELECT 1 FROM {{forms}} WHERE slug = ?' . ($exceptId !== null ? ' AND id <> ?' : ''),
            $exceptId !== null ? [$slug, $exceptId] : [$slug],
        ) !== null, 'form');
    }

    private function deleteForm(App $app, string $id): Response
    {
        $db = $app->db();
        $form = $this->find($db, $id);
        $db->delete('forms', ['id' => $form['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'form.delete', 'Form', (string) $form['id']);
        return Response::json(['ok' => true]);
    }

    private function saveField(App $app, Request $req, string $formId, ?string $fieldId): Response
    {
        $db = $app->db();
        $form = $this->find($db, $formId);
        $data = Validator::check($req->input(), [
            'label' => ['text', 'required', 'min' => 1, 'max' => 500],
            'type' => ['enum', 'enum' => Forms::TYPES],
            'help' => ['text', 'nullable', 'max' => 1000],
            'required' => ['bool'],
            'options' => ['text', 'nullable', 'max' => 5000],
            'position' => ['int', 'min' => 0, 'max' => 10000],
        ], partial: $fieldId !== null);
        $row = [];
        foreach (['label' => 'label', 'type' => 'type', 'help' => 'help', 'options' => 'options', 'position' => 'position'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        if (array_key_exists('required', $data)) {
            $row['required'] = (int) (bool) $data['required'];
        }
        $field = null;
        if ($fieldId !== null) {
            $field = $db->one('SELECT * FROM {{form_fields}} WHERE id = ? AND form_id = ?', [$fieldId, $form['id']]);
            if ($field === null) {
                throw ApiError::notFound();
            }
        }
        // Checked before anything is written, so a question that offers no
        // choices is never left behind by the refusal.
        $wouldBe = array_merge((array) $field, $row);
        if (in_array((string) ($wouldBe['type'] ?? Forms::TEXT), Forms::CHOICES, true) && Forms::optionsOf($wouldBe) === []) {
            throw ApiError::invalid(t('forms.needsOptions'));
        }
        if ($fieldId === null) {
            $row['position'] ??= 1 + (int) $db->value('SELECT COALESCE(MAX(position), 0) FROM {{form_fields}} WHERE form_id = ?', [$form['id']]);
            $fieldId = $db->insert('form_fields', $row + ['id' => Id::new(), 'form_id' => $form['id']]);
        } elseif ($row !== []) {
            // Renaming a question can't detach its answers: they point at the
            // question, not at the words it was asked in.
            $db->update('form_fields', $row, ['id' => $fieldId]);
        }
        return Response::json(Json::row('form_fields', (array) $db->one('SELECT * FROM {{form_fields}} WHERE id = ?', [$fieldId])), $field === null ? 201 : 200);
    }

    /**
     * Stopping a question keeps its answers: it is retired rather than
     * deleted, so March's responses can still say what they were
     * answering. One nobody has answered yet is simply removed.
     */
    private function retireField(App $app, string $formId, string $fieldId): Response
    {
        $db = $app->db();
        $form = $this->find($db, $formId);
        $field = Id::isValid($fieldId) ? $db->one('SELECT * FROM {{form_fields}} WHERE id = ? AND form_id = ?', [$fieldId, $form['id']]) : null;
        if ($field === null) {
            throw ApiError::notFound();
        }
        $answered = (int) $db->value('SELECT COUNT(*) FROM {{form_answers}} WHERE field_id = ?', [$field['id']]) > 0;
        if ($answered) {
            $db->update('form_fields', ['deleted_at' => Db::now()], ['id' => $field['id']]);
        } else {
            $db->delete('form_fields', ['id' => $field['id']]);
        }
        return Response::json(['retired' => $answered]);
    }

    // -- The responses ----------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function submissionRows(App $app, string $formId): array
    {
        $db = $app->db();
        $columns = Forms::columnsFor($this->fields($db, $formId, liveOnly: false));
        $rows = $db->all('SELECT * FROM {{form_submissions}} WHERE form_id = ? ORDER BY created_at DESC LIMIT 1000', [$formId]);
        if ($rows === []) {
            return [];
        }
        $ids = array_map('strval', array_column($rows, 'id'));
        $answers = [];
        foreach ($db->all(
            'SELECT * FROM {{form_answers}} WHERE submission_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ) as $answer) {
            $answers[(string) $answer['submission_id']][(string) $answer['field_id']] = (string) $answer['value'];
        }
        return array_map(fn (array $s) => [
            'id' => (string) $s['id'],
            'createdAt' => Json::instant((string) $s['created_at']),
            'member' => $s['user_id'] !== null,
            'handled' => $s['handled_at'] !== null,
            'handledBy' => $s['handled_by'],
            'cells' => Forms::submissionRow($columns, $answers[(string) $s['id']] ?? []),
        ], $rows);
    }

    private function submissions(App $app, Request $req, string $id): Response
    {
        $db = $app->db();
        $form = $this->find($db, $id);
        $columns = Forms::columnsFor($this->fields($db, (string) $form['id'], liveOnly: false));
        $rows = $this->submissionRows($app, (string) $form['id']);
        if (($req->query('format') ?? '') !== 'csv') {
            return Response::json($rows);
        }
        $quote = fn (mixed $value) => '"' . str_replace('"', '""', (string) $value) . '"';
        // Live questions first, then the retired ones: an export never
        // silently drops what somebody actually said.
        $csv = implode(',', array_map(fn (array $c) => $quote($c['label'] . ($c['retired'] ? ' (retired)' : '')), [...$columns, ['label' => 'Sent', 'retired' => false], ['label' => 'Member', 'retired' => false], ['label' => 'Dealt with by', 'retired' => false]])) . "\n";
        foreach ($rows as $row) {
            $cells = array_map(fn (array $cell) => $quote($cell['value']), $row['cells']);
            $cells[] = $quote($row['createdAt']);
            $cells[] = $quote($row['member'] ? 'yes' : 'no');
            $cells[] = $quote($row['handledBy'] ?? '');
            $csv .= implode(',', $cells) . "\n";
        }
        $response = Response::text($csv);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $form['slug']) . '-responses.csv"');
        return $response;
    }

    /** Responses are marked dealt with, by name: the way follow-up fails is two people each assuming the other rang. */
    private function handled(App $app, Request $req, string $id, string $submissionId): Response
    {
        $db = $app->db();
        $form = $this->find($db, $id);
        $one = Id::isValid($submissionId) ? $db->one('SELECT * FROM {{form_submissions}} WHERE id = ? AND form_id = ?', [$submissionId, $form['id']]) : null;
        if ($one === null) {
            throw ApiError::notFound();
        }
        $data = Validator::check($req->input(), ['handled' => ['bool', 'required']]);
        $db->update('form_submissions', $data['handled']
            ? ['handled_at' => Db::now(), 'handled_by' => mb_substr((string) $app->currentUser()->email(), 0, 255)]
            : ['handled_at' => null, 'handled_by' => null], ['id' => $one['id']]);
        return Response::json(['handled' => (bool) $data['handled']]);
    }

    private function deleteSubmission(App $app, string $id, string $submissionId): Response
    {
        $db = $app->db();
        $form = $this->find($db, $id);
        $one = Id::isValid($submissionId) ? $db->one('SELECT * FROM {{form_submissions}} WHERE id = ? AND form_id = ?', [$submissionId, $form['id']]) : null;
        if ($one === null) {
            throw ApiError::notFound();
        }
        $db->delete('form_submissions', ['id' => $one['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'form.submission.delete', 'FormSubmission', (string) $one['id']);
        return Response::json(['ok' => true]);
    }
};
