<?php

namespace App\Http\Controllers;

use App\Services\SpellHistory\SpellHistoryRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class SpellHistoryController extends Controller
{
    private const SAFE_MAXIMUM_PAGE = 5000;

    /**
     * Display one bounded page of precomputed Lucy revisions for a numeric spell ID.
     *
     * SpellHistoryRepository::forSpell() returns null when the archive does not
     * contain the spell. Its presentation result contains spell, revisions,
     * pagination, archive, and baseline keys. The controller flattens canonical
     * diff groups for the player-facing view while preserving lifecycle events.
     * The history repository is the only data source used by this route; the
     * normal spell-detail request never invokes it.
     */
    public function show(
        Request $request,
        int $spell,
        SpellHistoryRepository $historyRepository,
    ): Response {
        abort_unless($spell > 0, 404);

        $page = $this->canonicalPage($request);
        $maximumPage = max(1, min(
            (int) config('everquest.spell_history.max_page', 500),
            self::SAFE_MAXIMUM_PAGE,
        ));

        abort_unless($page >= 1 && $page <= $maximumPage, 404);

        $configuredPageSize = (int) config('everquest.spell_history.page_size', 25);
        $pageSize = max(1, min($configuredPageSize, 100));
        try {
            $result = $historyRepository->forSpell($spell, $page, $pageSize);
        } catch (InvalidArgumentException $exception) {
            $configuredCutoff = config('everquest.spell_history.baseline_date');
            if (! is_string($configuredCutoff) || trim($configuredCutoff) === '') {
                throw $exception;
            }

            $result = $historyRepository->forSpellAtDate($spell, null, $page, $pageSize);
            if ($result !== null) {
                $result['baseline'] = [
                    'configured' => true,
                    'invalid' => true,
                    'configured_date' => $configuredCutoff,
                ];
            }
        }

        abort_if($result === null, 404);

        $spellName = trim((string) data_get(
            $result,
            'spell.name',
            data_get($result, 'spell_name', "Spell #{$spell}"),
        ));
        $currentExpansion = (int) config('everquest.current_expansion', 0);
        $expansions = config('everquest.expansions', []);
        $expansionName = (string) data_get(
            $expansions,
            $currentExpansion,
            "Expansion {$currentExpansion}",
        );
        $baseline = $this->presentBaseline(data_get($result, 'baseline'));
        $revisions = $this->presentRevisions(data_get($result, 'revisions', []), $baseline);
        $pagination = $this->presentPagination(data_get($result, 'pagination', []), $page);
        $archive = $this->presentArchive($result);

        $viewData = [
            'spellSummary' => [
                'id' => $spell,
                'name' => $spellName !== '' ? $spellName : "Spell #{$spell}",
                'icon' => data_get($result, 'spell.icon', data_get($result, 'spell.new_icon')),
            ],
            'revisions' => collect($revisions),
            'pagination' => $pagination,
            'archive' => $archive,
            'baseline' => $baseline,
            'progression' => [
                'expansion_id' => $currentExpansion,
                'expansion_name' => $expansionName,
            ],
            'maximumPage' => $maximumPage,
            'metaTitle' => config('app.name').' - Spell History: '.($spellName !== '' ? $spellName : $spell),
        ];

        $response = response()->view('spells.history', $viewData);
        $datasetId = trim((string) data_get(
            $result,
            'dataset_id',
            data_get(
                $result,
                'dataset',
                data_get(
                    $result,
                    'archive.dataset',
                    data_get($result, 'archive.dataset_id', data_get($result, 'archive.build_hash', '')),
                ),
            ),
        ));

        if ($datasetId === '') {
            return $response->header('Cache-Control', 'no-store');
        }

        // Hash the actual representation so host-specific URLs, asset revisions,
        // navigation settings, and presentation changes cannot share a stale ETag.
        $etag = hash('sha256', (string) $response->getContent());

        $response->setEtag($etag);
        $response->setPublic();
        $response->setMaxAge(300);
        $response->headers->addCacheControlDirective('stale-while-revalidate', 60);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @param  mixed  $baseline
     * @return array<string, mixed>
     */
    private function presentBaseline($baseline): array
    {
        if (! is_array($baseline)) {
            return ['configured' => false];
        }

        $configuredAt = data_get($baseline, 'configured_captured_at', data_get($baseline, 'configured_date'));
        $matchedAt = data_get(
            $baseline,
            'matched_captured_at',
            data_get($baseline, 'snapshot.observed_at'),
        );
        $normalizedConfiguredAt = is_string($configuredAt)
            ? str_replace(' ', 'T', trim($configuredAt))
            : null;
        $hasExplicitTime = is_string($normalizedConfiguredAt) && str_contains($normalizedConfiguredAt, 'T');

        return [
            ...$baseline,
            'configured' => is_string($configuredAt) && trim($configuredAt) !== '',
            'invalid' => (bool) data_get($baseline, 'invalid', false),
            'exact' => $hasExplicitTime && $normalizedConfiguredAt === $matchedAt,
            'configured_captured_at' => $configuredAt,
            'configured_captured_label' => $this->timestampLabel($configuredAt),
            'matched_captured_at' => $matchedAt,
            'matched_captured_label' => $this->timestampLabel($matchedAt),
            'page' => (int) data_get($baseline, 'page', 0),
        ];
    }

    /**
     * @param  mixed  $revisions
     * @param  array<string, mixed>  $baseline
     * @return list<array<string, mixed>>
     */
    private function presentRevisions($revisions, array $baseline): array
    {
        if (! is_array($revisions)) {
            return [];
        }

        $matchedAt = data_get($baseline, 'matched_captured_at');
        $presented = [];

        foreach ($revisions as $revision) {
            if (! is_array($revision)) {
                continue;
            }

            $capturedAt = data_get($revision, 'captured_at', data_get($revision, 'observed_at'));
            $changes = [];

            if (is_array($revision['changes'] ?? null)) {
                $changes = array_values($revision['changes']);
            } elseif (is_array($revision['groups'] ?? null)) {
                foreach ($revision['groups'] as $group) {
                    if (! is_array($group) || ! is_array($group['changes'] ?? null)) {
                        continue;
                    }

                    $category = (string) ($group['section'] ?? 'advanced');
                    $groupLabel = trim((string) ($group['label'] ?? ''));

                    foreach ($group['changes'] as $change) {
                        if (! is_array($change)) {
                            continue;
                        }

                        $changeLabel = trim((string) ($change['label'] ?? $change['field'] ?? 'Spell field'));
                        $label = in_array($category, ['effects', 'reagents'], true) && $groupLabel !== ''
                            ? $groupLabel.': '.$changeLabel
                            : $changeLabel;
                        $before = data_get($change, 'before', data_get($change, 'old'));
                        $after = data_get($change, 'after', data_get($change, 'new'));
                        $presentedChange = [
                            ...$change,
                            'label' => $label,
                            'category' => $category,
                            'before' => $before,
                            'after' => $after,
                        ];

                        if (preg_match('/^effects\.\d+\.attribute$/D', (string) ($change['field'] ?? '')) === 1) {
                            $beforeDisplay = $this->spellEffectDisplay($before);
                            $afterDisplay = $this->spellEffectDisplay($after);
                            if ($beforeDisplay !== null) {
                                $presentedChange['before_display'] = $beforeDisplay;
                            }
                            if ($afterDisplay !== null) {
                                $presentedChange['after_display'] = $afterDisplay;
                            }
                        }

                        $changes[] = $presentedChange;
                    }
                }
            }

            $relativeToBaseline = null;
            if (is_string($capturedAt) && is_string($matchedAt)) {
                $relativeToBaseline = $capturedAt > $matchedAt ? 'after' : 'before';
            }

            $presented[] = [
                ...$revision,
                'captured_at' => $capturedAt,
                'captured_label' => $this->timestampLabel($capturedAt),
                'is_baseline' => (bool) data_get(
                    $revision,
                    'is_baseline',
                    data_get($revision, 'is_server_baseline', false),
                ),
                'relative_to_baseline' => $relativeToBaseline,
                'changes' => $changes,
                'change_count' => (int) data_get($revision, 'change_count', count($changes)),
            ];
        }

        return $presented;
    }

    /** @param mixed $pagination @return array<string, mixed> */
    private function presentPagination($pagination, int $requestedPage): array
    {
        if (! is_array($pagination)) {
            $pagination = [];
        }

        return [
            ...$pagination,
            'current_page' => (int) data_get($pagination, 'current_page', data_get($pagination, 'page', $requestedPage)),
            'has_previous' => (bool) data_get($pagination, 'has_previous', $requestedPage > 1),
            'has_more' => (bool) data_get($pagination, 'has_more', false),
        ];
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function presentArchive(array $result): array
    {
        $archive = data_get($result, 'archive', []);
        if (! is_array($archive)) {
            $archive = [];
        }

        $firstCapturedAt = data_get(
            $archive,
            'first_captured_at',
            data_get($archive, 'first_snapshot.observed_at'),
        );
        $lastCapturedAt = data_get(
            $archive,
            'last_captured_at',
            data_get($archive, 'last_snapshot.observed_at'),
        );

        return [
            ...$archive,
            'revision_count' => (int) data_get(
                $archive,
                'revision_count',
                data_get($result, 'spell.revision_count', 0),
            ),
            'snapshot_count' => (int) data_get($archive, 'snapshot_count', 0),
            'first_captured_at' => $firstCapturedAt,
            'first_captured_label' => $this->timestampLabel($firstCapturedAt),
            'last_captured_at' => $lastCapturedAt,
            'last_captured_label' => $this->timestampLabel($lastCapturedAt),
        ];
    }

    private function timestampLabel(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $timezone = new DateTimeZone('UTC');
        $formats = str_contains($value, 'T') || str_contains($value, ' ')
            ? [
                ['parse' => '!Y-m-d\\TH:i:s', 'round_trip' => 'Y-m-d\\TH:i:s'],
                ['parse' => '!Y-m-d H:i:s', 'round_trip' => 'Y-m-d H:i:s'],
            ]
            : [['parse' => '!Y-m-d', 'round_trip' => 'Y-m-d']];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format['parse'], $value, $timezone);
            if ($date !== false && $date->format($format['round_trip']) === $value) {
                return str_contains($format['parse'], 'H:i:s')
                    ? $date->format('F j, Y \\a\\t g:i:s A')
                    : $date->format('F j, Y');
            }
        }

        return $value;
    }

    private function spellEffectDisplay(mixed $value): ?string
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1)) {
            return null;
        }

        $effectId = (int) $value;
        $effectName = config("everquest.spell_effects.{$effectId}");
        if (! is_string($effectName) || trim($effectName) === '') {
            return null;
        }

        return trim($effectName)." ({$effectId})";
    }

    private function canonicalPage(Request $request): int
    {
        $queryString = $request->server('QUERY_STRING', '');
        abort_unless(is_string($queryString), 404);

        if ($queryString === '') {
            abort_unless($request->query() === [], 404);

            return 1;
        }

        abort_unless(
            preg_match('/^page=([1-9][0-9]*)$/D', $queryString, $matches) === 1
                && $request->query() === ['page' => $matches[1]],
            404,
        );

        $page = (int) $matches[1];
        abort_unless((string) $page === $matches[1], 404);

        return $page;
    }
}
