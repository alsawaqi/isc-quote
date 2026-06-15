<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiBootstrapTest extends TestCase
{
    public function test_api_routes_are_registered_separately_from_the_spa_catch_all(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_cors_configuration_is_present_for_api_routes(): void
    {
        $this->assertFileExists(config_path('cors.php'));
        $this->assertContains('api/*', config('cors.paths'));
    }
}
