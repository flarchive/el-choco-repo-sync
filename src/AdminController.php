<?php

namespace ElChoco\RepoSync;

use Flarum\Http\RequestUtil;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Configuration $config,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ConnectionInterface $db,
        private readonly Jobs $jobs,
        private readonly Processor $processor
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];
        try {
            if ($request->getMethod() === 'POST') {
                $input = $request->getParsedBody();
                if (!is_array($input) || strlen(json_encode($input)) > 50000) throw new SyncException('invalid_config');
                $action = $input['action'] ?? '';
                if ($action === 'resolve') {
                    $id = $input['discussion'] ?? null;
                    if (!is_scalar($id) || !ctype_digit((string) $id)) throw new SyncException('invalid_config');
                    $discussion = Discussion::find((int) $id);
                    if (!$discussion || !$discussion->first_post_id) throw new SyncException('target_invalid');
                    return new JsonResponse(['post' => (int) $discussion->first_post_id], 200, $headers);
                } elseif ($action === 'save') {
                    $this->save($input);
                } elseif ($action === 'run') {
                    $this->processor->run(2);
                } elseif ($action === 'sync' || $action === 'initialize') {
                    if ($this->config->get('enabled') !== '1') throw new SyncException('not_configured');
                    $id = $input['mapping'] ?? null;
                    if (!is_string($id)) throw new SyncException('invalid_config');
                    $mapping = $this->config->mappings()[$id] ?? null;
                    if (!$mapping || !$mapping['readme']) throw new SyncException('mapping_disabled');
                    $this->jobs->enqueue($mapping, 'readme', bin2hex(random_bytes(16)), null, $action === 'initialize');
                } elseif ($action === 'retry') {
                    $id = $input['job'] ?? null;
                    if (!is_scalar($id) || !ctype_digit((string) $id)) throw new SyncException('invalid_config');
                    $this->db->table('repo_sync_jobs')->where('id', (int) $id)->where('status', 'error')->update([
                        'status' => 'pending', 'attempts' => 0, 'available_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                } else throw new SyncException('invalid_config');
            }
            $backup = $request->getQueryParams()['backup'] ?? null;
            if ($backup !== null) {
                if (!is_scalar($backup) || !ctype_digit((string) $backup)) throw new SyncException('invalid_config');
                $record = $this->db->table('repo_sync_backups')->where('id', (int) $backup)->first();
                if (!$record) throw new SyncException('not_found', 404);
                return new JsonResponse(['backup' => $record], 200, $headers);
            }
            return new JsonResponse([
                'config' => ['enabled' => $this->config->get('enabled') === '1', 'actor' => $this->config->get('actor'),
                    'hasSecret' => $this->config->get('secret') !== '', 'hasToken' => $this->config->get('token') !== '',
                    'mappings' => json_decode($this->config->get('mappings', '[]'), true) ?: []],
                'jobs' => $this->jobs->recent(),
                'backups' => $this->db->table('repo_sync_backups')->orderByDesc('id')->limit(200)->get(['id', 'mapping_id', 'post_id', 'created_at'])->all(),
            ], 200, $headers);
        } catch (SyncException $error) {
            return new JsonResponse(['error' => $error->reason], $error->status, $headers);
        }
    }

    private function save(array $input): void
    {
        if (!is_array($input['mappings'] ?? null) || !array_is_list($input['mappings']) || count($input['mappings']) > 20) throw new SyncException('invalid_config');
        $raw = json_encode($input['mappings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        Configuration::parse($raw);
        foreach (['actor', 'secret', 'token'] as $name) if (!is_string($input[$name] ?? null)) throw new SyncException('invalid_config');
        $secret = $input['secret'] !== '' ? $input['secret'] : $this->config->get('secret');
        if (($input['enabled'] ?? false) && (strlen($secret) < 32 || !ctype_digit($input['actor']) || (int) $input['actor'] < 1)) throw new SyncException('invalid_config');
        if (strlen($secret) > 200 || strlen($input['token']) > 300 || preg_match('/[\r\n]/', $input['token'])) throw new SyncException('invalid_config');
        $this->settings->set(Configuration::PREFIX.'enabled', ($input['enabled'] ?? false) ? '1' : '0');
        $this->settings->set(Configuration::PREFIX.'actor', $input['actor']);
        $this->settings->set(Configuration::PREFIX.'secret', $secret);
        if ($input['token'] !== '' || ($input['clearToken'] ?? false)) $this->settings->set(Configuration::PREFIX.'token', $input['token']);
        $this->settings->set(Configuration::PREFIX.'mappings', $raw);
    }
}
