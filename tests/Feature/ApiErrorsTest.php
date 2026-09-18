<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorsTest extends TestCase
{
    public function test_unknown_api_route_returns_json_without_an_accept_header(): void
    {
        $this->get('/api/nonexistent')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Not Found'])
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
    }

    public function test_unknown_root_route_returns_json_even_when_html_is_requested(): void
    {
        $this->withHeader('Accept', 'text/html')
            ->get('/nonexistent')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_server_errors_do_not_disclose_internal_details(): void
    {
        Route::get('/test-error', function (): never {
            throw new RuntimeException('Sensitive internal detail');
        });

        $this->get('/test-error')
            ->assertInternalServerError()
            ->assertExactJson(['message' => 'Server Error']);
    }

    public function test_http_exceptions_do_not_disclose_internal_messages(): void
    {
        Route::get('/test-http-error', function (): never {
            abort(503, 'Sensitive upstream detail', ['Retry-After' => '60']);
        });

        $this->get('/test-http-error')
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Retry-After', '60')
            ->assertExactJson(['message' => 'Service Unavailable']);
    }

    public function test_method_not_allowed_is_generic_and_preserves_allowed_methods(): void
    {
        $this->post('/health')
            ->assertStatus(405)
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertExactJson(['message' => 'Method Not Allowed']);
    }
}
