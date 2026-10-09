<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Merchantlist;
use App\Models\NpcType;
use App\Services\MerchantPriceService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MerchantPriceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('everquest.discovered_items.enable', false);
        config()->set('database.connections.eqemu', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('eqemu');

        $schema = Schema::connection('eqemu');
        $schema->create('rule_sets', function (Blueprint $table): void {
            $table->integer('ruleset_id')->primary();
            $table->string('name');
        });
        $schema->create('rule_values', function (Blueprint $table): void {
            $table->integer('ruleset_id');
            $table->string('rule_name');
            $table->string('rule_value');
        });
        $schema->create('variables', function (Blueprint $table): void {
            $table->string('varname');
            $table->string('value');
        });
        $schema->create('zone', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('short_name');
            $table->integer('version')->default(0);
            $table->integer('ruleset')->default(0);
        });
        $schema->create('items', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('Name');
            $table->double('sellrate')->default(1);
            foreach (['icon', 'itemtype', 'slots', 'bagslots', 'bagwr', 'augtype', 'price',
                'pointtype', 'ldontheme', 'ldonsold', 'ldonprice'] as $column) {
                $table->integer($column)->default(0);
            }
        });
        $schema->create('merchantlist', function (Blueprint $table): void {
            $table->integer('merchantid');
            $table->integer('item');
            $table->integer('slot')->default(1);
        });
    }

    public function test_classic_prices_match_the_reported_screenshot(): void
    {
        $this->ruleset(1, 'default', [
            'UseClassicPriceMod' => 'true',
            // Classic pricing deliberately ignores this configured markup.
            'SellCostMod' => '1.2',
        ]);

        // September 26 export values and the independently observed game prices.
        $examples = [
            'Large Brick of High Quality Ore' => [6200, 2.8053, 18261],
            'Small Piece of Ore' => [23, 7.1015, 171],
            'Small Brick of Ore' => [79, 6.3292, 525],
            'Large Brick of Ore' => [148, 10.1352, 1574],
            'Large Brick of Medium Quality Ore' => [155, 41.9356, 6824],
            'Small Brick of Medium Quality Ore' => [46, 46.7288, 2256],
            'Smithy Hammer' => [10000, 1, 10500],
        ];

        $prices = app(MerchantPriceService::class);
        foreach ($examples as $name => [$price, $sellrate, $expected]) {
            $this->assertSame($expected, $prices->estimate($this->item($price, $sellrate)), $name);
        }
        $this->assertSame('18 pp 2 gp 6 sp 1 cp', price(18261));
    }

    public function test_modern_pricing_uses_sellrate_markup_and_configured_best_discount(): void
    {
        $this->ruleset(7, 'default', [
            'SellCostMod' => '1.25',
            'PriceBonusPct' => '10',
        ]);

        $this->assertSame(22500, app(MerchantPriceService::class)->estimate($this->item(10000, 2)));
    }

    public function test_disabling_character_price_modifiers_preserves_the_other_stages(): void
    {
        $this->ruleset(7, 'default', ['UsePriceMod' => 'false', 'SellCostMod' => '1.25']);
        $this->ruleset(8, 'classic', ['UseClassicPriceMod' => 'true']);
        $this->zone(1, 'poknowledge', 0, 7);
        $this->zone(2, 'poknowledge', 20, 8);
        $prices = app(MerchantPriceService::class);

        $this->assertSame(25000, $prices->estimate($this->item(10000, 2), 'poknowledge'));
        $this->assertSame(20000, $prices->estimate($this->item(10000, 2), 'poknowledge', 20));
    }

    public function test_selected_rulesets_inherit_the_named_default_even_when_its_id_is_not_one(): void
    {
        $this->ruleset(7, 'default', ['UsePriceMod' => 'false', 'SellCostMod' => '1.5']);
        $this->ruleset(9, 'raid', ['SellCostMod' => '1.25']);
        $this->zone(1, 'mistmoore', 20, 9);

        $this->assertSame(12500, app(MerchantPriceService::class)
            ->estimate($this->item(10000, 1), 'mistmoore', 20));
    }

    public function test_rule_zero_and_exact_zone_versions_take_precedence_over_the_startup_ruleset(): void
    {
        $this->ruleset(0, 'default', ['UseClassicPriceMod' => 'true']);
        $this->ruleset(2, 'server', ['UsePriceMod' => 'false', 'UseClassicPriceMod' => 'false', 'SellCostMod' => '2']);
        DB::connection('eqemu')->table('variables')->insert(['varname' => 'RuleSet', 'value' => 'server']);
        $this->zone(1, 'poknowledge', 0, 0);
        $this->zone(2, 'poknowledge', 20, 2);
        $this->zone(3, 'missingrules', 0, 99);
        $prices = app(MerchantPriceService::class);
        $item = $this->item(10000, 1);

        $this->assertSame(10500, $prices->estimate($item, 'poknowledge'));
        $this->assertSame(20000, $prices->estimate($item, 'poknowledge', 20));
        $this->assertSame(10500, $prices->estimate($item, 'poknowledge', 21));
        $this->assertSame(20000, $prices->estimate($item, 'missingrules'));
        $this->assertSame(20000, $prices->estimate($item));
    }

    public function test_missing_startup_ruleset_falls_back_to_the_named_default_and_compiled_defaults(): void
    {
        DB::connection('eqemu')->table('variables')->insert(['varname' => 'RuleSet', 'value' => 'missing']);
        $this->ruleset(7, 'default', ['UsePriceMod' => 'false']);

        $this->assertSame(10500, app(MerchantPriceService::class)->estimate($this->item(10000, 1)));

        DB::connection('eqemu')->table('rule_values')->delete();
        DB::connection('eqemu')->table('rule_sets')->delete();
        $this->app->forgetScopedInstances();
        $this->assertSame(10080, app(MerchantPriceService::class)->estimate($this->item(10000, 1)));
    }

    public function test_zero_prices_sellrates_and_the_client_currency_limit_are_preserved(): void
    {
        $this->ruleset(1, 'default', ['UseClassicPriceMod' => 'true']);
        $prices = app(MerchantPriceService::class);

        $this->assertSame(0, $prices->estimate($this->item(0, 2.8053)));
        $this->assertSame(0, $prices->estimate($this->item(6200, 0)));
        $this->assertSame(1, $prices->estimate($this->item(10, 0.1)));
        $this->assertSame(2147483647, $prices->estimate($this->item(2147483647, 2)));
    }

    public function test_rule_queries_are_shared_for_the_request_and_refreshed_for_the_next_scope(): void
    {
        $this->ruleset(1, 'default', ['UseClassicPriceMod' => 'true']);
        $this->zone(1, 'poknowledge', 0, 1);
        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if ($query->connectionName === 'eqemu') {
                $queryCount++;
            }
        });

        $prices = app(MerchantPriceService::class);
        $item = $this->item(10000, 1);
        $this->assertSame(10500, $prices->estimate($item, 'poknowledge'));
        $firstItemQueries = $queryCount;
        foreach (range(1, 50) as $unused) {
            $prices->estimate($item, 'poknowledge');
        }
        $this->assertSame($firstItemQueries, $queryCount, 'Merchant rows should share their rule lookups.');

        DB::connection('eqemu')->table('rule_values')->insert([
            'ruleset_id' => 1, 'rule_name' => 'Merchant:UsePriceMod', 'rule_value' => 'false',
        ]);
        $this->app->forgetScopedInstances();
        $this->assertSame(10000, app(MerchantPriceService::class)->estimate($item, 'poknowledge'));
    }

    public function test_merchant_item_relation_loads_the_purchase_multiplier(): void
    {
        DB::connection('eqemu')->table('items')->insert([
            'id' => 10469, 'Name' => 'Large Brick of High Quality Ore', 'price' => 6200, 'sellrate' => 2.8053,
        ]);
        DB::connection('eqemu')->table('merchantlist')->insert(['merchantid' => 1, 'item' => 10469]);

        $item = Merchantlist::with('items')->firstOrFail()->items;

        $this->assertSame(6200, $item->price);
        $this->assertEqualsWithDelta(2.8053, $item->sellrate, 0.000001);
    }

    public function test_both_merchant_views_render_the_same_estimate_using_their_zone_context(): void
    {
        $this->ruleset(0, 'default', ['UseClassicPriceMod' => 'true']);
        $this->ruleset(2, 'server', ['UseClassicPriceMod' => 'false', 'UsePriceMod' => 'false', 'SellCostMod' => '2']);
        DB::connection('eqemu')->table('variables')->insert(['varname' => 'RuleSet', 'value' => 'server']);
        $this->zone(1, 'poknowledge', 0, 0);
        $this->zone(2, 'poknowledge', 20, 2);
        $item = $this->item(6200, 2.8053);

        foreach ([$this->npcMerchantHtml($item), $this->itemMerchantHtml($item)] as $html) {
            $this->assertStringContainsString('18 pp 2 gp 6 sp 1 cp', $html);
            $this->assertStringNotContainsString('>6 pp 2 gp<', $html);
        }
        foreach ([$this->npcMerchantHtml($item, version: 20), $this->itemMerchantHtml($item, version: 20)] as $html) {
            $this->assertStringContainsString('34 pp 7 gp 8 sp 4 cp', $html);
        }
        // Generic monetary formatting continues to describe the supplied value.
        $this->assertSame('6 pp 2 gp', price($item->price));
    }

    public function test_alternative_currency_costs_are_not_marked_up(): void
    {
        $this->ruleset(1, 'default', ['UseClassicPriceMod' => 'true']);
        $item = $this->item(6200, 2.8053);

        $html = $this->npcMerchantHtml($item, 333, 1);
        $this->assertStringContainsString('333', $html);
        $this->assertStringNotContainsString('18 pp 2 gp 6 sp 1 cp', $html);

        $html = $this->itemMerchantHtml($item, altCurrency: 1);
        $this->assertStringContainsString('6 pp 2 gp', $html);
        $this->assertStringNotContainsString('Estimated cost', $html);

        $item->setAttribute('ldopnprice', 123);
        $this->assertStringContainsString('123 points', $this->itemMerchantHtml($item, 61));

        $item->forceFill(['pointtype' => 1, 'ldonsold' => 1, 'ldonprice' => 456, 'ldontheme' => 1]);
        $html = $this->npcMerchantHtml($item, 0, 0, 61);
        $this->assertStringContainsString('456 /', $html);
        $this->assertStringNotContainsString('18 pp 2 gp 6 sp 1 cp', $html);
    }

    private function ruleset(int $id, string $name, array $rules): void
    {
        DB::connection('eqemu')->table('rule_sets')->insert(['ruleset_id' => $id, 'name' => $name]);
        foreach ($rules as $rule => $value) {
            DB::connection('eqemu')->table('rule_values')->insert([
                'ruleset_id' => $id, 'rule_name' => 'Merchant:'.$rule, 'rule_value' => $value,
            ]);
        }
    }

    private function zone(int $id, string $name, int $version, int $ruleset): void
    {
        DB::connection('eqemu')->table('zone')->insert([
            'id' => $id, 'short_name' => $name, 'version' => $version, 'ruleset' => $ruleset,
        ]);
    }

    private function item(int $price, float $sellrate): Item
    {
        return (new Item)->forceFill([
            'id' => 10469,
            'Name' => 'Large Brick of High Quality Ore',
            'price' => $price,
            'sellrate' => $sellrate,
            'icon' => 1,
            'itemtype' => 17,
            'slots' => 0,
            'bagslots' => 0,
            'pointtype' => 0,
            'ldonsold' => 0,
        ]);
    }

    private function npcMerchantHtml(Item $item, int $altCost = 0, int $altCurrency = 0, int $class = 41, int $version = 0): string
    {
        $entry = (new Merchantlist)->forceFill(['alt_currency_cost' => $altCost])->setRelation('items', $item);
        $npc = (new NpcType)->forceFill(['class' => $class, 'alt_currency_id' => $altCurrency])
            ->setRelation('merchantlist', collect([$entry]));

        return view('npcs.partials.show.tab-merchant', [
            'npc' => $npc,
            'defaultTab' => 'merchant',
            'primaryLocationZone' => ['short_name' => 'poknowledge', 'version' => $version],
            'altCurrency' => collect(),
            'discoveredItems' => collect(),
        ])->render();
    }

    private function itemMerchantHtml(Item $item, int $class = 41, int $version = 0, int $altCurrency = 0): string
    {
        return view('items.partials.show.sold', [
            'item' => $item,
            'sold' => collect([[
                'zone' => 'poknowledge',
                'zone_name' => 'The Plane of Knowledge',
                'npcs' => collect([[
                    'id' => 1, 'clean_name' => 'Tallis Paerk', 'class' => $class, 'zone_version' => $version,
                    'alt_currency_id' => $altCurrency,
                ]]),
            ]]),
        ])->render();
    }
}
