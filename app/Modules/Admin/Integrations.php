<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Crypto;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Audit\Audit;
use App\Services\Registry;
use App\Services\TestResult;

/**
 * The optional integrations that used to be environment variables, as
 * settings groups under Admin → Services: the same generated form and test
 * button as a provider, but nothing to switch — a group is simply set or
 * not. Each is stored as one setting, `integration.<group>`, its secrets
 * encrypted under app_key.
 */
final class Integrations
{
    /**
     * @return array<string, array{label: string, description: string, fields: list<array<string, mixed>>, test: callable(App, array<string, mixed>): TestResult}>
     */
    public static function groups(): array
    {
        return [
            'transcription' => [
                'label' => 'Transcription',
                'description' => 'Writes a video’s transcript for you: any speech-to-text service that takes a multipart POST with a “file” field and answers {"text": …} — OpenAI’s, Groq’s, or a Whisper server of your own. A queued video is sent by the transcription job, one at a time.',
                'fields' => \App\Modules\Library\Transcription::FIELDS,
                'test' => fn (App $app, array $config) => \App\Modules\Library\Transcription::test($config),
            ],
        ];
    }

    /**
     * A group's saved settings, secrets decrypted; [] when never saved.
     *
     * @return array<string, mixed>
     */
    public static function config(App $app, string $group): array
    {
        $stored = $app->settings()->get('integration.' . $group);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $key => $value) {
            $out[$key] = is_array($value) && isset($value['secret']) && is_string($value['secret']) ? Crypto::decrypt($value['secret']) : $value;
        }
        return $out;
    }

    public static function register(Router $r, App $app): void
    {
        $admin = Middleware::admin($app);
        $r->get('/admin/integrations/[group]', fn (Request $req, array $p) => self::form($app, $req, $p['group']), [$admin]);
        $r->post('/admin/integrations/[group]', fn (Request $req, array $p) => self::submit($app, $req, $p['group']), [$admin]);
    }

    /** @return array{label: string, description: string, fields: list<array<string, mixed>>, test: callable} */
    private static function group(string $id): array
    {
        return self::groups()[$id] ?? throw ApiError::notFound('No such integration.');
    }

    /** @param array<string, mixed>|null $result */
    private static function page(App $app, string $id, array $values, ?array $result, ?string $flash = null, int $status = 200): Response
    {
        $group = self::group($id);
        $response = $app->page('admin/integration', [
            'title' => $group['label'],
            'id' => $id,
            'group' => $group,
            'values' => $values,
            'saved' => $app->settings()->has('integration.' . $id),
            'result' => $result,
            'flash' => $flash,
        ], $status, 'layouts/admin');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function form(App $app, Request $req, string $id): Response
    {
        return self::page($app, $id, self::config($app, $id), null, $app->session()->pull('flash'));
    }

    /** Test: runs the group's check on the submitted values. Save: stores them. Clear: forgets them. */
    private static function submit(App $app, Request $req, string $id): Response
    {
        $group = self::group($id);
        $input = $req->input();
        $action = (string) ($input['action'] ?? 'test');
        $actor = (string) $app->currentUser()->email();
        if ($action === 'clear') {
            $app->settings()->delete('integration.' . $id);
            Audit::log($app->db(), $actor, 'integration.clear', 'Integration', $id);
            $app->session()->set('flash', $group['label'] . ' is switched off: its settings were removed.');
            return Response::redirect(Url::to('/admin/integrations/' . $id));
        }
        $config = Registry::mergeFields($group['fields'], self::config($app, $id), $input);
        foreach ($group['fields'] as $field) {
            if (!empty($field['required']) && ($config[$field['key']] ?? '') === '') {
                return self::page($app, $id, $config, ['ok' => false, 'message' => $field['label'] . ' is required.', 'steps' => []], null, 400);
            }
        }
        if ($action === 'save') {
            $stored = [];
            foreach ($group['fields'] as $field) {
                $value = $config[$field['key']] ?? null;
                $stored[$field['key']] = !empty($field['secret']) && is_string($value) && $value !== '' ? ['secret' => Crypto::encrypt($value)] : $value;
            }
            $app->settings()->set('integration.' . $id, $stored);
            Audit::log($app->db(), $actor, 'integration.save', 'Integration', $id);
            $app->session()->set('flash', $group['label'] . ' settings saved.');
            return Response::redirect(Url::to('/admin/integrations/' . $id));
        }
        try {
            $result = ($group['test'])($app, $config);
        } catch (\Throwable $e) {
            Log::warning("Integration test for $id threw: " . $e->getMessage());
            $result = TestResult::fail('The test failed unexpectedly: ' . $e->getMessage());
        }
        return self::page($app, $id, $config, ['ok' => $result->ok, 'message' => $result->message, 'steps' => $result->steps]);
    }
}
