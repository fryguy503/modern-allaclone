<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesTradeskillPlannerSchema
{
    protected function createTradeskillPlannerSchema(): void
    {
        config()->set('database.connections.eqemu', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('eqemu');

        $schema = Schema::connection('eqemu');

        $schema->create('tradeskill_recipe', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->integer('tradeskill')->default(0);
            $table->integer('trivial')->default(0);
            $table->integer('nofail')->default(0);
            $table->integer('quest')->default(0);
            $table->integer('enabled')->default(1);
            $table->integer('replace_container')->default(0);
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
        });

        $schema->create('tradeskill_recipe_entries', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('recipe_id')->index();
            $table->integer('item_id')->index();
            $table->integer('successcount')->default(0);
            $table->integer('failcount')->default(0);
            $table->integer('componentcount')->default(0);
            $table->integer('iscontainer')->default(0);
        });

        $schema->create('items', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('Name');
            $table->integer('icon')->default(0);
        });

        $schema->create('discovered_items', function (Blueprint $table): void {
            $table->integer('item_id')->primary();
            $table->integer('discovered_date')->default(0);
        });

        $schema->create('object', function (Blueprint $table): void {
            $table->integer('type')->index();
            $table->integer('icon')->default(0);
        });

        $schema->create('merchantlist', function (Blueprint $table): void {
            $table->integer('item')->index();
            $table->integer('merchantid')->index();
        });

        $schema->create('npc_types', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->integer('merchant_id')->default(0)->index();
            $table->integer('loottable_id')->default(0)->index();
        });

        $schema->create('spawnentry', function (Blueprint $table): void {
            $table->integer('npcID')->index();
            $table->integer('spawngroupID')->index();
            $table->integer('chance')->default(100);
        });

        $schema->create('spawn2', function (Blueprint $table): void {
            $table->integer('spawngroupID')->index();
            $table->string('zone')->index();
            $table->integer('version')->default(0);
        });

        $schema->create('zone', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('zoneidnumber')->index();
            $table->string('short_name')->index();
            $table->string('long_name');
            $table->integer('version')->default(0);
            $table->integer('expansion')->default(0);
        });

        $schema->create('lootdrop_entries', function (Blueprint $table): void {
            $table->integer('item_id')->index();
            $table->integer('lootdrop_id')->index();
            $table->float('chance')->default(100);
        });

        $schema->create('loottable_entries', function (Blueprint $table): void {
            $table->integer('lootdrop_id')->index();
            $table->integer('loottable_id')->index();
            $table->float('probability')->default(100);
        });

        $schema->create('forage', function (Blueprint $table): void {
            $table->integer('itemid')->index();
            $table->integer('zoneid')->index();
            $table->integer('chance')->default(100);
            $table->integer('level')->default(0);
        });

        $schema->create('fishing', function (Blueprint $table): void {
            $table->integer('itemid')->index();
            $table->integer('zoneid')->index();
            $table->integer('chance')->default(100);
            $table->integer('skill_level')->default(0);
        });

        $schema->create('ground_spawns', function (Blueprint $table): void {
            $table->integer('item')->index();
            $table->integer('zoneid')->index();
            $table->float('max_x')->default(0);
            $table->float('max_y')->default(0);
            $table->float('max_z')->default(0);
        });
    }

    protected function addPlannerItem(int $id, string $name, int $icon = 0): void
    {
        DB::connection('eqemu')->table('items')->insert([
            'id' => $id,
            'Name' => $name,
            'icon' => $icon,
        ]);
    }

    /**
     * @param  array<string, int|string>  $overrides
     */
    protected function addPlannerRecipe(int $id, string $name, array $overrides = []): void
    {
        DB::connection('eqemu')->table('tradeskill_recipe')->insert(array_merge([
            'id' => $id,
            'name' => $name,
            'tradeskill' => 60,
            'trivial' => 100,
            'nofail' => 1,
            'quest' => 0,
            'enabled' => 1,
            'replace_container' => 0,
            'min_expansion' => -1,
            'max_expansion' => -1,
        ], $overrides));
    }

    /**
     * @param  array<string, int>  $overrides
     */
    protected function addPlannerEntry(
        int $id,
        int $recipeId,
        int $itemId,
        array $overrides = [],
    ): void {
        DB::connection('eqemu')->table('tradeskill_recipe_entries')->insert(array_merge([
            'id' => $id,
            'recipe_id' => $recipeId,
            'item_id' => $itemId,
            'successcount' => 0,
            'failcount' => 0,
            'componentcount' => 0,
            'iscontainer' => 0,
        ], $overrides));
    }
}
