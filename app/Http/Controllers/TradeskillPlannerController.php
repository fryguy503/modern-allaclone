<?php

namespace App\Http\Controllers;

use App\Models\TradeskillRecipe;
use App\Services\Tradeskills\RecipeGraphBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TradeskillPlannerController extends Controller
{
    public function show(TradeskillRecipe $recipe, RecipeGraphBuilder $graphBuilder): View
    {
        $plannerData = null;
        $plannerError = null;

        try {
            $cacheMinutes = min(
                10_080,
                max(1, (int) config('everquest.tradeskill_planner.cache_ttl_minutes', 60)),
            );
            $cacheKey = $graphBuilder->cacheKey($recipe);
            $cachedEnvelope = Cache::get($cacheKey);
            if (! is_array($cachedEnvelope) || ! $graphBuilder->cacheEnvelopeIsCurrent($cachedEnvelope)) {
                $cachedEnvelope = $graphBuilder->buildCacheEnvelope($recipe);
                Cache::put($cacheKey, $cachedEnvelope, now()->addMinutes($cacheMinutes));
            }
            $plannerData = $cachedEnvelope['graph'];
        } catch (Throwable $exception) {
            report($exception);
            $plannerError = 'The recipe plan could not be built right now. Please try again shortly.';
        }

        return view('recipes.plan', [
            'plannerData' => $plannerData,
            'plannerError' => $plannerError,
            'recipe' => $recipe,
            'metaTitle' => config('app.name').' - Tradeskill Plan: '.ucRomanNumeral($recipe->name),
        ]);
    }

    public function saved(RecipeGraphBuilder $graphBuilder): View
    {
        return view('recipes.plans', [
            'plannerData' => null,
            'plannerError' => null,
            'recipe' => null,
            'limits' => $graphBuilder->limits(),
            'metaTitle' => config('app.name').' - Saved Tradeskill Plans',
        ]);
    }
}
