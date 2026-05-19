<?php

namespace App\Services\Billing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingOutboxLineageService
{
    public function detail(int $tenantId, int $eventId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $currentEvent = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('id', $eventId)
            ->first([
                'id',
                'aggregate_type',
                'aggregate_id',
                'event_type',
                'status',
                'replayed_from_event_id',
            ]);

        if ($currentEvent === null) {
            throw new HttpException(404, 'Outbox event not found.');
        }

        $events = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', $currentEvent->aggregate_type)
            ->where('aggregate_id', $currentEvent->aggregate_id)
            ->orderBy('id')
            ->get([
                'id',
                'aggregate_type',
                'aggregate_id',
                'event_type',
                'payload',
                'status',
                'retry_count',
                'last_error',
                'replayed_from_event_id',
                'created_at',
                'updated_at',
            ]);

        $eventIds = $events->pluck('id')->map(fn ($id) => (int) $id)->all();
        $attemptsByEvent = $this->loadAttempts($eventIds);
        $payloads = app(BillingDocumentPayloadService::class)->listForAggregate(
            $tenantId,
            (string) $currentEvent->aggregate_type,
            (int) $currentEvent->aggregate_id,
        );
        $activities = $this->loadActivities(
            $tenantId,
            $eventIds,
            (string) $currentEvent->aggregate_type,
            (int) $currentEvent->aggregate_id,
        );
        $depthByEvent = $this->depthMap($events);

        return [
            'tenant_id' => $tenantId,
            'aggregate_type' => (string) $currentEvent->aggregate_type,
            'aggregate_id' => (int) $currentEvent->aggregate_id,
            'current_event_id' => (int) $currentEvent->id,
            'root_event_id' => (int) $events->first()->id,
            'latest_event_id' => (int) $events->last()->id,
            'lineage' => $events->map(function (object $event) use ($attemptsByEvent, $depthByEvent, $eventId) {
                $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);

                return [
                    'event_id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'status' => (string) $event->status,
                    'retry_count' => (int) $event->retry_count,
                    'last_error' => $event->last_error,
                    'replayed_from_event_id' => $event->replayed_from_event_id !== null ? (int) $event->replayed_from_event_id : null,
                    'replay_depth' => $depthByEvent[(int) $event->id] ?? 0,
                    'is_current' => (int) $event->id === $eventId,
                    'payload_snapshot' => [
                        'billing_payload_id' => $payload['billing_payload_id'] ?? null,
                        'provider_code' => $payload['provider_code'] ?? null,
                        'provider_environment' => $payload['provider_environment'] ?? null,
                        'schema_version' => $payload['schema_version'] ?? null,
                        'document_kind' => $payload['document_kind'] ?? null,
                        'document_number' => $payload['document_number'] ?? null,
                    ],
                    'attempts' => $attemptsByEvent[(int) $event->id] ?? [],
                    'created_at' => (string) $event->created_at,
                    'updated_at' => (string) $event->updated_at,
                ];
            })->values()->all(),
            'payload_snapshots' => $payloads,
            'activity_logs' => $activities,
        ];
    }

    private function loadAttempts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return DB::table('outbox_attempts')
            ->whereIn('outbox_event_id', $eventIds)
            ->orderBy('id')
            ->get([
                'id',
                'outbox_event_id',
                'status',
                'provider_code',
                'provider_environment',
                'provider_reference',
                'sunat_ticket',
                'error_message',
                'created_at',
            ])
            ->groupBy('outbox_event_id')
            ->map(fn (Collection $attempts) => $attempts->map(fn (object $attempt) => [
                'id' => (int) $attempt->id,
                'event_id' => (int) $attempt->outbox_event_id,
                'status' => (string) $attempt->status,
                'provider_code' => $attempt->provider_code,
                'provider_environment' => $attempt->provider_environment,
                'provider_reference' => $attempt->provider_reference,
                'sunat_ticket' => $attempt->sunat_ticket,
                'error_message' => $attempt->error_message,
                'created_at' => (string) $attempt->created_at,
            ])->values()->all())
            ->all();
    }

    private function loadActivities(int $tenantId, array $eventIds, string $aggregateType, int $aggregateId): array
    {
        return DB::table('tenant_activity_logs')
            ->where('tenant_id', $tenantId)
            ->where('domain', 'billing')
            ->where(function ($query) use ($eventIds, $aggregateType, $aggregateId) {
                $query->where(function ($outboxQuery) use ($eventIds) {
                    $outboxQuery->where('aggregate_type', 'outbox_event')
                        ->whereIn('aggregate_id', $eventIds);
                })->orWhere(function ($documentQuery) use ($aggregateType, $aggregateId) {
                    $documentQuery->where('aggregate_type', $aggregateType)
                        ->where('aggregate_id', $aggregateId);
                });
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get([
                'id',
                'event_type',
                'aggregate_type',
                'aggregate_id',
                'summary',
                'metadata',
                'occurred_at',
            ])
            ->map(fn (object $activity) => [
                'id' => (int) $activity->id,
                'event_type' => (string) $activity->event_type,
                'aggregate_type' => (string) $activity->aggregate_type,
                'aggregate_id' => $activity->aggregate_id !== null ? (int) $activity->aggregate_id : null,
                'summary' => (string) $activity->summary,
                'metadata' => $activity->metadata !== null ? json_decode((string) $activity->metadata, true, 512, JSON_THROW_ON_ERROR) : [],
                'occurred_at' => (string) $activity->occurred_at,
            ])
            ->values()
            ->all();
    }

    private function depthMap(Collection $events): array
    {
        $depthByEvent = [];

        foreach ($events as $event) {
            $depthByEvent[(int) $event->id] = $event->replayed_from_event_id !== null
                ? (($depthByEvent[(int) $event->replayed_from_event_id] ?? 0) + 1)
                : 0;
        }

        return $depthByEvent;
    }
}
