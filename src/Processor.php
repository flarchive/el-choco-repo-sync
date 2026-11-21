<?php

namespace ElChoco\RepoSync;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

final class Processor
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Configuration $config,
        private readonly Jobs $jobs,
        private readonly GitHub $github,
        private readonly Markdown $markdown,
        private readonly Publisher $publisher
    ) {}

    public function run(int $limit = 5): int
    {
        if ($this->config->get('enabled') !== '1') return 0;
        $count = 0;
        while ($count < $limit && ($job = $this->jobs->claim())) {
            try {
                $this->process($job);
            } catch (SyncException $error) {
                $this->jobs->fail($job, $error->reason);
            } catch (\Flarum\User\Exception\PermissionDeniedException) {
                $this->jobs->fail($job, 'permission_denied');
            } catch (\Throwable) {
                $this->jobs->fail($job, 'internal_error');
            }
            $count++;
        }
        $this->db->table('repo_sync_jobs')->where('kind', 'readme')->whereIn('status', ['done', 'ignored'])
            ->where('updated_at', '<', gmdate('Y-m-d H:i:s', time() - 30 * 86400))->delete();
        return $count;
    }

    private function process(object $job): void
    {
        $mapping = $this->config->mappings()[$job->mapping_id] ?? null;
        $this->db->transaction(function () use ($job, $mapping): void {
            $record = $this->db->table('repo_sync_jobs')->where('id', $job->id)->lockForUpdate()->first();
            if (!$record || $record->status !== 'processing' || $record->lock_token !== $job->lock_token) return;
            $finish = function (string $status, string $result, ?int $post = null) use ($job): void {
                $this->db->table('repo_sync_jobs')->where('id', $job->id)->update([
                    'status' => $status, 'result' => $result, 'post_id' => $post, 'lock_token' => null, 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            };
            if (!$mapping || Jobs::fingerprint($mapping) !== $job->mapping_hash || !($mapping[$job->kind === 'readme' ? 'readme' : 'releases'])) {
                $finish('ignored', 'mapping_disabled');
                return;
            }
            $actor = User::find($this->config->actorId());
            if (!$actor || $actor->isGuest()) throw new SyncException('actor_missing');
            $post = Post::whereVisibleTo($actor)->where('id', $mapping['post'])->lockForUpdate()->first();
            $discussion = Discussion::whereVisibleTo($actor)->find($mapping['discussion']);
            if (!$post instanceof CommentPost || !$discussion || (int) $post->discussion_id !== $mapping['discussion']
                || (int) $discussion->first_post_id !== $mapping['post'] || $post->hidden_at || $discussion->hidden_at) {
                throw new SyncException('target_invalid');
            }
            if ($job->kind === 'readme') {
                $actor->assertCan('edit', $post);
                $readme = $this->markdown->rewrite($this->github->readme($mapping), $mapping);
                if ($readme === '') throw new SyncException('empty_readme');
                $old = (string) $post->content;
                $new = $this->markdown->replace($old, $readme, $mapping['mode'], (bool) $job->initialize);
                if (strlen($new) > 60000) throw new SyncException('too_large');
                if (trim($old) === trim($new)) { $finish('done', 'unchanged', $post->id); return; }
                $this->db->table('repo_sync_backups')->insert([
                    'mapping_id' => $job->mapping_id, 'post_id' => $post->id, 'content' => $old, 'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->publisher->edit($actor, $post->id, $new);
                $keep = $this->db->table('repo_sync_backups')->where('mapping_id', $job->mapping_id)->orderByDesc('id')->limit(10)->pluck('id')->all();
                $this->db->table('repo_sync_backups')->where('mapping_id', $job->mapping_id)->whereNotIn('id', $keep)->delete();
                $finish('done', 'updated', $post->id);
            } else {
                $release = $this->github->release($mapping, $job->release_id);
                if (($release['draft'] ?? true) || !($release['published_at'] ?? null) || (($release['prerelease'] ?? false) && !$mapping['prereleases'])) {
                    $finish('ignored', 'release_skipped');
                    return;
                }
                $tag = $release['tag_name'] ?? '';
                $notes = $release['body'] ?? '';
                if (!is_string($tag) || $tag === '' || strlen($tag) > 200 || !is_string($notes)) throw new SyncException('github_failed');
                $title = preg_replace('/[\r\n\[\]#*_`<>]/', '', $tag);
                $content = '## '.$title."\n\n".$this->markdown->rewrite($notes, [...$mapping, 'branch' => $tag, 'file' => 'README.md'])
                    ."\n\n[GitHub · ".$mapping['repo'].'](https://github.com/'.$mapping['repo'].'/releases/tag/'.rawurlencode($tag).')';
                if (strlen($content) > 60000) throw new SyncException('too_large');
                $id = $this->publisher->reply($actor, $mapping['discussion'], $content);
                $finish('done', 'replied', $id);
            }
        });
    }
}
