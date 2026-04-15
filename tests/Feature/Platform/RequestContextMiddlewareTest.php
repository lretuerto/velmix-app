<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\AddRequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RequestContextMiddlewareTest extends TestCase
{
    public function test_request_context_sets_header_and_log_context(): void
    {
        Log::spy();

        $request = Request::create('/health/live', 'GET', server: [
            'HTTP_X_REQUEST_ID' => 'trace-123',
        ]);

        $response = app(AddRequestContext::class)->handle(
            $request,
            fn () => new Response('ok', 200),
        );

        Log::shouldHaveReceived('withContext')->once()->with([
            'request_id' => 'trace-123',
        ]);

        $this->assertSame('trace-123', $request->attributes->get('request_id'));
        $this->assertSame('trace-123', app('request_id'));
        $this->assertSame('trace-123', $response->headers->get('X-Request-Id'));
    }

    public function test_request_context_generates_safe_request_id_when_header_is_invalid(): void
    {
        Log::spy();

        $request = Request::create('/health/live', 'GET', server: [
            'HTTP_X_REQUEST_ID' => str_repeat('x', 130),
        ]);

        app(AddRequestContext::class)->handle(
            $request,
            fn () => new Response('ok', 200),
        );

        $requestId = $request->attributes->get('request_id');

        $this->assertIsString($requestId);
        $this->assertNotSame(str_repeat('x', 130), $requestId);
        $this->assertNotEmpty($requestId);
    }
}
