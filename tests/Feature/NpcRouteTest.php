<?php

namespace Tests\Feature;

use Tests\TestCase;

class NpcRouteTest extends TestCase
{
    public function test_npc_show_route_rejects_non_numeric_identifiers(): void
    {
        $this->get('/npcs/not-a-number')->assertNotFound();
    }
}
