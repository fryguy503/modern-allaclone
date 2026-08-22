<?php

use App\Http\Controllers\AaAbilityController;
use App\Http\Controllers\DiscoveredItemController;
use App\Http\Controllers\FactionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\NpcController;
use App\Http\Controllers\PatchController;
use App\Http\Controllers\PatchExportController;
use App\Http\Controllers\PetController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SpellController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\ZoneController;
use App\Http\Middleware\TasksEnabled;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// searchable historical EverQuest patch archive
Route::prefix('patches')->name('patches.')->group(function () {
    Route::get('/', [PatchController::class, 'index'])->name('index');
    Route::get('/sources', [PatchController::class, 'sources'])->name('sources');
    Route::get('/export/json', [PatchExportController::class, 'json'])
        ->name('export.json')->middleware('throttle:60,1');
    Route::get('/export/csv', [PatchExportController::class, 'csv'])
        ->name('export.csv')->middleware('throttle:60,1');
    Route::get('/feed.xml', [PatchExportController::class, 'feed'])
        ->name('feed')->middleware('throttle:60,1');
    Route::get('/{slug}/raw.txt', [PatchExportController::class, 'raw'])
        ->name('raw')->where('slug', '\\d{4}-\\d{2}-\\d{2}-\\d+');
    Route::get('/{slug}.json', [PatchExportController::class, 'singleJson'])
        ->name('single.json')->where('slug', '\\d{4}-\\d{2}-\\d{2}-\\d+');
    Route::get('/{slug}.csv', [PatchExportController::class, 'singleCsv'])
        ->name('single.csv')->where('slug', '\\d{4}-\\d{2}-\\d{2}-\\d+');
    Route::get('/{slug}', [PatchController::class, 'show'])
        ->name('show')->where('slug', '\\d{4}-\\d{2}-\\d{2}-\\d+');
});

// Friendly compatibility with the reference site's path-style examples.
Route::get('/patch', [PatchController::class, 'legacyIndex']);
Route::get('/patch/view/{slug}/raw', [PatchController::class, 'legacyRaw'])
    ->where('slug', '\\d{4}-\\d{2}-\\d{2}-\\d+');
Route::get('/patch/view/{slug}', [PatchController::class, 'legacyView'])
    ->where('slug', '\\d{4}-\\d{2}-\\d{2}-\\d+');
Route::get('/patch/export/json/{query?}', [PatchExportController::class, 'legacyJson'])
    ->where('query', '[^/]+')->middleware('throttle:60,1');
Route::get('/patch/export/csv/{query?}', [PatchExportController::class, 'legacyCsv'])
    ->where('query', '[^/]+')->middleware('throttle:60,1');
Route::get('/patch/feed', fn () => redirect()->route('patches.feed', status: 301));
Route::get('/patch/{query}', [PatchController::class, 'legacySearch'])->where('query', '[^/]+');

// global search
Route::get('/search/suggest', [SearchController::class, 'suggest'])->middleware('throttle:120,1');

// aa abilitys
Route::get('/aa', [AaAbilityController::class, 'index'])->name('aa.index');
Route::get('/aa/{ability}', [AaAbilityController::class, 'show'])->name('aa.show');;

// items
Route::get('/items', [ItemController::class, 'index'])->name('items.index');
Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');
Route::get('/items/popup/{item}', [ItemController::class, 'popup'])->name('items.popup');
Route::get('/items/drops_by_zone/{item}', [ItemController::class, 'drops_by_zone'])->name('items.drops_by_zone');

// item discovery
Route::get('/discovery', [DiscoveredItemController::class, 'index'])->name('discovery.index');
Route::get('/discovery/leaderboard', [DiscoveredItemController::class, 'leaderboard'])->name('discovery.leaderboard');

// zones
Route::get('/zones', [ZoneController::class, 'index'])->name('zones.index');
Route::get('/zones/{zone}/atlas', [ZoneController::class, 'atlas'])
    ->name('zones.atlas')
    ->whereNumber('zone');
Route::get('/zones/{zone}', [ZoneController::class, 'show'])->name('zones.show');

// spells
Route::get('/spells/other', [SpellController::class, 'extra'])->name('spells.extra');
Route::get('/spells', [SpellController::class, 'index'])->name('spells.index');
Route::get('/spells/{spell}', [SpellController::class, 'show'])->name('spells.show');
Route::get('/spells/popup/{spell}', [SpellController::class, 'popup'])->name('spells.popup');

// recipes
Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes.index');
Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])->name('recipes.show');

// npcs
Route::get('/npcs', [NpcController::class, 'index'])->name('npcs.index');
Route::get('/npcs/{npc}', [NpcController::class, 'show'])
    ->name('npcs.show')
    ->whereNumber('npc');

// factions
Route::get('/factions', [FactionController::class, 'index'])->name('factions.index');
Route::get('/factions/{faction}', [FactionController::class, 'show'])->name('factions.show');

Route::get('/tasks', [TaskController::class, 'index'])
    ->name('tasks.index')
    ->middleware(TasksEnabled::class);

Route::get('/tasks/{task}', [TaskController::class, 'show'])
    ->name('tasks.show')
    ->middleware(TasksEnabled::class);

// pets
Route::get('/pets/{id?}', [PetController::class, 'index'])
    ->name('pets.index')
    ->where('id', '[0-9]+');
Route::get('/pet/{pet}', [PetController::class, 'show'])->name('pets.show');
Route::get('/pet/popup/{pet}', [PetController::class, 'popup'])->name('pets.popup');
