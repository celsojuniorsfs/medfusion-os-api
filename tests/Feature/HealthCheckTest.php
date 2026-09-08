<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Projeto é API-only (sem Blade/Vite — ver docs/architecture.md); "/" não existe, o smoke test
 * de "a aplicação sobe" é o health check embutido do Laravel (bootstrap/app.php, health: '/up').
 */
class HealthCheckTest extends TestCase
{
    public function test_the_health_check_endpoint_is_up(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
