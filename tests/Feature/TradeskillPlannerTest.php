<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\Support\CreatesTradeskillPlannerSchema;
use Tests\TestCase;

class TradeskillPlannerTest extends TestCase
{
    use CreatesTradeskillPlannerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTradeskillPlannerSchema();
        config()->set('everquest.discovered_items.enable', false);
        config()->set('everquest.current_expansion', 1);
        config()->set('everquest.ignore_zones', []);
        config()->set('everquest.merchants_dont_drop_stuff', true);
        Cache::clear();
    }

    public function test_disabled_planner_routes_return_not_found(): void
    {
        config()->set('everquest.tradeskill_planner.enable', false);

        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerRecipe(1, 'Finished Recipe');
        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);

        $this->get('/recipes/plans')->assertNotFound();
        $this->get('/recipes/1/plan')->assertNotFound();

        $this->get('/recipes/1')
            ->assertOk()
            ->assertDontSee('Plan this recipe')
            ->assertDontSee('Saved plans');
    }

    public function test_saved_plans_page_is_available_without_an_account(): void
    {
        config()->set('everquest.tradeskill_planner.enable', true);

        $this->get('/recipes/plans')
            ->assertOk()
            ->assertSee('Saved tradeskill plans')
            ->assertSee('stored only in this browser');
    }

    public function test_recipe_plan_page_embeds_a_recursive_graph(): void
    {
        config()->set('everquest.tradeskill_planner.enable', true);

        $this->addPlannerItem(100, 'Finished Item');
        $this->addPlannerItem(200, 'Raw Material');
        $this->addPlannerRecipe(1, 'Finished Recipe');
        $this->addPlannerEntry(1, 1, 100, ['successcount' => 1]);
        $this->addPlannerEntry(2, 1, 200, ['componentcount' => 2]);

        $this->get('/recipes/1/plan')
            ->assertOk()
            ->assertSee('Recursive tradeskill planner')
            ->assertSee('Finished Recipe')
            ->assertSee('Raw Material');
    }
}
