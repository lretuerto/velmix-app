<?php

namespace App\Http\Middleware;

use App\Services\Platform\IdempotencyService;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function __construct(
        private readonly IdempotencyService $service,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));

        if ($idempotencyKey === '') {
            return $next($request);
        }

        $reservation = $this->service->begin(
            (int) $request->attributes->get('tenant_id'),
            (string) $request->method(),
            '/'.ltrim($request->path(), '/'),
            $idempotencyKey,
            [
                'body' => $request->all(),
                'query' => $request->query(),
            ],
        );

        if ($reservation['response'] instanceof Response) {
            $reservation['response']->headers->set('X-Idempotency-Key', $idempotencyKey);
            $reservation['response']->headers->set('X-Idempotency-Status', 'replayed');

            return $reservation['response'];
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $response = $this->exceptions->render($request, $exception);
        }

        $this->service->complete($reservation['record'], $response);
        $response->headers->set('X-Idempotency-Key', $idempotencyKey);
        $response->headers->set('X-Idempotency-Status', 'stored');

        return $response;
    }
}
