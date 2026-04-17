<?php

namespace App\Services\Platform;

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IdempotencyService
{
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
            $record = IdempotencyKey::query()
                ->where('tenant_id', $tenantId)
                ->where('method', $method)
                ->where('path', $path)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                $record = IdempotencyKey::query()->create([
                    'tenant_id' => $tenantId,
                    'method' => $method,
                    'path' => $path,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => 'in_progress',
                    'locked_until' => now()->addMinutes(5),
                ]);

                return ['record' => $record, 'response' => null];
            }

            if ($record->request_hash !== $requestHash) {
                throw new HttpException(409, 'Idempotency key already exists for a different request payload.');
            }

            if ($record->response_status !== null) {
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
                'locked_until' => now()->addMinutes(5),
                'response_status' => null,
                'response_headers' => null,
                'response_body' => null,
            ])->save();

            return ['record' => $record->fresh(), 'response' => null];
        });
    }

    public function complete(IdempotencyKey $record, Response $response): void
    {
        $record->forceFill([
            'status' => 'completed',
            'locked_until' => null,
            'response_status' => $response->getStatusCode(),
            'response_headers' => array_filter([
                'Content-Type' => $response->headers->get('Content-Type'),
            ]),
            'response_body' => method_exists($response, 'getContent') && $response->getContent() !== false
                ? $response->getContent()
                : null,
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
}
