<?php

namespace App\Http\Middleware;

use App\Services\Platform\IdempotencyService;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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
            if ((bool) config('velmix.idempotency.strict', false)) {
                throw new HttpException(428, 'Idempotency-Key header is required for this operation.');
            }

            $response = $next($request);
            $response->headers->set('X-Idempotency-Required', 'recommended');

            return $response;
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
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            $response = $this->exceptions->render($request, $exception);

            if ($response->getStatusCode() >= 500) {
                $this->service->fail($reservation['record'], $exception);
                $response->headers->set('X-Idempotency-Key', $idempotencyKey);
                $response->headers->set('X-Idempotency-Status', 'released');

                return $response;
            }
        }

        $stored = $this->service->complete($reservation['record'], $response);
        $response->headers->set('X-Idempotency-Key', $idempotencyKey);
        $response->headers->set('X-Idempotency-Status', $stored ? 'stored' : 'released');

        return $response;
    }
}
