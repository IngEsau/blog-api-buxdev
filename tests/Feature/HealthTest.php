<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_ok(): void
    {
        $this->get('/health')->assertOk();
    }

    public function test_health_returns_the_expected_json(): void
    {
        $this->get('/health')
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'buxdev-api',
            ]);
    }

    public function test_post_health_returns_method_not_allowed_as_json(): void
    {
        $this->post('/health')
            ->assertStatus(405)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
    }

    public function test_health_does_not_enable_cors(): void
    {
        $this->withHeader('Origin', 'https://example.com')
            ->get('/health')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
