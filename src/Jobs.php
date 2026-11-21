<?php

namespace ElChoco\RepoSync;

use Illuminate\Database\ConnectionInterface;

final class Jobs
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public static function fingerprint(array $mapping): string
    {
        return hash('sha256', json_encode(array_map(fn ($key) => $mapping[$key],
            ['repo', 'branch', 'file', 'discussion', 'post', 'mode', 'readme', 'releases', 'prereleases']), JSON_THROW_ON_ERROR));
    }

    public function enqueue(array $mapping, string $kind, string $identity, ?string $release = null, bool $initialize = false): int
    {
        $key = hash('sha256', $kind === 'release' ? 'release:'.$mapping['repo'].':'.$mapping['discussion'].':'.$release : 'readme:'.$mapping['id'].':'.$identity);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('repo_sync_jobs')->insertOrIgnore([
            'job_key' => $key, 'mapping_id' => $mapping['id'], 'mapping_hash' => self::fingerprint($mapping), 'repo' => $mapping['repo'], 'kind' => $kind,
            'release_id' => $release, 'initialize' => $initialize, 'status' => 'pending', 'attempts' => 0,
            'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return (int) $this->db->table('repo_sync_jobs')->where('job_key', $key)->value('id');
    }

    public function receive(string $event, string $delivery, array $payload, array $mappings): int
    {
        $repo = $payload['repository']['full_name'] ?? null;
        if (!is_string($repo)) throw new SyncException('invalid_payload');
        $count = 0;
        foreach ($mappings as $mapping) {
            if (strtolower($repo) !== $mapping['repo']) continue;
            if ($event === 'push' && $mapping['readme'] && ($payload['ref'] ?? null) === 'refs/heads/'.$mapping['branch'] && !($payload['deleted'] ?? false)) {
                $this->enqueue($mapping, 'readme', $delivery);
                $count++;
            }
            if ($event === 'release' && $mapping['releases'] && ($payload['action'] ?? '') === 'published') {
                $release = $payload['release'] ?? [];
                if (!is_array($release) || ($release['draft'] ?? true) || (($release['prerelease'] ?? false) && !$mapping['prereleases'])) continue;
                $id = $release['id'] ?? null;
                if (!is_scalar($id) || !ctype_digit((string) $id) || strlen((string) $id) > 24 || (int) $id < 1) throw new SyncException('invalid_payload');
                $this->enqueue($mapping, 'release', $delivery, (string) $id);
                $count++;
            }
        }
        return $count;
    }

    public function claim(): ?object
    {
        $now = gmdate('Y-m-d H:i:s');
        $expired = gmdate('Y-m-d H:i:s', time() - 300);
        $this->db->table('repo_sync_jobs')->where('status', 'processing')->where('locked_at', '<', $expired)
            ->update(['status' => 'pending', 'lock_token' => null, 'locked_at' => null]);
        $id = $this->db->table('repo_sync_jobs')->where('status', 'pending')->where('available_at', '<=', $now)->orderBy('id')->value('id');
        if (!$id) return null;
        $token = bin2hex(random_bytes(16));
        $claimed = $this->db->table('repo_sync_jobs')->where('id', $id)->where('status', 'pending')->update([
            'status' => 'processing', 'lock_token' => $token, 'locked_at' => $now, 'updated_at' => $now,
            'attempts' => $this->db->raw('attempts + 1'),
        ]);
        return $claimed ? $this->db->table('repo_sync_jobs')->where('id', $id)->first() : null;
    }

    public function fail(object $job, string $reason): void
    {
        $retry = $job->attempts < 5 && in_array($reason, ['github_failed', 'github_rate_limit', 'internal_error'], true);
        $this->db->table('repo_sync_jobs')->where('id', $job->id)->where('lock_token', $job->lock_token)->where('status', 'processing')->update([
            'status' => $retry ? 'pending' : 'error', 'result' => $reason, 'lock_token' => null,
            'available_at' => gmdate('Y-m-d H:i:s', time() + min(3600, 60 * 2 ** $job->attempts)), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function recent(): array
    {
        return $this->db->table('repo_sync_jobs')->orderByDesc('id')->limit(30)
            ->get(['id', 'mapping_id', 'repo', 'kind', 'status', 'attempts', 'result', 'post_id', 'created_at', 'updated_at'])->all();
    }
}
