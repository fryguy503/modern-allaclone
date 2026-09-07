<?php

namespace App\Services;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PatchContentFormatter
{
    public function format(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $sections = [];
        $current = ['title' => 'Patch notes', 'anchor' => 'patch-notes', 'blocks' => []];
        $paragraph = [];
        $list = null;
        $anchors = [];

        $flushParagraph = function () use (&$paragraph, &$current): void {
            if (! $paragraph) return;
            $current['blocks'][] = ['type' => 'paragraph', 'text' => trim(implode(' ', $paragraph))];
            $paragraph = [];
        };
        $flushList = function () use (&$list, &$current): void {
            if ($list === null) return;
            $current['blocks'][] = $list;
            $list = null;
        };
        $flushSection = function () use (&$current, &$sections): void {
            if ($current['blocks']) $sections[] = $current;
        };

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            $heading = $this->heading($line);
            if ($heading !== null) {
                $flushParagraph();
                $flushList();
                $flushSection();
                $anchor = Str::slug($heading) ?: 'section';
                $anchors[$anchor] = ($anchors[$anchor] ?? 0) + 1;
                if ($anchors[$anchor] > 1) $anchor .= '-'.$anchors[$anchor];
                $current = ['title' => $heading, 'anchor' => $anchor, 'blocks' => []];
                continue;
            }

            $orderedMatch = preg_match('/^(?:\d+)[.)]\s+(.+)$/u', $line, $match);
            $bulletMatch = ! $orderedMatch && preg_match('/^((?:(?:-|\*|\+)\s+)+)(.+)$/u', $line, $match);
            $compactBulletMatch = ! $orderedMatch && ! $bulletMatch && preg_match('/^(-+)\s+(.+)$/u', $line, $match);
            if ($orderedMatch || $bulletMatch || $compactBulletMatch) {
                $flushParagraph();
                $ordered = (bool) $orderedMatch;
                $text = trim($ordered ? $match[1] : $match[2]);
                $depth = $ordered ? 0 : max(0, substr_count($match[1], '-') + substr_count($match[1], '*') + substr_count($match[1], '+') - 1);
                if ($list === null || $list['ordered'] !== $ordered) {
                    $flushList();
                    $list = ['type' => 'list', 'ordered' => $ordered, 'items' => []];
                }
                $list['items'][] = ['text' => $text, 'depth' => min(3, $depth)];
                continue;
            }

            if ($list !== null && $list['items']) {
                $lastItem = array_key_last($list['items']);
                $list['items'][$lastItem]['text'] .= ' '.$line;
                continue;
            }
            $paragraph[] = $line;
        }

        $flushParagraph();
        $flushList();
        $flushSection();

        return $sections ?: [['title' => 'Patch notes', 'anchor' => 'patch-notes', 'blocks' => []]];
    }

    public function highlight(string $text, ?string $query): HtmlString
    {
        $escaped = e($text);
        $query = trim((string) $query);
        if ($query === '') return new HtmlString($escaped);

        preg_match_all('/"([^"]+)"|(\S+)/u', $query, $matches, PREG_SET_ORDER);
        $terms = [];
        foreach ($matches as $match) {
            $term = trim($match[1] !== '' ? $match[1] : $match[2]);
            if ($term !== '') $terms[] = preg_quote(e(mb_substr($term, 0, 60)), '/');
            if (count($terms) === 10) break;
        }
        if (! $terms) return new HtmlString($escaped);

        $pattern = '/('.implode('|', array_unique($terms)).')/iu';
        $highlighted = preg_replace($pattern, '<mark class="patch-highlight">$1</mark>', $escaped);

        return new HtmlString($highlighted ?? $escaped);
    }

    private function heading(string $line): ?string
    {
        if (preg_match('/^(?:#{2,5}\s*)?\*{1,4}\s*([^*]{2,90}?)\s*\*{1,4}$/u', $line, $match)) {
            return trim($match[1], " \t\n\r\0\x0B-_");
        }
        if (mb_strlen($line) <= 70 && preg_match("/^([\\pL][\\pL\\pN &\\/'`’.-]{2,65}):$/u", $line, $match)) {
            return trim($match[1]);
        }

        return null;
    }
}
