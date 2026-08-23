<?php

namespace App\Services\Tradeskills;

use App\Models\DiscoveredItem;
use App\Models\TradeskillRecipe;
use Illuminate\Support\Facades\DB;
use LengthException;

class RecipeGraphBuilder
{
    public const SCHEMA_VERSION = 2;

    private int $maxDepth;

    private int $maxNodes;

    private int $maxAlternativesPerItem;

    public function __construct(private readonly MaterialSourceResolver $sourceResolver)
    {
        $this->maxDepth = $this->boundedConfigInt('max_depth', 12, 1, 30);
        $this->maxNodes = $this->boundedConfigInt('max_nodes', 500, 25, 2_000);
        $this->maxAlternativesPerItem = $this->boundedConfigInt(
            'max_alternatives_per_item',
            10,
            1,
            50,
        );
    }

    /**
     * Build a quantity-independent graph that the browser can safely recalculate.
     *
     * @return array<string, mixed>
     */
    public function build(TradeskillRecipe $root): array
    {
        return $this->buildCacheEnvelope($root)['graph'];
    }

    /**
     * Build the public graph together with server-only discovery state used to
     * validate cached graphs without ever sending hidden item ids to a visitor.
     *
     * @return array{graph: array<string, mixed>, discoverySnapshot: array<int, bool>}
     */
    public function buildCacheEnvelope(TradeskillRecipe $root): array
    {
        $rootRecipeId = (int) $root->getKey();
        $recipeRows = [$rootRecipeId => $this->recipeDataFromModel($root)];
        $entriesByRecipe = $this->loadEntries([$rootRecipeId]);
        $itemMetadata = [];
        $worldTypes = [];
        $missingItemIds = [];
        $discovered = [];
        $warnings = [];

        $this->captureEntryNodes(
            $entriesByRecipe[$rootRecipeId] ?? [],
            $itemMetadata,
            $worldTypes,
            $missingItemIds,
        );
        $this->resolveDiscovery(array_keys($itemMetadata), $discovered);

        if (($entriesByRecipe[$rootRecipeId] ?? []) === []) {
            $warnings[] = 'This recipe has no recipe entries and cannot be expanded.';
        }
        if (! (bool) ($recipeRows[$rootRecipeId]['enabled'] ?? false)) {
            $warnings[] = 'The selected root recipe is disabled on this server.';
        }
        if ($this->nodeCount($recipeRows, $itemMetadata, $worldTypes, $missingItemIds) > $this->maxNodes) {
            throw new LengthException('The root recipe exceeds the configured planner node limit.');
        }

        $frontier = [];
        $hiddenItemsEncountered = false;
        $depthLimitReached = false;
        $this->addChildItemsToFrontier(
            $entriesByRecipe[$rootRecipeId] ?? [],
            1,
            $frontier,
            $discovered,
            $hiddenItemsEncountered,
            $depthLimitReached,
        );

        $processedItems = [];
        $recipeDepths = [$rootRecipeId => 0];
        $alternativesByItem = [];
        $alternativeLimitReached = false;
        $nodeLimitReached = false;

        while ($frontier !== []) {
            ksort($frontier, SORT_NUMERIC);
            $currentFrontier = $frontier;
            $frontier = [];

            foreach (array_keys($currentFrontier) as $itemId) {
                $processedItems[(int) $itemId] = true;
            }

            $candidateRows = $this->findAlternatives(array_map('intval', array_keys($currentFrontier)));
            $candidatesByItem = [];
            $pendingRecipeRows = [];
            $pendingRecipeDepths = [];

            foreach ($currentFrontier as $itemId => $depth) {
                $itemId = (int) $itemId;
                $candidates = $candidateRows[$itemId] ?? [];

                if (count($candidates) > $this->maxAlternativesPerItem) {
                    $alternativeLimitReached = true;
                    $candidates = array_slice($candidates, 0, $this->maxAlternativesPerItem);
                }

                $candidatesByItem[$itemId] = $candidates;
                foreach ($candidates as $candidate) {
                    $recipeId = (int) $candidate['recipeId'];
                    if (isset($recipeRows[$recipeId])) {
                        continue;
                    }

                    if (! isset($pendingRecipeRows[$recipeId])) {
                        $pendingRecipeRows[$recipeId] = $candidate['recipe'];
                        $pendingRecipeDepths[$recipeId] = (int) $depth;
                    } else {
                        $pendingRecipeDepths[$recipeId] = min(
                            $pendingRecipeDepths[$recipeId],
                            (int) $depth,
                        );
                    }
                }
            }

            $availableNodes = max(
                0,
                $this->maxNodes - $this->nodeCount($recipeRows, $itemMetadata, $worldTypes, $missingItemIds),
            );
            if (count($pendingRecipeRows) > $availableNodes) {
                $pendingRecipeRows = array_slice($pendingRecipeRows, 0, $availableNodes, true);
                $pendingRecipeDepths = array_intersect_key($pendingRecipeDepths, $pendingRecipeRows);
                $nodeLimitReached = true;
            }

            $pendingEntries = $this->loadEntries(array_map('intval', array_keys($pendingRecipeRows)));
            $acceptedRecipeIds = [];

            foreach ($pendingRecipeRows as $recipeId => $recipeRow) {
                $recipeId = (int) $recipeId;
                $entries = $pendingEntries[$recipeId] ?? [];
                [$newItems, $newWorldTypes, $newMissingItems] = $this->newEntryNodeIdentities(
                    $entries,
                    $itemMetadata,
                    $worldTypes,
                    $missingItemIds,
                );
                $nodeCost = 1 + count($newItems) + count($newWorldTypes) + count($newMissingItems);

                if ($this->nodeCount($recipeRows, $itemMetadata, $worldTypes, $missingItemIds) + $nodeCost > $this->maxNodes) {
                    $nodeLimitReached = true;

                    continue;
                }

                $recipeRows[$recipeId] = $recipeRow;
                $entriesByRecipe[$recipeId] = $entries;
                $recipeDepths[$recipeId] = $pendingRecipeDepths[$recipeId] ?? $this->maxDepth;
                $acceptedRecipeIds[] = $recipeId;
                $this->captureEntryNodes($entries, $itemMetadata, $worldTypes, $missingItemIds);
            }

            $newItemIds = array_values(array_diff(array_keys($itemMetadata), array_keys($discovered)));
            $this->resolveDiscovery($newItemIds, $discovered);

            foreach ($candidatesByItem as $itemId => $candidates) {
                foreach ($candidates as $candidate) {
                    $recipeId = (int) $candidate['recipeId'];
                    if (! isset($recipeRows[$recipeId])) {
                        continue;
                    }

                    $alternativesByItem[$itemId][$recipeId] = [
                        'recipeId' => $recipeId,
                        'yield' => (int) $candidate['yield'],
                    ];
                }
            }

            foreach ($acceptedRecipeIds as $recipeId) {
                $nextDepth = ($recipeDepths[$recipeId] ?? 0) + 1;
                $nextItems = [];
                $this->addChildItemsToFrontier(
                    $entriesByRecipe[$recipeId] ?? [],
                    $nextDepth,
                    $nextItems,
                    $discovered,
                    $hiddenItemsEncountered,
                    $depthLimitReached,
                );

                foreach ($nextItems as $itemId => $depth) {
                    if (isset($processedItems[$itemId])) {
                        continue;
                    }

                    $frontier[$itemId] = isset($frontier[$itemId])
                        ? min($frontier[$itemId], $depth)
                        : $depth;
                }
            }
        }

        if ($hiddenItemsEncountered) {
            $warnings[] = 'Undiscovered items were redacted and were not expanded.';
        }
        if ($missingItemIds !== []) {
            $warnings[] = 'Some recipe entries refer to items that are missing from the items table.';
        }
        if ($depthLimitReached) {
            $warnings[] = 'Some branches were stopped at the configured recursion depth.';
        }
        if ($alternativeLimitReached) {
            $warnings[] = 'Some items have more recipe alternatives than this server allows the planner to return.';
        }
        if ($nodeLimitReached) {
            $warnings[] = 'The recipe graph was truncated at the configured node limit.';
        }
        if ($this->hasCycle($entriesByRecipe, $alternativesByItem)) {
            $warnings[] = 'A circular recipe dependency was detected; the client will treat that branch as an acquisition step.';
        }

        $sources = $this->sourceResolver->resolve(array_values(array_filter(
            array_keys($itemMetadata),
            fn (int $itemId): bool => $discovered[$itemId] ?? false,
        )));
        $worldIcons = $this->loadWorldIcons(array_keys($worldTypes));
        [$itemKeys, $normalizedItems] = $this->normalizeItems(
            $itemMetadata,
            $worldTypes,
            $worldIcons,
            $missingItemIds,
            $discovered,
            $sources,
        );
        $normalizedRecipes = $this->normalizeRecipes($recipeRows, $entriesByRecipe, $itemKeys, $normalizedItems);
        $normalizedAlternatives = $this->normalizeAlternatives(
            $alternativesByItem,
            $recipeRows,
            $itemKeys,
        );

        ksort($normalizedRecipes, SORT_NUMERIC);
        ksort($normalizedItems, SORT_NATURAL);
        ksort($normalizedAlternatives, SORT_NATURAL);

        $rootRecipe = $normalizedRecipes[$rootRecipeId] ?? null;
        $rootProducts = $rootRecipe['products'] ?? [];
        if ($rootRecipe !== null) {
            $returnedComponentKeys = [];
            foreach ($rootRecipe['components'] as $component) {
                if ((int) $component['returnedOnSuccess'] > 0) {
                    $returnedComponentKeys[$component['itemKey']] = true;
                }
            }

            $targetProducts = array_values(array_filter(
                $rootProducts,
                static fn (array $product): bool => ! isset($returnedComponentKeys[$product['itemKey']]),
            ));
            if ($targetProducts !== []) {
                $rootProducts = $targetProducts;
            }
        }

        ksort($discovered, SORT_NUMERIC);

        return [
            'graph' => [
                'schemaVersion' => self::SCHEMA_VERSION,
                'rootRecipeId' => $rootRecipeId,
                'rootName' => (string) $root->name,
                'rootProducts' => $rootProducts,
                'recipes' => $normalizedRecipes,
                'items' => $normalizedItems,
                'alternatives' => $normalizedAlternatives,
                'warnings' => array_values(array_unique($warnings)),
                'limits' => $this->limits(),
            ],
            'discoverySnapshot' => $discovered,
        ];
    }

    /** @param array<string, mixed> $envelope */
    public function cacheEnvelopeIsCurrent(array $envelope): bool
    {
        if (! isset($envelope['graph']) || ! is_array($envelope['graph'])) {
            return false;
        }
        if (! config('everquest.discovered_items.enable', false)) {
            return true;
        }

        $snapshot = $envelope['discoverySnapshot'] ?? null;
        if (! is_array($snapshot) || count($snapshot) > $this->maxNodes) {
            return false;
        }

        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', array_keys($snapshot)),
            static fn (int $itemId): bool => $itemId > 0,
        )));
        if (count($itemIds) !== count($snapshot)) {
            return false;
        }

        $currentlyDiscovered = DiscoveredItem::query()
            ->whereIn('item_id', $itemIds)
            ->pluck('item_id')
            ->mapWithKeys(static fn ($itemId): array => [(int) $itemId => true])
            ->all();

        foreach ($snapshot as $itemId => $wasDiscovered) {
            if ((bool) $wasDiscovered !== isset($currentlyDiscovered[(int) $itemId])) {
                return false;
            }
        }

        return true;
    }

    public function cacheKey(TradeskillRecipe $root): string
    {
        $dimensions = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'currentExpansion' => (int) config('everquest.current_expansion', 0),
            'discoveryEnabled' => (bool) config('everquest.discovered_items.enable', false),
            'maxDepth' => $this->maxDepth,
            'maxNodes' => $this->maxNodes,
            'maxAlternatives' => $this->maxAlternativesPerItem,
            'maxSourcesPerType' => (int) config('everquest.tradeskill_planner.max_sources_per_type', 5),
            'maxSourceRows' => (int) config('everquest.tradeskill_planner.max_source_rows', 10_000),
            'clientLimits' => $this->limits(),
            'ignoreZones' => array_values((array) config('everquest.ignore_zones', [])),
            'excludeMerchantDrops' => (bool) config('everquest.merchants_dont_drop_stuff', true),
            'objectContainers' => (array) config('everquest.object_containers', []),
            'tradeskills' => (array) config('everquest.skills.tradeskill', []),
        ];
        $digest = substr(hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR)), 0, 24);

        return sprintf('tradeskill-planner:v%d:r%d:%s', self::SCHEMA_VERSION, (int) $root->getKey(), $digest);
    }

    /**
     * Limits that are safe and useful for the browser-side planner.
     *
     * @return array{maxQuantity: int, maxInventoryPerItem: int, maxSavedPlans: int, maxShareLength: int, maxTotalQuantity: int, maxDepth: int, maxNodes: int}
     */
    public function limits(): array
    {
        return [
            'maxQuantity' => $this->boundedConfigInt('max_quantity', 1_000, 1, 1_000_000),
            'maxInventoryPerItem' => $this->boundedConfigInt(
                'max_inventory_per_item',
                1_000_000,
                0,
                1_000_000_000,
            ),
            'maxSavedPlans' => $this->boundedConfigInt('max_saved_plans', 50, 1, 500),
            'maxShareLength' => $this->boundedConfigInt('max_share_length', 8_000, 512, 32_000),
            'maxTotalQuantity' => $this->boundedConfigInt(
                'max_total_quantity',
                10_000_000,
                1_000,
                1_000_000_000,
            ),
            'maxDepth' => $this->maxDepth,
            'maxNodes' => $this->maxNodes,
        ];
    }

    /**
     * @param  array<int>  $recipeIds
     * @return array<int, array<int, object>>
     */
    private function loadEntries(array $recipeIds): array
    {
        $recipeIds = array_values(array_unique(array_filter($recipeIds, static fn (int $id): bool => $id > 0)));
        if ($recipeIds === []) {
            return [];
        }

        $grouped = [];
        foreach ($recipeIds as $recipeId) {
            $grouped[$recipeId] = [];
        }

        $maxEntryRows = min(100_000, max(100, $this->maxNodes * 4));
        $rows = DB::connection('eqemu')
            ->table('tradeskill_recipe_entries as e')
            ->leftJoin('items as i', 'i.id', '=', 'e.item_id')
            ->whereIn('e.recipe_id', $recipeIds)
            ->select([
                'e.id',
                'e.recipe_id',
                'e.item_id',
                'e.successcount',
                'e.failcount',
                'e.componentcount',
                'e.iscontainer',
                'i.id as resolved_item_id',
                'i.Name as item_name',
                'i.icon as item_icon',
            ])
            ->orderBy('e.recipe_id')
            ->orderBy('e.id')
            ->limit($maxEntryRows + 1)
            ->get();

        if ($rows->count() > $maxEntryRows) {
            throw new LengthException('The recipe graph contains too many entry rows to plan safely.');
        }

        foreach ($rows as $row) {
            $grouped[(int) $row->recipe_id][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function findAlternatives(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds, static fn (int $id): bool => $id > 0)));
        if ($itemIds === []) {
            return [];
        }

        $currentExpansion = (int) config('everquest.current_expansion', 0);
        $baseQuery = DB::connection('eqemu')
            ->table('tradeskill_recipe_entries as output')
            ->join('tradeskill_recipe as recipe', 'recipe.id', '=', 'output.recipe_id')
            ->whereIn('output.item_id', $itemIds)
            ->where('output.successcount', '>', 0)
            ->where('output.componentcount', '<=', 0)
            ->where('output.iscontainer', 0)
            ->where('recipe.enabled', 1)
            ->where(function ($query) use ($currentExpansion) {
                $query->where('recipe.min_expansion', -1)
                    ->orWhere('recipe.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('recipe.max_expansion', -1)
                    ->orWhere('recipe.max_expansion', '>=', $currentExpansion);
            })
            ->select([
                'output.item_id',
                'recipe.id',
                'recipe.name',
                'recipe.tradeskill',
                'recipe.trivial',
                'recipe.nofail',
                'recipe.quest',
                'recipe.enabled',
                'recipe.replace_container',
            ])
            ->selectRaw('SUM(output.successcount) as successcount')
            ->groupBy([
                'output.item_id',
                'recipe.id',
                'recipe.name',
                'recipe.tradeskill',
                'recipe.trivial',
                'recipe.nofail',
                'recipe.quest',
                'recipe.enabled',
                'recipe.replace_container',
            ]);

        $rankedQuery = DB::connection('eqemu')
            ->query()
            ->fromSub($baseQuery, 'candidate')
            ->select('candidate.*')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY item_id '
                .'ORDER BY quest ASC, nofail DESC, successcount DESC, trivial ASC, id ASC) as planner_rank',
            );

        $rows = DB::connection('eqemu')
            ->query()
            ->fromSub($rankedQuery, 'ranked_candidate')
            ->where('planner_rank', '<=', $this->maxAlternativesPerItem + 1)
            ->orderBy('item_id')
            ->orderBy('planner_rank')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $itemId = (int) $row->item_id;
            $recipeId = (int) $row->id;

            if (! isset($grouped[$itemId][$recipeId])) {
                $grouped[$itemId][$recipeId] = [
                    'recipeId' => $recipeId,
                    'yield' => 0,
                    'recipe' => $this->recipeDataFromRow($row),
                ];
            }

            $grouped[$itemId][$recipeId]['yield'] += max(0, (int) $row->successcount);
        }

        foreach ($grouped as $itemId => $recipes) {
            $recipes = array_values($recipes);
            usort($recipes, function (array $left, array $right): int {
                $leftRecipe = $left['recipe'];
                $rightRecipe = $right['recipe'];

                return ((int) $leftRecipe['quest'] <=> (int) $rightRecipe['quest'])
                    ?: ((int) $rightRecipe['nofail'] <=> (int) $leftRecipe['nofail'])
                    ?: ((int) $right['yield'] <=> (int) $left['yield'])
                    ?: ((int) $leftRecipe['trivial'] <=> (int) $rightRecipe['trivial'])
                    ?: ((int) $leftRecipe['id'] <=> (int) $rightRecipe['id']);
            });
            $grouped[$itemId] = $recipes;
        }

        return $grouped;
    }

    /**
     * @param  array<int, object>  $entries
     * @param  array<int, array{id: int, name: string, icon: ?int}>  $itemMetadata
     * @param  array<int, true>  $worldTypes
     * @param  array<int, true>  $missingItemIds
     */
    private function captureEntryNodes(
        array $entries,
        array &$itemMetadata,
        array &$worldTypes,
        array &$missingItemIds,
    ): void {
        $objectContainers = (array) config('everquest.object_containers', []);

        foreach ($entries as $entry) {
            $itemId = (int) $entry->item_id;
            if ($entry->resolved_item_id !== null) {
                $itemMetadata[$itemId] ??= [
                    'id' => $itemId,
                    'name' => (string) ($entry->item_name ?: "Item #{$itemId}"),
                    'icon' => $entry->item_icon === null ? null : (int) $entry->item_icon,
                ];

                continue;
            }

            if ((bool) $entry->iscontainer && array_key_exists($itemId, $objectContainers)) {
                $worldTypes[$itemId] = true;

                continue;
            }

            $missingItemIds[$itemId] = true;
        }
    }

    /**
     * @param  array<int, object>  $entries
     * @return array{0: array<int, true>, 1: array<int, true>, 2: array<int, true>}
     */
    private function newEntryNodeIdentities(
        array $entries,
        array $itemMetadata,
        array $worldTypes,
        array $missingItemIds,
    ): array {
        $newItems = [];
        $newWorldTypes = [];
        $newMissingItems = [];
        $objectContainers = (array) config('everquest.object_containers', []);

        foreach ($entries as $entry) {
            $itemId = (int) $entry->item_id;
            if ($entry->resolved_item_id !== null) {
                if (! isset($itemMetadata[$itemId])) {
                    $newItems[$itemId] = true;
                }

                continue;
            }

            if ((bool) $entry->iscontainer && array_key_exists($itemId, $objectContainers)) {
                if (! isset($worldTypes[$itemId])) {
                    $newWorldTypes[$itemId] = true;
                }

                continue;
            }

            if (! isset($missingItemIds[$itemId])) {
                $newMissingItems[$itemId] = true;
            }
        }

        return [$newItems, $newWorldTypes, $newMissingItems];
    }

    /**
     * @param  array<int, object>  $entries
     * @param  array<int, int>  $frontier
     * @param  array<int, bool>  $discovered
     */
    private function addChildItemsToFrontier(
        array $entries,
        int $depth,
        array &$frontier,
        array $discovered,
        bool &$hiddenItemsEncountered,
        bool &$depthLimitReached,
    ): void {
        foreach ($entries as $entry) {
            $isComponent = ! (bool) $entry->iscontainer && (int) $entry->componentcount > 0;
            $isPortableContainer = (bool) $entry->iscontainer && $entry->resolved_item_id !== null;

            if (! $isComponent && ! $isPortableContainer) {
                continue;
            }
            if ($entry->resolved_item_id === null) {
                continue;
            }

            $itemId = (int) $entry->item_id;
            if (! ($discovered[$itemId] ?? false)) {
                $hiddenItemsEncountered = true;

                continue;
            }
            if ($depth > $this->maxDepth) {
                $depthLimitReached = true;

                continue;
            }

            $frontier[$itemId] = isset($frontier[$itemId])
                ? min($frontier[$itemId], $depth)
                : $depth;
        }
    }

    /**
     * @param  array<int>  $itemIds
     * @param  array<int, bool>  $discovered
     */
    private function resolveDiscovery(array $itemIds, array &$discovered): void
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $itemId): bool => $itemId > 0 && ! array_key_exists($itemId, $discovered),
        )));
        if ($itemIds === []) {
            return;
        }

        if (! config('everquest.discovered_items.enable', false)) {
            foreach ($itemIds as $itemId) {
                $discovered[$itemId] = true;
            }

            return;
        }

        $found = DiscoveredItem::query()
            ->whereIn('item_id', $itemIds)
            ->pluck('item_id')
            ->mapWithKeys(static fn ($itemId): array => [(int) $itemId => true])
            ->all();

        foreach ($itemIds as $itemId) {
            $discovered[$itemId] = isset($found[$itemId]);
        }
    }

    /**
     * @param  array<int>  $worldTypes
     * @return array<int, ?int>
     */
    private function loadWorldIcons(array $worldTypes): array
    {
        $worldTypes = array_values(array_unique(array_map('intval', $worldTypes)));
        if ($worldTypes === []) {
            return [];
        }

        $icons = [];
        $rows = DB::connection('eqemu')
            ->table('object')
            ->whereIn('type', $worldTypes)
            ->select('type', 'icon')
            ->orderBy('type')
            ->get();

        foreach ($rows as $row) {
            $type = (int) $row->type;
            if (! array_key_exists($type, $icons) || ! $icons[$type]) {
                $icons[$type] = $row->icon === null ? null : (int) $row->icon;
            }
        }

        return $icons;
    }

    /**
     * @return array{0: array<int, string>, 1: array<string, array<string, mixed>>}
     */
    private function normalizeItems(
        array $itemMetadata,
        array $worldTypes,
        array $worldIcons,
        array $missingItemIds,
        array $discovered,
        array $sources,
    ): array {
        ksort($itemMetadata, SORT_NUMERIC);
        ksort($worldTypes, SORT_NUMERIC);
        ksort($missingItemIds, SORT_NUMERIC);

        $itemKeys = [];
        $items = [];
        $undiscoveredOrdinal = 0;
        $missingOrdinal = 0;

        foreach ($itemMetadata as $itemId => $metadata) {
            $isDiscovered = $discovered[$itemId] ?? false;
            $key = $isDiscovered
                ? "item-{$itemId}"
                : 'undiscovered-'.(++$undiscoveredOrdinal);
            $itemKeys[$itemId] = $key;

            if (! $isDiscovered) {
                $items[$key] = [
                    'key' => $key,
                    'id' => null,
                    'name' => 'Undiscovered Item',
                    'icon' => null,
                    'url' => null,
                    'discovered' => false,
                    'sources' => [],
                    'sourceTypes' => [],
                ];

                continue;
            }

            $resolvedSources = $sources[$itemId] ?? ['sources' => [], 'sourceTypes' => []];
            $items[$key] = [
                'key' => $key,
                'id' => $itemId,
                'name' => $metadata['name'],
                'icon' => $metadata['icon'],
                'url' => route('items.show', $itemId, absolute: false),
                'discovered' => true,
                'sources' => $resolvedSources['sources'],
                'sourceTypes' => $resolvedSources['sourceTypes'],
            ];
        }

        $objectContainers = (array) config('everquest.object_containers', []);
        foreach (array_keys($worldTypes) as $worldType) {
            $key = "world-{$worldType}";
            $itemKeys[$this->worldIdentity($worldType)] = $key;
            $items[$key] = [
                'key' => $key,
                'id' => null,
                'name' => (string) ($objectContainers[$worldType] ?? 'World tradeskill station'),
                'icon' => $worldIcons[$worldType] ?? null,
                'url' => null,
                'discovered' => true,
                'sources' => [],
                'sourceTypes' => [],
            ];
        }

        foreach (array_keys($missingItemIds) as $missingItemId) {
            $key = 'missing-'.(++$missingOrdinal);
            $itemKeys[$this->missingIdentity($missingItemId)] = $key;
            $items[$key] = [
                'key' => $key,
                'id' => null,
                'name' => 'Unknown Item',
                'icon' => null,
                'url' => null,
                'discovered' => false,
                'sources' => [],
                'sourceTypes' => [],
            ];
        }

        return [$itemKeys, $items];
    }

    private function normalizeRecipes(
        array $recipeRows,
        array $entriesByRecipe,
        array $itemKeys,
        array $items,
    ): array {
        $normalized = [];
        foreach ($recipeRows as $recipeId => $recipe) {
            $entries = $entriesByRecipe[$recipeId] ?? [];
            $successByItem = [];
            foreach ($entries as $entry) {
                if ((int) $entry->successcount > 0) {
                    $itemId = (int) $entry->item_id;
                    $successByItem[$itemId] = ($successByItem[$itemId] ?? 0) + (int) $entry->successcount;
                }
            }

            $products = [];
            foreach ($successByItem as $itemId => $count) {
                $itemKey = $this->entryItemKeyForId($itemId, false, null, $itemKeys);
                if ($itemKey !== null) {
                    $products[] = ['itemKey' => $itemKey, 'count' => $count];
                }
            }

            $componentCounts = [];
            foreach ($entries as $entry) {
                if ((bool) $entry->iscontainer || (int) $entry->componentcount <= 0) {
                    continue;
                }

                $itemId = (int) $entry->item_id;
                $componentCounts[$itemId] = ($componentCounts[$itemId] ?? 0) + (int) $entry->componentcount;
            }

            $components = [];
            foreach ($componentCounts as $itemId => $count) {
                $itemKey = $this->entryItemKeyForId($itemId, false, null, $itemKeys);
                if ($itemKey !== null) {
                    $components[] = [
                        'itemKey' => $itemKey,
                        'count' => $count,
                        'returnedOnSuccess' => $successByItem[$itemId] ?? 0,
                    ];
                }
            }

            $containersByKey = [];
            foreach ($entries as $entry) {
                if (! (bool) $entry->iscontainer) {
                    continue;
                }

                $worldObject = $entry->resolved_item_id === null
                    && array_key_exists((int) $entry->item_id, (array) config('everquest.object_containers', []));
                $itemKey = $this->entryItemKeyForId(
                    (int) $entry->item_id,
                    true,
                    $worldObject,
                    $itemKeys,
                );
                if ($itemKey === null || ! isset($items[$itemKey])) {
                    continue;
                }

                $count = max(1, (int) $entry->componentcount);
                if (! isset($containersByKey[$itemKey])) {
                    $containersByKey[$itemKey] = [
                        'itemKey' => $itemKey,
                        'name' => $items[$itemKey]['name'],
                        'count' => 0,
                        'consumed' => ! $worldObject && (bool) $recipe['replaceContainer'],
                        'worldObject' => $worldObject,
                    ];
                }
                $containersByKey[$itemKey]['count'] += $count;
            }

            $normalized[$recipeId] = [
                'id' => (int) $recipe['id'],
                'name' => (string) $recipe['name'],
                'tradeskill' => (int) $recipe['tradeskill'],
                'tradeskillName' => $this->tradeskillName((int) $recipe['tradeskill']),
                'trivial' => (int) $recipe['trivial'],
                'nofail' => (bool) $recipe['nofail'],
                'quest' => (bool) $recipe['quest'],
                'enabled' => (bool) $recipe['enabled'],
                'replaceContainer' => (bool) $recipe['replaceContainer'],
                'url' => route('recipes.show', (int) $recipe['id'], absolute: false),
                'products' => array_values($products),
                'components' => array_values($components),
                'containers' => array_values($containersByKey),
            ];
        }

        return $normalized;
    }

    private function normalizeAlternatives(array $alternativesByItem, array $recipeRows, array $itemKeys): array
    {
        $normalized = [];
        foreach ($alternativesByItem as $itemId => $alternatives) {
            $itemKey = $itemKeys[(int) $itemId] ?? null;
            if ($itemKey === null) {
                continue;
            }

            $automaticAssigned = false;
            foreach ($alternatives as $alternative) {
                $recipe = $recipeRows[$alternative['recipeId']] ?? null;
                if ($recipe === null) {
                    continue;
                }

                $normalized[$itemKey][] = [
                    'recipeId' => (int) $alternative['recipeId'],
                    'yield' => max(1, (int) $alternative['yield']),
                    'name' => (string) $recipe['name'],
                    'tradeskillName' => $this->tradeskillName((int) $recipe['tradeskill']),
                    'trivial' => (int) $recipe['trivial'],
                    'nofail' => (bool) $recipe['nofail'],
                    'quest' => (bool) $recipe['quest'],
                    'automatic' => ! $automaticAssigned && ! (bool) $recipe['quest'],
                ];
                if (! $automaticAssigned && ! (bool) $recipe['quest']) {
                    $automaticAssigned = true;
                }
            }
        }

        return $normalized;
    }

    private function entryItemKeyForId(int $itemId, bool $container, ?bool $worldObject, array $itemKeys): ?string
    {
        if ($container && $worldObject) {
            return $itemKeys[$this->worldIdentity($itemId)] ?? null;
        }

        return $itemKeys[$itemId]
            ?? $itemKeys[$this->missingIdentity($itemId)]
            ?? null;
    }

    private function hasCycle(array $entriesByRecipe, array $alternativesByItem): bool
    {
        $adjacency = [];
        foreach ($entriesByRecipe as $recipeId => $entries) {
            foreach ($entries as $entry) {
                $isComponent = ! (bool) $entry->iscontainer && (int) $entry->componentcount > 0;
                $isPortableContainer = (bool) $entry->iscontainer && $entry->resolved_item_id !== null;
                if (! $isComponent && ! $isPortableContainer) {
                    continue;
                }

                foreach ($alternativesByItem[(int) $entry->item_id] ?? [] as $alternative) {
                    $adjacency[(int) $recipeId][(int) $alternative['recipeId']] = true;
                }
            }
        }

        $state = [];
        $visit = function (int $recipeId) use (&$visit, &$state, $adjacency): bool {
            if (($state[$recipeId] ?? 0) === 1) {
                return true;
            }
            if (($state[$recipeId] ?? 0) === 2) {
                return false;
            }

            $state[$recipeId] = 1;
            foreach (array_keys($adjacency[$recipeId] ?? []) as $childRecipeId) {
                if ($visit((int) $childRecipeId)) {
                    return true;
                }
            }
            $state[$recipeId] = 2;

            return false;
        };

        foreach (array_keys($entriesByRecipe) as $recipeId) {
            if ($visit((int) $recipeId)) {
                return true;
            }
        }

        return false;
    }

    private function nodeCount(
        array $recipeRows,
        array $itemMetadata,
        array $worldTypes,
        array $missingItemIds,
    ): int {
        return count($recipeRows) + count($itemMetadata) + count($worldTypes) + count($missingItemIds);
    }

    /** @return array<string, mixed> */
    private function recipeDataFromModel(TradeskillRecipe $recipe): array
    {
        return [
            'id' => (int) $recipe->id,
            'name' => (string) $recipe->name,
            'tradeskill' => (int) $recipe->tradeskill,
            'trivial' => (int) $recipe->trivial,
            'nofail' => (bool) $recipe->nofail,
            'quest' => (bool) $recipe->quest,
            'enabled' => (bool) $recipe->enabled,
            'replaceContainer' => (bool) $recipe->replace_container,
        ];
    }

    /** @return array<string, mixed> */
    private function recipeDataFromRow(object $recipe): array
    {
        return [
            'id' => (int) $recipe->id,
            'name' => (string) $recipe->name,
            'tradeskill' => (int) $recipe->tradeskill,
            'trivial' => (int) $recipe->trivial,
            'nofail' => (bool) $recipe->nofail,
            'quest' => (bool) $recipe->quest,
            'enabled' => (bool) $recipe->enabled,
            'replaceContainer' => (bool) $recipe->replace_container,
        ];
    }

    private function tradeskillName(int $tradeskill): string
    {
        return (string) (
            config("everquest.skills.tradeskill.{$tradeskill}")
            ?? config("everquest.db_skills.{$tradeskill}")
            ?? 'Non-Tradeskill'
        );
    }

    private function worldIdentity(int $worldType): string
    {
        return "world:{$worldType}";
    }

    private function missingIdentity(int $itemId): string
    {
        return "missing:{$itemId}";
    }

    private function boundedConfigInt(string $key, int $default, int $minimum, int $maximum): int
    {
        return min(
            $maximum,
            max($minimum, (int) config("everquest.tradeskill_planner.{$key}", $default)),
        );
    }
}
