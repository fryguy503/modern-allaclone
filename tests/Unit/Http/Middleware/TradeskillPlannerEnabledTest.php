<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\TradeskillPlannerEnabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class TradeskillPlannerEnabledTest extends TestCase
{
    public function test_request_continues_when_planner_is_enabled(): void
    {
        config()->set('everquest.tradeskill_planner.enable', true);

        $response = (new TradeskillPlannerEnabled)->handle(
            Request::create('/recipes/plans'),
            fn (): Response => new Response('allowed'),
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('allowed', $response->getContent());
    }

    public function test_request_is_hidden_when_planner_is_disabled(): void
    {
        config()->set('everquest.tradeskill_planner.enable', false);

        $this->expectException(NotFoundHttpException::class);

        (new TradeskillPlannerEnabled)->handle(
            Request::create('/recipes/plans'),
            fn (): Response => new Response('must not run'),
        );
    }
}
