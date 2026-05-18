<?php

namespace App\Services\Platform;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IdempotencyService
{
    private const REQUEST_FINGERPRINT_VERSION = 'v1';

    public function begin(
        int $tenantId,
        string $method,
        string $path,
        string $idempotencyKey,
        array $requestPayload,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required for idempotent requests.');
        }

        $method = strtoupper(trim($method));
        $path = trim($path);
        $idempotencyKey = trim($idempotencyKey);

        if ($method === '' || $path === '' || $idempotencyKey === '') {
            throw new HttpException(422, 'Idempotency scope is invalid.');
        }

        $requestHash = $this->hashPayload($requestPayload);

        return DB::transaction(function () use ($tenantId, $method, $path, $idempotencyKey, $requestHash): array {
            $record = $this->findForUpdate($tenantId, $method, $path, $idempotencyKey);

            if ($record === null) {
                try {
                    $record = IdempotencyKey::query()->create([
                        'tenant_id' => $tenantId,
                        'method' => $method,
                        'path' => $path,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'request_fingerprint_version' => self::REQUEST_FINGERPRINT_VERSION,
                        'status' => 'in_progress',
                        'locked_until' => $this->lockUntil(),
                    ]);
                } catch (QueryException $exception) {
                    if (! $this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }

                    $record = $this->findForUpdate($tenantId, $method, $path, $idempotencyKey);

                    if ($record === null) {
                        throw $exception;
                    }
                }

                if ($record->wasRecentlyCreated) {
                    return ['record' => $record, 'response' => null];
                }
            }

            if ($record->request_hash !== $requestHash) {
                throw new HttpException(409, 'Idempotency key already exists for a different request payload.');
            }

            if ($record->status === 'completed' && $record->response_status !== null) {
                return [
                    'record' => $record,
                    'response' => response(
                        $record->response_body ?? '',
                        (int) $record->response_status,
                        $record->response_headers ?? ['Content-Type' => 'application/json'],
                    ),
                ];
            }

            if ($record->locked_until !== null && $record->locked_until->isFuture()) {
                throw new HttpException(409, 'Idempotent request is already being processed.');
            }

            $record->forceFill([
                'status' => 'in_progress',
                'locked_until' => $this->lockUntil(),
                'completed_at' => null,
                'error_class' => null,
                'request_fingerprint_version' => self::REQUEST_FINGERPRINT_VERSION,
                'response_status' => null,
                'response_headers' => null,
                'response_body' => null,
            ])->save();

            return ['record' => $record->fresh(), 'response' => null];
        });
    }

    public function complete(IdempotencyKey $record, Response $response): bool
    {
        if ($response->getStatusCode() >= 500) {
            $this->fail($record, null, 'server_error_response');

            return false;
        }

        $record->forceFill([
            'status' => 'completed',
            'locked_until' => null,
            'completed_at' => now(),
            'error_class' => null,
            'request_fingerprint_version' => self::REQUEST_FINGERPRINT_VERSION,
            'response_status' => $response->getStatusCode(),
            'response_headers' => array_filter([
                'Content-Type' => $response->headers->get('Content-Type'),
            ]),
            'response_body' => method_exists($response, 'getContent') && $response->getContent() !== false
                ? $response->getContent()
                : null,
        ])->save();

        return true;
    }

    public function fail(IdempotencyKey $record, ?\Throwable $exception = null, ?string $errorClass = null): void
    {
        $record->forceFill([
            'status' => 'failed',
            'locked_until' => null,
            'completed_at' => null,
            'error_class' => $exception !== null ? $exception::class : $errorClass,
            'response_status' => null,
            'response_headers' => null,
            'response_body' => null,
        ])->save();
    }

    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($this->normalize($payload), JSON_THROW_ON_ERROR));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->normalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }

    private function findForUpdate(int $tenantId, string $method, string $path, string $idempotencyKey): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('tenant_id', $tenantId)
            ->where('method', $method)
            ->where('path', $path)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    private function lockUntil(): \Carbon\CarbonInterface
    {
        return now()->addMinutes(max(1, (int) config('velmix.idempotency.lock_minutes', 5)));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $previous = strtolower($exception->getPrevious()?->getMessage() ?? '');

        foreach ([
            'idempotency_scope_unique',
            'unique constraint failed',
            'duplicate entry',
        ] as $needle) {
            if (str_contains($message, $needle) || ($previous !== '' && str_contains($previous, $needle))) {
                return true;
            }
        }

        return false;
    }
}
