<?php

namespace Tests\Feature;

use App\Http\Controllers\ItemController;
use App\Models\Item;
use App\Services\GroundSpawnLocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ItemKinboundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('everquest.discovered_items.enable', false);
        config()->set('everquest.item_history.enable', false);
        config()->set('cache.default', 'array');
        config()->set('database.connections.eqemu', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('eqemu');
        Schema::connection('eqemu')->create('item_kinbound_policy', function (Blueprint $table): void {
            $table->integer('item_id')->primary();
            $table->integer('epic')->default(-1);
            $table->integer('expansion')->default(-1);
            $table->integer('override_mode')->default(0);
        });
    }

    #[DataProvider('itemFlags')]
    public function test_item_pages_and_popups_display_binding_tags(array $flags, bool $kinbound, bool $noTrade): void
    {
        $item = $this->item($flags);

        foreach (['items.show', 'items.partials.popup'] as $view) {
            $html = view($view, $this->viewData($item))->render();
            $tags = 'Magic, Lore, '.($kinbound ? 'Kinbound, ' : ($noTrade ? 'No Trade, ' : ''))
                .'No Rent, Quest'.($kinbound ? '' : ', Attuneable');

            $this->assertStringContainsString($tags, $html, $view);
            if ($kinbound) {
                $this->assertStringNotContainsString('No Trade', $html, $view);
                $this->assertStringNotContainsString('Attuneable', $html, $view);
                $this->assertStringContainsString('Shareable within the same forum account until attuned.', $html, $view);
                $this->assertStringContainsString('Attuning permanently binds it to a character.', $html, $view);
                $this->assertStringContainsString('Click effects require attunement.', $html, $view);
            } else {
                $this->assertStringNotContainsString('Kinbound', $html, $view);
                $this->assertStringNotContainsString('Shareable within the same forum account', $html, $view);
            }
        }
    }

    public static function itemFlags(): array
    {
        return [
            'explicit Always flag' => [['kinbound' => 1], true, false],
            'numeric strings from database' => [['kinbound' => '1', 'nodrop' => '0', 'notransfer' => '0'], true, false],
            'unlisted flag' => [['kinbound' => 0], false, true],
            'explicit Never flag' => [['kinbound' => 2], false, true],
            'invalid numeric flag' => [['kinbound' => 3], false, true],
            'null flag' => [['kinbound' => null], false, true],
            'missing column' => [[], false, true],
            'tradeable item' => [['kinbound' => 1, 'nodrop' => 1], false, false],
            'no-transfer veto' => [['kinbound' => 1, 'notransfer' => 1], false, true],
        ];
    }

    public function test_known_epics_are_excluded_even_with_the_explicit_flag(): void
    {
        $item = $this->item(['kinbound' => 1]);
        DB::connection('eqemu')->table('item_kinbound_policy')->insert(['item_id' => $item->id, 'epic' => 1]);

        $html = view('items.partials.popup', ['item' => $item])->render();

        $this->assertStringNotContainsString('Kinbound', $html);
        $this->assertStringContainsString('Magic, Lore, No Trade, No Rent, Quest, Attuneable', $html);
    }

    public function test_legacy_catalog_metadata_does_not_enable_kinbound(): void
    {
        $item = $this->item(['kinbound' => 0]);
        DB::connection('eqemu')->table('item_kinbound_policy')->insert([
            'item_id' => $item->id,
            'epic' => 0,
            'expansion' => 1,
            'override_mode' => 1,
        ]);

        $this->assertStringNotContainsString('Kinbound', view('items.partials.popup', ['item' => $item])->render());
    }

    public function test_missing_policy_catalog_does_not_advertise_kinbound(): void
    {
        Schema::connection('eqemu')->drop('item_kinbound_policy');

        $html = view('items.partials.popup', ['item' => $this->item(['kinbound' => 1])])->render();

        $this->assertStringNotContainsString('Kinbound', $html);
        $this->assertStringContainsString('No Trade', $html);
    }

    #[DataProvider('cachedFlagChanges')]
    public function test_cached_item_page_uses_current_binding_flags(array $cachedFlags, array $currentFlags, string $expectedTags): void
    {
        $cachedItem = $this->item($cachedFlags);
        Cache::put('items.show.'.$cachedItem->id, $this->viewData($cachedItem), now()->addMonth());
        $groundSpawns = $this->mock(GroundSpawnLocationService::class);
        $groundSpawns->shouldReceive('forItem')->once()->with($cachedItem->id)->andReturn([]);

        $view = (new ItemController)->show($this->item($currentFlags), $groundSpawns);

        $this->assertStringContainsString($expectedTags, $view->render());
    }

    public static function cachedFlagChanges(): array
    {
        return [
            'legacy cache gains Kinbound' => [[], ['kinbound' => 1], 'Magic, Lore, Kinbound, No Rent, Quest'],
            'Kinbound is removed' => [['kinbound' => 1], ['kinbound' => 0], 'Magic, Lore, No Trade, No Rent, Quest, Attuneable'],
            'item becomes tradeable' => [['kinbound' => 1], ['kinbound' => 1, 'nodrop' => 1], 'Magic, Lore, No Rent, Quest, Attuneable'],
            'no-transfer veto is added' => [['kinbound' => 1], ['kinbound' => 1, 'notransfer' => 1], 'Magic, Lore, No Trade, No Rent, Quest, Attuneable'],
            'attuneable flag is refreshed' => [['kinbound' => 1, 'attuneable' => 0], ['kinbound' => 0, 'attuneable' => 1], 'Magic, Lore, No Trade, No Rent, Quest, Attuneable'],
        ];
    }

    private function item(array $flags): Item
    {
        return (new Item)->forceFill(array_replace([
            'id' => 12345,
            'Name' => 'Binding Test Item',
            'icon' => 1,
            'itemtype' => 0,
            'magic' => 1,
            'loregroup' => -1,
            'nodrop' => 0,
            'notransfer' => 0,
            'norent' => 0,
            'questitemflag' => 1,
            'attuneable' => 1,
            'price' => 0,
            'weight' => 0,
            'banedmgbody' => 0,
        ], $flags))->setRelation('discovery', null);
    }

    private function viewData(Item $item): array
    {
        return [
            'item' => $item,
            'recipes' => collect(),
            'used_in_ts' => collect(),
            'forage' => collect(),
            'fishing' => collect(),
            'soldByZone' => collect(),
            'ground_spawn' => collect(),
        ];
    }
}
