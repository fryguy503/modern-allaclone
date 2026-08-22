<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

class PatchArchive
{
    private ?array $archive = null;

    private ?array $slugLookup = null;

    private ?array $suggestionIndex = null;

    public function dataPath(): string
    {
        return database_path('data/everquest-patch-history.json');
    }

    public function csvPath(): string
    {
        return database_path('data/everquest-patch-history.csv');
    }

    public function suggestionPath(): string
    {
        return database_path('data/everquest-patch-suggestions.json');
    }

    public function metadata(): array
    {
        $archive = $this->load();

        return array_diff_key($archive, ['patches' => true]);
    }

    public function coverage(): array
    {
        return $this->load()['coverage'];
    }

    public function all(): array
    {
        return $this->load()['patches'];
    }

    public function find(string $slug): ?array
    {
        if ($this->slugLookup === null) {
            $this->slugLookup = [];
            foreach ($this->all() as $patch) {
                $this->slugLookup[$patch['slug']] = $patch;
            }
        }

        return $this->slugLookup[$slug] ?? null;
    }

    public function suggest(string $query, int $limit = 5): array
    {
        $terms = $this->queryTerms(trim($query));
        if (! $terms || $limit < 1) return [];

        $matches = [];
        foreach ($this->loadSuggestions() as $patch) {
            $searchable = $this->lower($patch['search_text']);
            $title = $this->lower($patch['title']);
            $score = 0;
            foreach ($terms as $term) {
                $needle = $this->lower($term);
                if (! str_contains($searchable, $needle)) continue 2;
                if (str_contains($title, $needle)) $score += 30;
                $score += min(10, substr_count($searchable, $needle));
            }
            $patch['_score'] = $score;
            unset($patch['search_text']);
            $matches[] = $patch;
        }

        usort($matches, fn (array $left, array $right) =>
            ($right['_score'] <=> $left['_score']) ?: ($right['patch_date'] <=> $left['patch_date'])
        );

        return array_map(function (array $patch): array {
            unset($patch['_score']);
            return $patch;
        }, array_slice($matches, 0, min($limit, 20)));
    }

    public function paginate(array $filters, int $page, int $perPage, string $path): LengthAwarePaginator
    {
        $matches = $this->filtered($filters, true);
        $total = count($matches);
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));
        $items = array_slice($matches, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => $path,
            'query' => array_filter($filters, fn ($value) => $value !== null && $value !== '' && $value !== []),
        ]);
    }

    public function filtered(array $filters, bool $withExcerpts = false): array
    {
        $query = trim((string) ($filters['q'] ?? ''));
        $scope = $filters['scope'] ?? 'all';
        $terms = $this->queryTerms($query);
        $categories = array_values(array_filter((array) ($filters['categories'] ?? [])));
        $matches = [];

        foreach ($this->all() as $patch) {
            if (($filters['from'] ?? null) && $patch['patch_date'] < $filters['from']) continue;
            if (($filters['to'] ?? null) && $patch['patch_date'] > $filters['to']) continue;
            if (($filters['year'] ?? null) && $patch['year'] !== (int) $filters['year']) continue;
            if (($filters['kind'] ?? null) && $patch['kind'] !== $filters['kind']) continue;
            if (($filters['expansion'] ?? null) && $patch['expansion_code'] !== $filters['expansion']) continue;
            if (($filters['source'] ?? null) && ! in_array($filters['source'], $patch['source_files'], true)) continue;

            $patchCategories = array_column($patch['categories'], 'slug');
            if ($categories && array_diff($categories, $patchCategories)) continue;

            $score = 0;
            if ($terms) {
                $title = $this->lower($patch['title'].' '.$patch['display_date']);
                $body = $this->lower($patch['content']);
                $searchable = match ($scope) {
                    'title' => $title,
                    'body' => $body,
                    default => $title.' '.$body,
                };

                foreach ($terms as $term) {
                    $needle = $this->lower($term);
                    if (! str_contains($searchable, $needle)) continue 2;
                    if (str_contains($title, $needle)) $score += 30;
                    $score += min(15, substr_count($body, $needle));
                }

                if ($this->lower($patch['title']) === $this->lower($query)) $score += 100;
            }

            if ($terms) $patch['_score'] = $score;
            if ($withExcerpts) {
                $patch['_excerpt'] = $this->excerpt($patch['content'], $terms);
            }
            $matches[] = $patch;
        }

        $sort = $filters['sort'] ?? ($query !== '' ? 'relevance' : 'newest');
        usort($matches, function (array $left, array $right) use ($sort, $query): int {
            if ($sort === 'relevance' && $query !== '') {
                $score = ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0);
                if ($score !== 0) return $score;
            }

            $date = $left['patch_date'] <=> $right['patch_date'];
            if ($date === 0) $date = $left['sequence'] <=> $right['sequence'];

            return $sort === 'oldest' ? $date : -$date;
        });

        if (! $withExcerpts) {
            foreach ($matches as &$match) unset($match['_score']);
            unset($match);
        }

        return $matches;
    }

    public function facets(): array
    {
        $years = [];
        $categories = [];
        $expansions = [];
        $kinds = [];
        $sources = [];

        foreach ($this->all() as $patch) {
            $years[$patch['year']] = ($years[$patch['year']] ?? 0) + 1;
            $expansions[$patch['expansion_code']] ??= ['label' => $patch['expansion'], 'count' => 0];
            $expansions[$patch['expansion_code']]['count']++;
            $kinds[$patch['kind']] = ($kinds[$patch['kind']] ?? 0) + 1;
            foreach ($patch['categories'] as $category) {
                $categories[$category['slug']] ??= ['label' => $category['label'], 'count' => 0];
                $categories[$category['slug']]['count']++;
            }
            foreach ($patch['source_files'] as $source) {
                $sources[$source] = ($sources[$source] ?? 0) + 1;
            }
        }

        krsort($years);
        uasort($expansions, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        uasort($categories, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        ksort($kinds);
        ksort($sources);

        return compact('years', 'categories', 'expansions', 'kinds', 'sources');
    }

    public function totals(): array
    {
        $patches = $this->all();

        return [
            'patches' => count($patches),
            'years' => count(array_unique(array_column($patches, 'year'))),
            'words' => array_sum(array_column($patches, 'word_count')),
            'changes' => array_sum(array_column($patches, 'change_count')),
            'sources' => $this->coverage()['source_file_count'],
        ];
    }

    public function adjacent(array $patch): array
    {
        $patches = $this->all();
        foreach ($patches as $index => $candidate) {
            if ($candidate['slug'] !== $patch['slug']) continue;

            return [
                'previous' => $patches[$index - 1] ?? null,
                'next' => $patches[$index + 1] ?? null,
            ];
        }

        return ['previous' => null, 'next' => null];
    }

    public function related(array $patch, int $limit = 4): array
    {
        $categories = array_column($patch['categories'], 'slug');
        $related = [];
        foreach ($this->all() as $candidate) {
            if ($candidate['slug'] === $patch['slug']) continue;
            $overlap = count(array_intersect($categories, array_column($candidate['categories'], 'slug')));
            $score = ($overlap * 5)
                + ($candidate['expansion_code'] === $patch['expansion_code'] ? 4 : 0)
                + ($candidate['year'] === $patch['year'] ? 2 : 0);
            if ($score < 5) continue;
            $candidate['_related_score'] = $score;
            $related[] = $candidate;
        }

        usort($related, function (array $left, array $right) use ($patch): int {
            $score = $right['_related_score'] <=> $left['_related_score'];
            if ($score !== 0) return $score;
            return abs(strtotime($left['patch_date']) - strtotime($patch['patch_date']))
                <=> abs(strtotime($right['patch_date']) - strtotime($patch['patch_date']));
        });

        return array_slice($related, 0, $limit);
    }

    public function onThisDay(array $patch): array
    {
        $monthDay = substr($patch['patch_date'], 5);

        return array_values(array_filter($this->all(), fn (array $candidate) =>
            $candidate['slug'] !== $patch['slug'] && substr($candidate['patch_date'], 5) === $monthDay
        ));
    }

    public function yearArchive(int $year): array
    {
        $months = [];
        foreach ($this->all() as $patch) {
            if ($patch['year'] !== $year) continue;
            $months[$patch['month']][] = $patch;
        }
        ksort($months);

        return $months;
    }

    public function checksum(): string
    {
        return hash_file('sha256', $this->dataPath());
    }

    public function lastModified(): int
    {
        return filemtime($this->dataPath()) ?: time();
    }

    private function load(): array
    {
        if ($this->archive !== null) return $this->archive;
        $path = $this->dataPath();
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The historical patch archive is unavailable.');
        }

        try {
            $archive = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('The historical patch archive is invalid.', previous: $exception);
        }

        if (! isset($archive['schema_version'], $archive['coverage'], $archive['patches']) || ! is_array($archive['patches'])) {
            throw new RuntimeException('The historical patch archive has an unsupported schema.');
        }

        return $this->archive = $archive;
    }

    private function loadSuggestions(): array
    {
        if ($this->suggestionIndex !== null) return $this->suggestionIndex;
        $path = $this->suggestionPath();
        if (! is_file($path) || ! is_readable($path)) return $this->suggestionIndex = [];

        try {
            $index = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->suggestionIndex = [];
        }

        return $this->suggestionIndex = is_array($index['patches'] ?? null) ? $index['patches'] : [];
    }

    private function queryTerms(string $query): array
    {
        if ($query === '') return [];
        preg_match_all('/"([^"]+)"|(\S+)/u', $query, $matches, PREG_SET_ORDER);
        $terms = [];
        foreach ($matches as $match) {
            $term = trim($match[1] !== '' ? $match[1] : $match[2]);
            if ($term !== '') $terms[] = mb_substr($term, 0, 60);
            if (count($terms) === 10) break;
        }

        return array_values(array_unique($terms));
    }

    private function excerpt(string $content, array $terms): string
    {
        $plain = preg_replace('/\s+/u', ' ', trim($content));
        if (! $terms) return mb_strlen($plain) > 320 ? mb_substr($plain, 0, 317).'…' : $plain;

        $position = null;
        foreach ($terms as $term) {
            $candidate = mb_stripos($plain, $term);
            if ($candidate !== false && ($position === null || $candidate < $position)) $position = $candidate;
        }
        $position ??= 0;
        $start = max(0, $position - 110);
        $excerpt = mb_substr($plain, $start, 340);
        if ($start > 0) $excerpt = '…'.ltrim($excerpt);
        if ($start + 340 < mb_strlen($plain)) $excerpt = rtrim($excerpt).'…';

        return $excerpt;
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
