<?php

namespace App\Services\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantActivityLogService
{
    public function record(
        int $tenantId,
        ?int $userId,
        string $domain,
        string $eventType,
        string $aggregateType,
        ?int $aggregateId,
        string $summary,
        array $metadata = [],
        ?string $occurredAt = null,
    ): int {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $domain = trim($domain);
        $eventType = trim($eventType);
        $aggregateType = trim($aggregateType);
        $summary = trim($summary);

        if ($domain === '' || $eventType === '' || $aggregateType === '' || $summary === '') {
            throw new HttpException(422, 'Activity log payload is invalid.');
        }

        return DB::table('tenant_activity_logs')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'domain' => $domain,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'summary' => $summary,
            'metadata' => $metadata !== [] ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'occurred_at' => $occurredAt !== null ? CarbonImmutable::parse($occurredAt) : now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function list(int $tenantId, array $filters = []): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $limit = (int) ($filters['limit'] ?? 50);
        $limit = max(1, min($limit, 200));

        $query = DB::table('tenant_activity_logs')
            ->leftJoin('users', 'users.id', '=', 'tenant_activity_logs.user_id')
            ->where('tenant_activity_logs.tenant_id', $tenantId);

        foreach (['domain', 'event_type', 'aggregate_type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('tenant_activity_logs.'.$field, $filters[$field]);
            }
        }

        if (! empty($filters['user_id'])) {
            $query->where('tenant_activity_logs.user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('tenant_activity_logs.occurred_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('tenant_activity_logs.occurred_at', '<=', CarbonImmutable::parse($filters['date_to'])->endOfDay());
        }

        return $query
            ->orderByDesc('tenant_activity_logs.occurred_at')
            ->orderByDesc('tenant_activity_logs.id')
            ->limit($limit)
            ->get([
                'tenant_activity_logs.id',
                'tenant_activity_logs.tenant_id',
                'tenant_activity_logs.user_id',
                'tenant_activity_logs.domain',
                'tenant_activity_logs.event_type',
                'tenant_activity_logs.aggregate_type',
                'tenant_activity_logs.aggregate_id',
                'tenant_activity_logs.summary',
                'tenant_activity_logs.metadata',
                'tenant_activity_logs.occurred_at',
                'users.name as user_name',
            ])
            ->map(fn (object $activity) => $this->formatActivity($activity))
            ->all();
    }

    public function detail(int $tenantId, int $activityId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $activity = DB::table('tenant_activity_logs')
            ->leftJoin('users', 'users.id', '=', 'tenant_activity_logs.user_id')
            ->where('tenant_activity_logs.tenant_id', $tenantId)
            ->where('tenant_activity_logs.id', $activityId)
            ->first([
                'tenant_activity_logs.id',
                'tenant_activity_logs.tenant_id',
                'tenant_activity_logs.user_id',
                'tenant_activity_logs.domain',
                'tenant_activity_logs.event_type',
                'tenant_activity_logs.aggregate_type',
                'tenant_activity_logs.aggregate_id',
                'tenant_activity_logs.summary',
                'tenant_activity_logs.metadata',
                'tenant_activity_logs.occurred_at',
                'users.name as user_name',
            ]);

        if ($activity === null) {
            throw new HttpException(404, 'Activity log not found.');
        }

        return $this->formatActivity($activity);
    }

    private function formatActivity(object $activity): array
    {
        return [
            'id' => $activity->id,
            'tenant_id' => $activity->tenant_id,
            'domain' => $activity->domain,
            'event_type' => $activity->event_type,
            'aggregate_type' => $activity->aggregate_type,
            'aggregate_id' => $activity->aggregate_id !== null ? (int) $activity->aggregate_id : null,
            'summary' => $activity->summary,
            'metadata' => $activity->metadata !== null ? json_decode($activity->metadata, true, 512, JSON_THROW_ON_ERROR) : [],
            'occurred_at' => $activity->occurred_at,
            'user' => $activity->user_id !== null ? [
                'id' => (int) $activity->user_id,
                'name' => $activity->user_name,
            ] : null,
        ];
    }
}
