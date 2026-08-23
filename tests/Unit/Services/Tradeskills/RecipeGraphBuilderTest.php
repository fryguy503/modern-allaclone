<?php

namespace Tests\Unit\Services\Tradeskills;

use App\Models\TradeskillRecipe;
use App\Services\Tradeskills\MaterialSourceResolver;
use App\Services\Tradeskills\RecipeGraphBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LengthException;
use Tests\Support\CreatesTradeskillPlannerSchema;
use Tests\TestCase;

class RecipeGraphBuilderTest extends TestCase
{
    use CreatesTradeskillPlannerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTradeskillPlannerSchema();
        config()->set('everquest.current_expansion', 1);
        config()->set('everquest.ignore_zones', []);
        config()->set('everquest.merchants_dont_drop_stuff', true);
    }

    public function test_undiscovered_items_are_redacted_and_their_subrecipes_are_not_loaded(): void
    {
        config()->set('everquest.discovered_items.enable', true);

        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerItem(200, 'Visible Ingredient');
        $this->addPlannerItem(300, 'Secret Ingredient');
        $this->addPlannerItem(400, 'Visible Raw Material');
        $this->addPlannerItem(500, 'Secret Raw Material');

        $this->addPlannerRecipe(1, 'Finished Recipe');
        $this->addPlannerRecipe(2, 'Visible Subcombine');
        $this->addPlannerRecipe(3, 'Secret Subcombine');

        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);
        $this->addPlannerEntry(2, 1, 200, ['componentcount' => 1]);
        $this->addPlannerEntry(3, 1, 300, ['componentcount' => 1]);
        $this->addPlannerEntry(4, 2, 200, ['successcount' => 1]);
        $this->addPlannerEntry(5, 2, 400, ['componentcount' => 1]);
        $this->addPlannerEntry(6, 3, 300, ['successcount' => 1]);
        $this->addPlannerEntry(7, 3, 500, ['componentcount' => 1]);

        DB::connection('eqemu')->table('discovered_items')->insert([
            ['item_id' => 100, 'discovered_date' => 1],
            ['item_id' => 200, 'discovered_date' => 2],
            ['item_id' => 400, 'discovered_date' => 3],
        ]);

        $root = TradeskillRecipe::query()->findOrFail(1);
        $graph = $this->builder()->build($root);
        $serialized = json_encode($graph, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('item-100', $graph['items']);
        $this->assertArrayHasKey('item-200', $graph['items']);
        $this->assertArrayHasKey('item-400', $graph['items']);
        $this->assertArrayHasKey('undiscovered-1', $graph['items']);
        $this->assertSame([
            'key' => 'undiscovered-1',
            'id' => null,
            'name' => 'Undiscovered Item',
            'icon' => null,
            'url' => null,
            'discovered' => false,
            'sources' => [],
            'sourceTypes' => [],
        ], $graph['items']['undiscovered-1']);

        $this->assertArrayHasKey(2, $graph['recipes']);
        $this->assertArrayNotHasKey(3, $graph['recipes']);
        $this->assertStringNotContainsString('Secret Ingredient', $serialized);
        $this->assertStringNotContainsString('Secret Subcombine', $serialized);
        $this->assertStringNotContainsString('/items/300', $serialized);
        $this->assertStringContainsString('Undiscovered items were redacted', implode(' ', $graph['warnings']));
    }

    public function test_wide_graph_uses_batched_queries_instead_of_queries_per_component(): void
    {
        config()->set('everquest.discovered_items.enable', false);

        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerRecipe(1, 'Wide Recipe');
        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);

        $items = [];
        $entries = [];
        foreach (range(200, 299) as $offset => $itemId) {
            $items[] = ['id' => $itemId, 'Name' => "Material {$itemId}", 'icon' => 0];
            $entries[] = [
                'id' => $offset + 2,
                'recipe_id' => 1,
                'item_id' => $itemId,
                'successcount' => 0,
                'failcount' => 0,
                'componentcount' => 1,
                'iscontainer' => 0,
            ];
        }
        DB::connection('eqemu')->table('items')->insert($items);
        DB::connection('eqemu')->table('tradeskill_recipe_entries')->insert($entries);

        $root = TradeskillRecipe::query()->findOrFail(1);
        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if ($query->connectionName === 'eqemu') {
                $queryCount++;
            }
        });

        $graph = $this->builder()->build($root);

        $this->assertCount(101, $graph['items']);
        $this->assertSame([], $graph['alternatives']);
        $this->assertLessThanOrEqual(
            8,
            $queryCount,
            'A hundred sibling components should be loaded and source-resolved in batches.',
        );
    }

    public function test_returned_components_are_not_offered_as_subcombine_producers(): void
    {
        config()->set('everquest.discovered_items.enable', false);

        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerItem(200, 'Reusable Mold');
        $this->addPlannerItem(300, 'Raw Mold Material');
        $this->addPlannerItem(400, 'Unrelated Output');

        $this->addPlannerRecipe(1, 'Finished Recipe');
        $this->addPlannerRecipe(2, 'Make Reusable Mold');
        $this->addPlannerRecipe(3, 'Recipe That Returns Its Mold');

        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);
        $this->addPlannerEntry(2, 1, 200, ['componentcount' => 1, 'successcount' => 1]);
        $this->addPlannerEntry(3, 2, 200, ['successcount' => 1]);
        $this->addPlannerEntry(4, 2, 300, ['componentcount' => 1]);
        $this->addPlannerEntry(5, 3, 400, ['successcount' => 1]);
        $this->addPlannerEntry(6, 3, 200, ['componentcount' => 1, 'successcount' => 1]);

        $root = TradeskillRecipe::query()->findOrFail(1);
        $graph = $this->builder()->build($root);

        $this->assertSame(
            [2],
            array_column($graph['alternatives']['item-200'], 'recipeId'),
            'Returning a required tool does not make the recipe a producer of that tool.',
        );
        $this->assertArrayNotHasKey(3, $graph['recipes']);
    }

    public function test_cached_graph_is_invalidated_when_relevant_discovery_state_changes(): void
    {
        config()->set('everquest.discovered_items.enable', true);

        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerItem(200, 'Hidden Ingredient');
        $this->addPlannerRecipe(1, 'Discovery-sensitive Recipe');
        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);
        $this->addPlannerEntry(2, 1, 200, ['componentcount' => 1]);
        DB::connection('eqemu')->table('discovered_items')->insert([
            'item_id' => 100,
            'discovered_date' => 1_000,
        ]);

        $root = TradeskillRecipe::query()->findOrFail(1);
        $builder = $this->builder();
        $envelope = $builder->buildCacheEnvelope($root);

        $this->assertTrue($builder->cacheEnvelopeIsCurrent($envelope));
        $this->assertArrayNotHasKey('discoverySnapshot', $envelope['graph']);

        DB::connection('eqemu')->table('discovered_items')->insert([
            'item_id' => 200,
            'discovered_date' => 2_000,
        ]);

        $this->assertFalse($builder->cacheEnvelopeIsCurrent($envelope));
    }

    public function test_cache_key_changes_when_a_browser_planning_limit_changes(): void
    {
        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerRecipe(1, 'Limit-sensitive Recipe');
        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);

        $root = TradeskillRecipe::query()->findOrFail(1);
        $builder = $this->builder();
        config()->set('everquest.tradeskill_planner.max_quantity', 10);
        $firstKey = $builder->cacheKey($root);

        config()->set('everquest.tradeskill_planner.max_quantity', 11);

        $this->assertNotSame($firstKey, $builder->cacheKey($root));
    }

    public function test_oversized_root_recipe_is_rejected_at_the_node_limit(): void
    {
        config()->set('everquest.discovered_items.enable', false);
        config()->set('everquest.tradeskill_planner.max_nodes', 25);

        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerRecipe(1, 'Oversized Root Recipe');
        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);

        foreach (range(200, 225) as $offset => $itemId) {
            $this->addPlannerItem($itemId, "Material {$itemId}");
            $this->addPlannerEntry($offset + 2, 1, $itemId, ['componentcount' => 1]);
        }

        $root = TradeskillRecipe::query()->findOrFail(1);

        $this->expectException(LengthException::class);
        $this->builder()->build($root);
    }

    private function builder(): RecipeGraphBuilder
    {
        return new RecipeGraphBuilder(new MaterialSourceResolver);
    }
}
