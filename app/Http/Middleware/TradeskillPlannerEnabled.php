<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TradeskillPlannerEnabled
{
    /**
     * Return a 404 so a disabled optional feature is not advertised publicly.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('everquest.tradeskill_planner.enable', true), 404);

        return $next($request);
    }
}
