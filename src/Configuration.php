<?php

namespace ElChoco\RepoSync;

use Flarum\Settings\SettingsRepositoryInterface;

final class Configuration
{
    public const PREFIX = 'el-choco-repo-sync.';

    public function __construct(private readonly SettingsRepositoryInterface $settings) {}

    public function get(string $name, string $default = ''): string
    {
        return (string) $this->settings->get(self::PREFIX.$name, $default);
    }

    public function mappings(): array
    {
        return self::parse($this->get('mappings', '[]'));
    }

    public static function parse(string $raw): array
    {
        try {
            $items = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SyncException('invalid_config');
        }
        if (!is_array($items) || !array_is_list($items) || count($items) > 20) throw new SyncException('invalid_config');
        $result = [];
        $targets = [];
        foreach ($items as $item) {
            if (!is_array($item) || !($item['enabled'] ?? false)) continue;
            foreach (['id', 'repo', 'branch', 'file', 'mode'] as $key) {
                if (!isset($item[$key]) || !is_string($item[$key])) throw new SyncException('invalid_config');
            }
            if (!preg_match('/^[a-zA-Z0-9-]{8,40}$/D', $item['id']) || isset($result[$item['id']])) throw new SyncException('invalid_config');
            if (!preg_match('~^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}/[a-zA-Z0-9_.-]{1,99}$~D', $item['repo'])) throw new SyncException('invalid_config');
            if ($item['branch'] === '' || strlen($item['branch']) > 200 || preg_match('/[\x00-\x20\x7f]/', $item['branch'])) throw new SyncException('invalid_config');
            $parts = explode('/', $item['file']);
            if ($item['file'] === '' || strlen($item['file']) > 300 || preg_match('/[\x00-\x1f\x7f\\\\]/', $item['file']) || array_intersect($parts, ['', '.', '..'])) throw new SyncException('invalid_config');
            if (!in_array($item['mode'], ['section', 'whole'], true)) throw new SyncException('invalid_config');
            foreach (['discussion', 'post'] as $key) {
                $value = $item[$key] ?? '';
                if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value < 1) throw new SyncException('invalid_config');
                $item[$key] = (int) $value;
            }
            if (isset($targets[$item['post']])) throw new SyncException('duplicate_target');
            $targets[$item['post']] = true;
            $item['repo'] = strtolower($item['repo']);
            $item['readme'] = (bool) ($item['readme'] ?? true);
            $item['releases'] = (bool) ($item['releases'] ?? false);
            $item['prereleases'] = (bool) ($item['prereleases'] ?? false);
            $result[$item['id']] = $item;
        }
        return $result;
    }

    public function actorId(): int
    {
        $value = $this->get('actor');
        if (!ctype_digit($value) || (int) $value < 1) throw new SyncException('actor_missing');
        return (int) $value;
    }
}
