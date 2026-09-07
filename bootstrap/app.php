<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TasksEnabled;
use App\Http\Middleware\TradeskillPlannerEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tasks.enabled' => TasksEnabled::class,
            'tradeskill-planner.enabled' => TradeskillPlannerEnabled::class,
        ]);
        $middleware->appendToGroup('web', SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
