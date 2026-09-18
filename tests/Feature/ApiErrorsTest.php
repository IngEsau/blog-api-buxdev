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
            ->assertJsonStructure(['message'])
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
}
