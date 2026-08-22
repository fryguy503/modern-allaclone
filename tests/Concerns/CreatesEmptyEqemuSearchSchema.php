<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesEmptyEqemuSearchSchema
{
    protected function useEmptyEqemuSearchDatabase(): void
    {
        config()->set('everquest.discovered_items.enable', false);
        config()->set('database.connections.eqemu', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('eqemu');

        $tables = [
            'npc_types' => ['name'],
            'items' => ['Name'],
            'tradeskill_recipe' => ['name'],
            'faction_list' => ['name'],
            'spells_new' => ['name'],
        ];

        foreach ($tables as $table => $columns) {
            Schema::connection('eqemu')->create($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->integer('id')->primary();
                foreach ($columns as $column) {
                    $blueprint->string($column);
                }
            });
        }

        Schema::connection('eqemu')->create('zone', function (Blueprint $blueprint): void {
            $blueprint->integer('id')->primary();
            $blueprint->string('long_name');
            $blueprint->string('short_name');
            $blueprint->integer('zoneidnumber');
        });
    }
}
