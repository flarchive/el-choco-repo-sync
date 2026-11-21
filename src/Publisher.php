<?php

namespace ElChoco\RepoSync;

use Flarum\Api\Client;
use Flarum\User\User;

class Publisher
{
    public function __construct(private readonly Client $client) {}

    public function edit(User $actor, int $post, string $content): void
    {
        $this->send($actor, ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['content' => $content]], $post);
    }

    public function reply(User $actor, int $discussion, string $content): int
    {
        return $this->send($actor, ['type' => 'posts', 'attributes' => ['content' => $content],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion]]]]);
    }

    private function send(User $actor, array $data, ?int $post = null): int
    {
        $client = $this->client->withActor($actor)->withoutErrorHandling()->withBody(['data' => $data]);
        try {
            $response = $post ? $client->patch('/posts/'.$post) : $client->post('/posts');
            if ($response->getStatusCode() >= 400) throw new SyncException('publish_failed');
            $json = json_decode((string) $response->getBody(), true);
            $id = $json['data']['id'] ?? null;
            if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) throw new SyncException('publish_failed');
            return (int) $id;
        } catch (SyncException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new SyncException('publish_failed');
        }
    }
}
