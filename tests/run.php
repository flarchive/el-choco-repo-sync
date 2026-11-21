<?php

require $argv[1] ?? throw new RuntimeException('Pass the Flarum 2 vendor/autoload.php path.');
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'ElChoco\\RepoSync\\')) require dirname(__DIR__).'/src/'.substr($class, strlen('ElChoco\\RepoSync\\')).'.php';
});

use ElChoco\RepoSync\{AdminController, Configuration, GitHub, Jobs, Markdown, Processor, Publisher, RunCommand, SyncException, WebhookController};
use Flarum\Api\{Client, ClientMiddlewarePipe};
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\Post\{Post, CommentPost};
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Flarum\Group\Group;
use Flarum\User\Access\Gate;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Laminas\Diactoros\{ServerRequest, Stream};
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function rejects(callable $callback, string $reason): void {
    try { $callback(); } catch (SyncException $e) { check($e->reason === $reason, 'Expected '.$reason.', got '.$e->reason); return; }
    throw new RuntimeException('Expected '.$reason);
}

$settings = new class implements SettingsRepositoryInterface {
    public array $data = [];
    public function all(): array { return $this->data; }
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function delete(string $keyLike): void { unset($this->data[$keyLike]); }
};
$mapping = ['id' => 'mapping-1234', 'repo' => 'el-choco/kobalt', 'branch' => 'main', 'file' => 'docs/README.md',
    'discussion' => 10, 'post' => 20, 'mode' => 'section', 'enabled' => true, 'readme' => true, 'releases' => true, 'prereleases' => false];
foreach (['enabled' => '1', 'actor' => '1', 'secret' => str_repeat('s', 64), 'mappings' => json_encode([$mapping])] as $key => $value) $settings->set(Configuration::PREFIX.$key, $value);
$config = new Configuration($settings);
check($config->mappings()['mapping-1234']['post'] === 20, 'Mapping normalization');
foreach ([['repo', '../local'], ['file', '../README.md'], ['file', 'x\\README.md'], ['branch', "main\n"], ['post', '20-no'], ['mode', 'anything']] as [$key, $value]) {
    $bad = $mapping; $bad[$key] = $value;
    rejects(fn () => Configuration::parse(json_encode([$bad])), 'invalid_config');
}
$duplicate = [...$mapping, 'id' => 'mapping-5678'];
rejects(fn () => Configuration::parse(json_encode([$mapping, $duplicate])), 'duplicate_target');

$capsule = new Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'rs_']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('users', function (Blueprint $t): void { $t->increments('id'); $t->string('username'); });
$schema->create('discussions', function (Blueprint $t): void { $t->increments('id'); $t->unsignedInteger('first_post_id'); $t->dateTime('hidden_at')->nullable(); });
$schema->create('posts', function (Blueprint $t): void { $t->increments('id'); $t->unsignedInteger('discussion_id'); $t->string('type'); $t->text('content'); $t->dateTime('hidden_at')->nullable(); });
$migration = require dirname(__DIR__).'/migrations/2026_11_21_000000_create_repo_sync_tables.php';
$migration['up']($schema);
check($schema->hasTable('repo_sync_jobs'), 'Prefixed jobs migration');

$formatterConfig = new s9e\TextFormatter\Configurator();
$formatterConfig->Litedown;
$formatterConfig->BBCodes->addCustom('[REPOSYNC]{TEXT}[/REPOSYNC]', '<div class="RepoSync-content">{TEXT}</div>');
$bundle = $formatterConfig->finalize();
$formatted = "Personal introduction.\n\n[REPOSYNC]\n# Old README\n[/REPOSYNC]\n\nPersonal footer.";
$xml = $bundle['parser']->parse($formatted);
check(s9e\TextFormatter\Unparser::unparse($xml) === $formatted, 'Markers survive real formatter parse/unparse');
$html = $bundle['renderer']->render($xml);
check(str_contains($html, 'RepoSync-content') && !str_contains($html, '[REPOSYNC]'), 'Markers hidden in rendered post');
check(str_contains($html, '<h1>'), 'Markdown inside managed section renders');
CommentPost::setFormatter(new class($bundle['parser']) extends Formatter {
    public function __construct(private $parser) {}
    public function parse(string $text, mixed $context = null, ?User $user = null): string { return $this->parser->parse($text); }
    public function unparse(?string $xml, mixed $context = null): ?string { return $xml === null ? null : s9e\TextFormatter\Unparser::unparse($xml); }
});
Post::setModel('comment', CommentPost::class);
$db->table('users')->insert(['id' => 1, 'username' => 'bot']);
$db->table('discussions')->insert(['id' => 10, 'first_post_id' => 20]);
$db->table('posts')->insert(['id' => 20, 'discussion_id' => 10, 'type' => 'comment', 'content' => $xml]);
$gate = new class(new Container(), []) extends Gate {
    public bool $allowed = true;
    public function allows(User $actor, string $ability, string|\Flarum\Database\AbstractModel|null $model): bool { return $this->allowed && (int) $actor->id === 1; }
};
User::setGate($gate);

$markdown = new Markdown();
$input = "![Image](../img/logo.png)\n[Guide](guide.md)\n`[Code](code.md)`\n```md\n[Code](code.md)\n```\n[Site](https://example.com/)\n[ref]: local.md\n<img src=\"img.png\">";
$rewritten = $markdown->rewrite($input, $mapping);
check(str_contains($rewritten, 'https://raw.githubusercontent.com/el-choco/kobalt/main/img/logo.png'), 'Relative image');
check(str_contains($rewritten, 'https://github.com/el-choco/kobalt/blob/main/docs/guide.md'), 'Relative link');
check(str_contains($rewritten, '`[Code](code.md)`') && str_contains($rewritten, "```md\n[Code](code.md)"), 'Code untouched');
check(str_contains($rewritten, '[Site](<https://example.com/>)'), 'External link preserved');
check(str_contains($rewritten, '[ref]: <https://github.com/'), 'Reference links absolute');
check(str_contains($markdown->rewrite("![logo][image]\n[image]: logo.png", $mapping), '[image]: <https://raw.githubusercontent.com/'), 'Reference images use raw image URL');
check(str_contains($rewritten, 'src="https://raw.githubusercontent.com/'), 'HTML image rewritten');
check($markdown->replace('Intro', 'Body', 'section', true) === "Intro\n\n[REPOSYNC]\nBody\n[/REPOSYNC]", 'Explicit initialization appends');
check($markdown->replace('Intro', 'Body', 'whole') === 'Body', 'Whole-post mode');
rejects(fn () => $markdown->replace('Intro', 'Body', 'section'), 'markers_missing');
rejects(fn () => $markdown->replace('[/REPOSYNC][REPOSYNC]', 'Body', 'section'), 'markers_missing');
rejects(fn () => $markdown->replace('[REPOSYNC][REPOSYNC][/REPOSYNC]', 'Body', 'section'), 'markers_missing');
rejects(fn () => $markdown->replace($formatted, '[REPOSYNC]', 'section'), 'reserved_markers');
rejects(fn () => $markdown->replace($formatted, '[/reposync]', 'section'), 'reserved_markers');
check($markdown->replace('[reposync]old[/reposync]', 'new', 'section') === "[reposync]\nnew\n[/reposync]", 'Case-insensitive markers');

$httpCalls = [];
$http = new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([
    new GuzzleHttp\Psr7\Response(200, [], json_encode(['type' => 'file', 'encoding' => 'base64', 'content' => base64_encode('README')])),
    new GuzzleHttp\Psr7\Response(404), new GuzzleHttp\Psr7\Response(429),
]))]);
$history = GuzzleHttp\Middleware::history($httpCalls);
$http->getConfig('handler')->push($history);
$realGitHub = new GitHub($http, $config);
check($realGitHub->readme($mapping) === 'README', 'GitHub base64 decoding');
check(str_starts_with((string) $httpCalls[0]['request']->getUri(), 'https://api.github.com/repos/el-choco/kobalt/contents/docs/README.md?ref=main'), 'GitHub destination fixed');
rejects(fn () => $realGitHub->readme($mapping), 'github_not_found');
rejects(fn () => $realGitHub->readme($mapping), 'github_rate_limit');

$github = new class extends GitHub {
    public string $text = '# New README';
    public ?string $failure = null;
    public int $calls = 0;
    public function __construct() {}
    public function readme(array $mapping): string { $this->calls++; if ($this->failure) throw new SyncException($this->failure); return $this->text; }
    public function release(array $mapping, string $id): array { return ['draft' => false, 'prerelease' => false, 'published_at' => '2026-11-21', 'tag_name' => 'v1.0.0', 'body' => 'Changes.']; }
};
$publisher = new class($db, $bundle['parser']) extends Publisher {
    public bool $fail = false;
    public int $edits = 0;
    public int $replies = 0;
    public function __construct(private $db, private $parser) {}
    public function edit(User $actor, int $post, string $content): void {
        if ($this->fail) throw new SyncException('publish_failed');
        $this->edits++;
        $this->db->table('posts')->where('id', $post)->update(['content' => $this->parser->parse($content)]);
    }
    public function reply(User $actor, int $discussion, string $content): int { $this->replies++; return 100 + $this->replies; }
};
$jobs = new Jobs($db);
$processor = new Processor($db, $config, $jobs, $github, $markdown, $publisher);
$webhook = new WebhookController($config, $jobs);
$request = function (array|string $data, string $event = 'push', string $delivery = 'delivery-1234', bool $signed = true) use ($config): ServerRequestInterface {
    $body = is_array($data) ? json_encode($data) : $data;
    $stream = new Stream('php://temp', 'r+'); $stream->write($body);
    return (new ServerRequest())->withMethod('POST')->withBody($stream)->withHeader('X-GitHub-Event', $event)->withHeader('X-GitHub-Delivery', $delivery)
        ->withHeader('X-Hub-Signature-256', $signed ? 'sha256='.hash_hmac('sha256', $body, $config->get('secret')) : 'invalid');
};
$push = ['repository' => ['full_name' => 'el-choco/kobalt'], 'ref' => 'refs/heads/main'];
check($webhook->handle($request($push, signed: false))->getStatusCode() === 401, 'Unsigned request blocked');
check($db->table('repo_sync_jobs')->count() === 0, 'Rejected webhook creates no job');
check($webhook->handle($request('bad JSON'))->getStatusCode() === 422, 'Invalid JSON blocked');
check($webhook->handle($request([], 'ping'))->getStatusCode() === 200, 'Signed ping');
check($webhook->handle($request([...$push, 'ref' => 'refs/heads/other']))->getStatusCode() === 200, 'Wrong branch ignored');
check($webhook->handle($request([...$push, 'deleted' => true]))->getStatusCode() === 200, 'Deleted branch ignored');
check($db->table('repo_sync_jobs')->count() === 0, 'Ignored events create no job');
check($webhook->handle($request($push))->getStatusCode() === 202, 'Push queues job');
$webhook->handle($request($push));
check($db->table('repo_sync_jobs')->count() === 1, 'Duplicate delivery deduplicated');
check($processor->run() === 1, 'Worker processes queued job');
$content = CommentPost::find(20)->content;
check(str_contains($content, 'Personal introduction.') && str_contains($content, 'Personal footer.') && str_contains($content, '# New README'), 'Only managed section changed');
check($db->table('repo_sync_backups')->first()->content === $formatted, 'Original text backed up');
check($db->table('repo_sync_jobs')->first()->result === 'updated', 'Success recorded');
$jobs->enqueue($mapping, 'readme', 'repeat-push'); $processor->run();
check($publisher->edits === 1 && $db->table('repo_sync_backups')->count() === 1, 'Unchanged README avoids edits and backups');

$release = ['repository' => $push['repository'], 'action' => 'published', 'release' => ['id' => 999, 'draft' => false, 'prerelease' => false]];
check($webhook->handle($request($release, 'release'))->getStatusCode() === 202, 'Release queued');
$processor->run();
$webhook->handle($request($release, 'release', 'another-delivery'));
$processor->run();
check($publisher->replies === 1, 'Release deduplicated even with different delivery');
$pre = $release; $pre['release']['prerelease'] = true; $pre['release']['id'] = 1000;
check($webhook->handle($request($pre, 'release'))->getStatusCode() === 200, 'Prerelease excluded');

$gate->allowed = false;
$id = $jobs->enqueue($mapping, 'readme', 'forbidden'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('result') === 'permission_denied', 'Revoked rights prevent changes');
$gate->allowed = true;
$db->table('posts')->where('id', 20)->update(['hidden_at' => gmdate('Y-m-d H:i:s')]);
$id = $jobs->enqueue($mapping, 'readme', 'hidden'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('result') === 'target_invalid', 'Hidden target refused');
$db->table('posts')->where('id', 20)->update(['hidden_at' => null]);
$github->failure = 'github_failed';
$id = $jobs->enqueue($mapping, 'readme', 'network-error'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('status') === 'pending', 'Network failure retried');
$db->table('repo_sync_jobs')->where('id', $id)->update(['attempts' => 5, 'available_at' => gmdate('Y-m-d H:i:s')]);
$processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('status') === 'error', 'Retry limit enforced');
$github->failure = null;
$publisher->fail = true;
$github->text = 'Changed again';
$before = $db->table('repo_sync_backups')->count();
$id = $jobs->enqueue($mapping, 'readme', 'failed-publish'); $processor->run();
check($db->table('repo_sync_backups')->count() === $before && CommentPost::find(20)->content === $content, 'Failed publishing rolls back backup and post');
$publisher->fail = false;

$id = $jobs->enqueue($mapping, 'readme', 'removed-mapping');
$settings->set(Configuration::PREFIX.'mappings', '[]'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('status') === 'ignored', 'Removed mapping cancels queued writes');
$settings->set(Configuration::PREFIX.'mappings', json_encode([$mapping]));
$jobs->enqueue($mapping, 'readme', 'lease-test');
$claimed = $jobs->claim();
check($claimed !== null && $jobs->claim() === null, 'Exclusive claim');
$db->table('repo_sync_jobs')->where('id', $claimed->id)->update(['locked_at' => '2000-01-01 00:00:00']);
$reclaimed = $jobs->claim();
check($reclaimed->lock_token !== $claimed->lock_token, 'Expired lease recovered');
$jobs->fail($claimed, 'internal_error');
check($db->table('repo_sync_jobs')->where('id', $claimed->id)->value('lock_token') === $reclaimed->lock_token, 'Stale worker cannot overwrite new lease');
$jobs->fail($reclaimed, 'markers_missing');

$admin = User::find(1);
$group = new Group(); $group->id = 1;
$admin->setRelation('groups', new Illuminate\Database\Eloquent\Collection([$group]));
$controller = new AdminController($config, $settings, $db, $jobs, $processor);
$adminRequest = RequestUtil::withActor((new ServerRequest())->withMethod('GET'), $admin);
$data = json_decode((string) $controller->handle($adminRequest)->getBody(), true);
check(!str_contains(json_encode($data), str_repeat('s', 64)), 'Admin status never returns saved secret');
check($data['config']['hasSecret'] === true && count($data['backups']) === 1, 'Admin status and backups');
$lookup = $adminRequest->withMethod('POST')->withParsedBody(['action' => 'resolve', 'discussion' => '10']);
check(json_decode((string) $controller->handle($lookup)->getBody(), true)['post'] === 20, 'Admin resolves first-post ID');
$saving = ['action' => 'save', 'enabled' => true, 'actor' => '1', 'secret' => '', 'token' => '', 'mappings' => [$mapping]];
$settings->set(Configuration::PREFIX.'token', 'private-token');
check($controller->handle($adminRequest->withMethod('POST')->withParsedBody($saving))->getStatusCode() === 200, 'Admin settings saved');
check($config->get('secret') === str_repeat('s', 64) && $config->get('token') === 'private-token', 'Blank credentials preserve stored values');
$controller->handle($adminRequest->withMethod('POST')->withParsedBody([...$saving, 'clearToken' => true]));
check($config->get('token') === '', 'Explicit token removal');
check($controller->handle($adminRequest->withMethod('POST')->withParsedBody([...$saving, 'secret' => 'short']))->getStatusCode() === 422, 'Short webhook secret refused');
$backupRequest = $adminRequest->withQueryParams(['backup' => $data['backups'][0]['id']]);
check(json_decode((string) $controller->handle($backupRequest)->getBody(), true)['backup']['content'] === $formatted, 'Admin can retrieve backup');
try { $controller->handle(RequestUtil::withActor(new ServerRequest(), new Flarum\User\Guest())); throw new RuntimeException('Guest allowed'); }
catch (Flarum\User\Exception\PermissionDeniedException) { check(true, 'Guest cannot access admin API'); }

$id = $jobs->enqueue($mapping, 'readme', 'changed-target');
$settings->set(Configuration::PREFIX.'mappings', json_encode([[...$mapping, 'post' => 21]]));
$processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('status') === 'ignored', 'Changed target cancels previously queued job');
$settings->set(Configuration::PREFIX.'mappings', json_encode([$mapping]));
$settings->set(Configuration::PREFIX.'enabled', '0');
$id = $jobs->enqueue($mapping, 'readme', 'disabled-global');
check($processor->run() === 0 && $db->table('repo_sync_jobs')->where('id', $id)->value('status') === 'pending', 'Global switch pauses processing');
check($webhook->handle($request($push))->getStatusCode() === 503, 'Disabled webhook refuses delivery');
$settings->set(Configuration::PREFIX.'enabled', '1');
$processor->run();
$github->text = '';
$id = $jobs->enqueue($mapping, 'readme', 'empty'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('result') === 'empty_readme', 'Empty README never erases post');
$github->text = str_repeat('x', 61000);
$id = $jobs->enqueue($mapping, 'readme', 'oversize'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('result') === 'too_large', 'Oversize final post refused');
$db->table('posts')->where('id', 20)->update(['content' => $bundle['parser']->parse('Keep my introduction')]);
$github->text = 'Body';
$id = $jobs->enqueue($mapping, 'readme', 'no-markers'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('result') === 'markers_missing', 'Webhook never creates missing markers');
$id = $jobs->enqueue($mapping, 'readme', 'initialize', null, true); $processor->run();
check(str_starts_with(CommentPost::find(20)->content, 'Keep my introduction'), 'Initialization preserves existing text');
for ($i = 0; $i < 12; $i++) {
    $github->text = 'Revision '.$i;
    $jobs->enqueue($mapping, 'readme', 'revision-'.$i); $processor->run();
}
check($db->table('repo_sync_backups')->where('mapping_id', $mapping['id'])->count() === 10, 'Backup retention limited to ten');
$visible = true;
Flarum\Discussion\Discussion::registerVisibilityScoper(function ($actor, $query) use (&$visible): void { if (!$visible) $query->whereRaw('1=0'); });
$visible = false;
$id = $jobs->enqueue($mapping, 'readme', 'private-discussion'); $processor->run();
check($db->table('repo_sync_jobs')->where('id', $id)->value('result') === 'target_invalid', 'Visibility scopers enforced before publishing');
$visible = true;

$pipe = new class extends ClientMiddlewarePipe {
    public array $calls = [];
    public bool $fail = false;
    public function __construct() {}
    public function errorHandling(bool $value): static { return $this; }
    public function handle(ServerRequestInterface $request): ResponseInterface {
        $this->calls[] = [$request->getMethod(), $request->getUri()->getPath(), $request->getParsedBody(), RequestUtil::getActor($request)->id];
        return new JsonResponse(['data' => ['id' => '42']], $this->fail ? 403 : 200);
    }
};
$apiPublisher = new Publisher(new Client($pipe));
$apiPublisher->edit($admin, 20, 'Test');
check($pipe->calls[0][0] === 'PATCH' && $pipe->calls[0][1] === '/posts/20' && $pipe->calls[0][3] === 1, 'Publisher uses Flarum edit API as configured user');
check($apiPublisher->reply($admin, 10, 'Release') === 42 && $pipe->calls[1][2]['data']['relationships']['discussion']['data']['id'] === '10', 'Publisher uses Flarum reply API');
$pipe->fail = true;
rejects(fn () => $apiPublisher->edit($admin, 20, 'Forbidden'), 'publish_failed');
check((new RunCommand($processor))->getName() === 'repo-sync:run', 'Command loads against real Flarum base class');
$migration['down']($schema);
check(!$schema->hasTable('repo_sync_jobs') && !$schema->hasTable('repo_sync_backups'), 'Migration down');
echo $checks." backend checks passed.\n";
