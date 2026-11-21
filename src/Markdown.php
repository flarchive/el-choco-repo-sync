<?php

namespace ElChoco\RepoSync;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

final class Markdown
{
    public const START = '[REPOSYNC]';
    public const END = '[/REPOSYNC]';

    public function rewrite(string $text, array $mapping): string
    {
        $directory = dirname($mapping['file']);
        $directory = $directory === '.' ? '' : implode('/', array_map('rawurlencode', explode('/', $directory))).'/';
        $base = 'https://github.com/'.$mapping['repo'].'/blob/'.rawurlencode($mapping['branch']).'/'.$directory;
        $raw = 'https://raw.githubusercontent.com/'.$mapping['repo'].'/'.rawurlencode($mapping['branch']).'/'.$directory;
        $resolve = function (string $target, bool $image = false) use ($base, $raw): string {
            if ($target === '' || $target[0] === '#' || preg_match('~^(?:https?://|mailto:|//)~i', $target)) return $target;
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $target)) return '#';
            try {
                return (string) UriResolver::resolve(new Uri($image ? $raw : $base), new Uri($target));
            } catch (\Throwable) {
                return '#';
            }
        };
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        preg_match_all('/!\[([^\]]*)\]\[([^\]]*)\]/', $text, $references, PREG_SET_ORDER);
        $imageReferences = [];
        foreach ($references as $reference) $imageReferences[strtolower(trim($reference[2] !== '' ? $reference[2] : $reference[1]))] = true;
        $fence = null;
        $result = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $match)) {
                if ($fence === null) $fence = $match[1];
                elseif ($match[1][0] === $fence[0] && strlen($match[1]) >= strlen($fence)) $fence = null;
                $result[] = $line;
                continue;
            }
            if ($fence !== null || preg_match('/^(?: {4}|\t)/', $line)) { $result[] = $line; continue; }
            if (preg_match('/^ {0,3}\[([^\]]+)\]:\s*(<[^>]+>|\S+)(.*)$/', $line, $match)) {
                $dest = trim($match[2], '<>');
                $line = '['.$match[1].']: <'.$resolve($dest, isset($imageReferences[strtolower(trim($match[1]))])).'>'.$match[3];
            } else {
                $line = preg_replace_callback('~(`+)[^`]*?\1(*SKIP)(*F)|(!?\[[^\]\n]*\])\((<[^>\n]+>|[^\s()]+(?:\([^()]*\)[^\s()]*)*)([ \t]+["\x27][^\n]*?["\x27])?[ \t]*\)~', function ($match) use ($resolve) {
                    return $match[2].'(<'.$resolve(trim($match[3], '<>'), $match[2][0] === '!').'>'.($match[4] ?? '').')';
                }, $line);
                $line = preg_replace_callback('~(`+)[^`]*?\1(*SKIP)(*F)|(<(?:img|a)\b[^>]*?\b)(src|href)=("|\x27)(.*?)\4~i', function ($match) use ($resolve) {
                    return $match[2].$match[3].'='.$match[4].$resolve($match[5], strtolower($match[3]) === 'src').$match[4];
                }, $line);
            }
            $result[] = $line;
        }
        return trim(implode("\n", $result));
    }

    public function replace(string $old, string $readme, string $mode, bool $initialize = false): string
    {
        if (stripos($readme, self::START) !== false || stripos($readme, self::END) !== false) throw new SyncException('reserved_markers');
        if ($mode === 'whole') return $readme;
        $starts = substr_count(strtoupper($old), self::START);
        $ends = substr_count(strtoupper($old), self::END);
        if ($starts === 0 && $ends === 0 && $initialize) return rtrim($old)."\n\n".self::START."\n".$readme."\n".self::END;
        if ($starts !== 1 || $ends !== 1) throw new SyncException('markers_missing');
        $start = stripos($old, self::START) + strlen(self::START);
        $end = stripos($old, self::END);
        if ($end < $start) throw new SyncException('markers_missing');
        return substr($old, 0, $start)."\n".$readme."\n".substr($old, $end);
    }
}
