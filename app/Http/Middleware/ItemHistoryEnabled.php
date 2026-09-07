<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ItemHistoryEnabled
{
    /**
     * Keep the optional archive and its route undiscoverable when disabled.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('everquest.item_history.enable', false), 404);

        $itemId = $request->route('item');
        if ($itemId !== null) {
            $validatedId = filter_var($itemId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            abort_if($validatedId === false, 404);
        }

        return $next($request);
    }
}
