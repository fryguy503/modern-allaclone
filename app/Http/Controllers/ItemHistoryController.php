<?php

namespace App\Http\Controllers;

use App\Services\ItemHistory\ItemHistoryRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ItemHistoryController extends Controller
{
    private const SAFE_MAXIMUM_PAGE = 5000;

    public function show(
        Request $request,
        int $item,
        ItemHistoryRepository $historyRepository,
    ): Response {
        abort_unless($item > 0, 404);

        [$view, $page] = $this->canonicalOptions($request);
        $maximumPage = max(1, min(
            (int) config('everquest.item_history.max_page', 500),
            self::SAFE_MAXIMUM_PAGE,
        ));
        abort_unless($page <= $maximumPage, 404);

        $pageSize = max(1, min(
            (int) config('everquest.item_history.page_size', 25),
            100,
        ));
        $result = $historyRepository->forItem($item, $page, $pageSize);
        abort_if($result === null, 404);

        $name = trim((string) data_get($result, 'item.name', ''));
        $revisions = array_map(function (array $revision): array {
            $observedAt = $revision['observed_at'] ?? null;

            return [
                ...$revision,
                'observed_label' => $this->timestampLabel($observedAt),
                'snapshot_lines' => data_get($revision, 'detail.snapshot_lines', []),
            ];
        }, data_get($result, 'revisions', []));

        $archive = data_get($result, 'archive', []);
        $archive['first_observed_label'] = $this->timestampLabel($archive['first_observed_at'] ?? null);
        $archive['last_observed_label'] = $this->timestampLabel($archive['last_observed_at'] ?? null);
        $archive['generated_label'] = $this->timestampLabel($archive['generated_at'] ?? null);

        $response = response()->view('items.history', [
            'itemSummary' => [
                'id' => $item,
                'name' => $name !== '' ? $name : "Item #{$item}",
                'icon' => data_get($result, 'item.icon'),
            ],
            'revisions' => collect($revisions),
            'pagination' => data_get($result, 'pagination', []),
            'archive' => $archive,
            'displayMode' => $view,
            'maximumPage' => $maximumPage,
            'metaTitle' => config('app.name').' - Item History: '.($name !== '' ? $name : $item),
        ]);

        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->headers->addCacheControlDirective('stale-while-revalidate', 60);
        $response->isNotModified($request);

        return $response;
    }

    /** @return array{0: 'cards'|'table', 1: int} */
    private function canonicalOptions(Request $request): array
    {
        $queryString = $request->server('QUERY_STRING', '');
        abort_unless(is_string($queryString), 404);

        if ($queryString === '') {
            abort_unless($request->query() === [], 404);

            return ['cards', 1];
        }

        $patterns = [
            '/^view=(cards|table)$/D' => static fn (array $matches): array => [$matches[1], 1],
            '/^page=([2-9][0-9]*)$/D' => static fn (array $matches): array => ['cards', (int) $matches[1]],
            '/^view=(cards|table)&page=([2-9][0-9]*)$/D' => static fn (array $matches): array => [$matches[1], (int) $matches[2]],
        ];

        foreach ($patterns as $pattern => $result) {
            if (preg_match($pattern, $queryString, $matches) !== 1) {
                continue;
            }

            [$view, $page] = $result($matches);
            $expected = $page === 1
                ? ['view' => $view]
                : ($view === 'cards' && ! str_starts_with($queryString, 'view=')
                    ? ['page' => (string) $page]
                    : ['view' => $view, 'page' => (string) $page]);
            abort_unless($request->query() === $expected, 404);

            return [$view, $page];
        }

        abort(404);
    }

    private function timestampLabel(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $formats = [
            ['parse' => '!Y-m-d\\TH:i:s', 'round_trip' => 'Y-m-d\\TH:i:s'],
            ['parse' => '!Y-m-d\\TH:i:s\\Z', 'round_trip' => 'Y-m-d\\TH:i:s\\Z'],
            ['parse' => '!Y-m-d\\TH:i:s.v\\Z', 'round_trip' => 'Y-m-d\\TH:i:s.v\\Z'],
            ['parse' => '!Y-m-d H:i:s', 'round_trip' => 'Y-m-d H:i:s'],
        ];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format['parse'], $value, new DateTimeZone('UTC'));
            if ($date !== false && $date->format($format['round_trip']) === $value) {
                return $date->format('F j, Y \\a\\t g:i:s A');
            }
        }

        return $value;
    }
}
