<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SpellHistoryEnabled
{
    /**
     * Keep the optional archive and its route undiscoverable when disabled.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('everquest.spell_history.enable', false), 404);

        $spellId = $request->route('spell');

        if ($spellId !== null) {
            $validatedId = filter_var($spellId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            abort_if($validatedId === false, 404);
        }

        return $next($request);
    }
}
