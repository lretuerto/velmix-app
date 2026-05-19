<?php

namespace App\Services\Billing;

use App\Services\Audit\TenantActivityLogService;
use App\Services\Billing\Providers\BillingDispatchProviderRegistry;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingProviderHealthService
{
    public function check(int $tenantId, int $userId): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $profiles = app(BillingProviderProfileService::class);
        $profileModel = $profiles->ensureModel($tenantId);
        $profile = $profiles->current($tenantId);
        $provider = app(BillingDispatchProviderRegistry::class)->forCode((string) $profile['provider_code']);
        $health = $provider->checkHealth($profile);

        $profileModel->forceFill([
            'health_status' => (string) ($health['status'] ?? 'unknown'),
            'health_checked_at' => now(),
            'health_message' => $health['message'] ?? null,
        ])->save();

        app(TenantActivityLogService::class)->record(
            $tenantId,
            $userId,
            'billing',
            'billing.provider_health.checked',
            'billing_provider_profile',
            $profileModel->id,
            sprintf('Billing provider health checked for %s.', $profileModel->provider_code),
            [
                'provider_code' => $profileModel->provider_code,
                'environment' => $profileModel->environment,
                'health_status' => $profileModel->health_status,
            ],
        );

        return array_merge(
            $profiles->publicCurrent($tenantId),
            ['capabilities' => $health['capabilities'] ?? []],
        );
    }

    public function trace(int $tenantId, int $limit = 20): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($limit <= 0) {
            throw new HttpException(422, 'Trace limit is invalid.');
        }

        $profile = app(BillingProviderProfileService::class)->current($tenantId);

        $statusBreakdown = DB::table('outbox_attempts')
            ->join('outbox_events', 'outbox_events.id', '=', 'outbox_attempts.outbox_event_id')
            ->where('outbox_events.tenant_id', $tenantId)
            ->selectRaw('
                COALESCE(outbox_attempts.provider_code, ?) as provider_code,
                COALESCE(outbox_attempts.provider_environment, ?) as provider_environment,
                outbox_attempts.status,
                COUNT(*) as attempts_count
            ', [
                (string) $profile['provider_code'],
                (string) $profile['environment'],
            ])
            ->groupBy('outbox_attempts.provider_code', 'outbox_attempts.provider_environment', 'outbox_attempts.status')
            ->orderBy('provider_code')
            ->orderBy('provider_environment')
            ->orderBy('outbox_attempts.status')
            ->get()
            ->map(fn (object $row) => [
                'provider_code' => (string) $row->provider_code,
                'provider_environment' => (string) $row->provider_environment,
                'status' => (string) $row->status,
                'attempts_count' => (int) $row->attempts_count,
            ])
            ->values()
            ->all();

        $recentAttempts = DB::table('outbox_attempts')
            ->join('outbox_events', 'outbox_events.id', '=', 'outbox_attempts.outbox_event_id')
            ->where('outbox_events.tenant_id', $tenantId)
            ->orderByDesc('outbox_attempts.id')
            ->limit($limit)
            ->get([
                'outbox_attempts.id',
                'outbox_attempts.outbox_event_id',
                'outbox_attempts.status',
                'outbox_attempts.provider_code',
                'outbox_attempts.provider_environment',
                'outbox_attempts.provider_reference',
                'outbox_attempts.sunat_ticket',
                'outbox_attempts.error_message',
                'outbox_attempts.created_at',
                'outbox_events.aggregate_type',
                'outbox_events.aggregate_id',
                'outbox_events.event_type',
            ])
            ->map(fn (object $attempt) => [
                'id' => (int) $attempt->id,
                'event_id' => (int) $attempt->outbox_event_id,
                'status' => (string) $attempt->status,
                'provider_code' => $attempt->provider_code !== null ? (string) $attempt->provider_code : (string) $profile['provider_code'],
                'provider_environment' => $attempt->provider_environment !== null ? (string) $attempt->provider_environment : (string) $profile['environment'],
                'provider_reference' => $attempt->provider_reference,
                'sunat_ticket' => $attempt->sunat_ticket,
                'error_message' => $attempt->error_message,
                'aggregate_type' => (string) $attempt->aggregate_type,
                'aggregate_id' => (int) $attempt->aggregate_id,
                'event_type' => (string) $attempt->event_type,
                'created_at' => (string) $attempt->created_at,
            ])
            ->values()
            ->all();

        return [
            'tenant_id' => $tenantId,
            'provider_profile' => app(BillingProviderProfileService::class)->publicSerializeArray($profile),
            'status_breakdown' => $statusBreakdown,
            'recent_attempts' => $recentAttempts,
        ];
    }
}
