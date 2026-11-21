<?php

namespace ElChoco\RepoSync;

use GuzzleHttp\ClientInterface;

class GitHub
{
    public function __construct(private readonly ClientInterface $http, private readonly Configuration $config) {}

    private function request(string $path, array $query = []): array
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'el-choco-repo-sync', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($token = $this->config->get('token')) $headers['Authorization'] = 'Bearer '.$token;
        try {
            $response = $this->http->request('GET', 'https://api.github.com/repos/'.$path, [
                'headers' => $headers, 'query' => $query, 'timeout' => 12, 'connect_timeout' => 4,
                'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new SyncException(match ($response->getStatusCode()) { 404 => 'github_not_found', 403, 429 => 'github_rate_limit', default => 'github_failed' }, 502);
            }
            $body = $response->getBody();
            $raw = '';
            while (!$body->eof() && strlen($raw) <= 200000) $raw .= $body->read(8192);
            $body->close();
            if (strlen($raw) > 200000) throw new SyncException('too_large');
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new SyncException('github_failed', 502);
            return $data;
        } catch (SyncException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new SyncException('github_failed', 502);
        }
    }

    public function readme(array $mapping): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', $mapping['file'])));
        $data = $this->request($mapping['repo'].'/contents/'.$path, ['ref' => $mapping['branch']]);
        if (($data['type'] ?? '') !== 'file' || ($data['encoding'] ?? '') !== 'base64' || !is_string($data['content'] ?? null)) throw new SyncException('github_failed', 502);
        $content = base64_decode($data['content'], true);
        if (!is_string($content) || !mb_check_encoding($content, 'UTF-8')) throw new SyncException('github_failed', 502);
        if (strlen($content) > 50000) throw new SyncException('too_large');
        return $content;
    }

    public function release(array $mapping, string $id): array
    {
        return $this->request($mapping['repo'].'/releases/'.rawurlencode($id));
    }
}
