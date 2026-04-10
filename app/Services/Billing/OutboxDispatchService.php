<?php

namespace App\Services\Billing;

use App\Services\Audit\TenantActivityLogService;
use App\Services\Billing\Providers\BillingDispatchProviderRegistry;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OutboxDispatchService
{
    private const VALID_OUTCOMES = ['accepted', 'rejected', 'transient_fail'];

    public function dispatchNext(int $tenantId, ?string $outcome = null): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($outcome !== null && ! in_array($outcome, self::VALID_OUTCOMES, true)) {
            throw new HttpException(422, 'Dispatch outcome is invalid.');
        }

        return DB::transaction(function () use ($tenantId, $outcome) {
            $event = DB::table('outbox_events')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id', 'aggregate_type', 'aggregate_id', 'event_type']);

            if ($event === null) {
                throw new HttpException(404, 'No pending outbox events.');
            }

            return $this->dispatchLockedEvent($tenantId, $event, $outcome);
        });
    }

    public function dispatchBatch(int $tenantId, int $limit = 10, ?string $outcome = null): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($limit <= 0) {
            throw new HttpException(422, 'Dispatch limit is invalid.');
        }

        if ($outcome !== null && ! in_array($outcome, self::VALID_OUTCOMES, true)) {
            throw new HttpException(422, 'Dispatch outcome is invalid.');
        }

        $results = [];
        $statusCounts = [
            'processed' => 0,
            'rejected' => 0,
            'failed' => 0,
        ];

        for ($i = 0; $i < $limit; $i++) {
            $eventResult = DB::transaction(function () use ($tenantId, $outcome) {
                $event = DB::table('outbox_events')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'pending')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first(['id', 'aggregate_type', 'aggregate_id', 'event_type']);

                if ($event === null) {
                    return null;
                }

                return $this->dispatchLockedEvent($tenantId, $event, $outcome);
            });

            if ($eventResult === null) {
                break;
            }

            $results[] = $eventResult;
            $status = (string) ($eventResult['status'] ?? 'processed');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        return [
            'tenant_id' => $tenantId,
            'requested_limit' => $limit,
            'processed_count' => count($results),
            'status_counts' => $statusCounts,
            'results' => $results,
        ];
    }

    public function queueSummary(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $counts = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed_count,
                COALESCE(SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END), 0) as processed_count
            ")
            ->first();

        $oldestPending = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->first(['id', 'aggregate_type', 'aggregate_id', 'event_type', 'created_at']);

        $latestAttempt = DB::table('outbox_attempts')
            ->join('outbox_events', 'outbox_events.id', '=', 'outbox_attempts.outbox_event_id')
            ->where('outbox_events.tenant_id', $tenantId)
            ->orderByDesc('outbox_attempts.id')
            ->first([
                'outbox_attempts.outbox_event_id',
                'outbox_attempts.status',
                'outbox_attempts.provider_code',
                'outbox_attempts.provider_environment',
                'outbox_attempts.provider_reference',
                'outbox_attempts.sunat_ticket',
                'outbox_attempts.error_message',
                'outbox_attempts.created_at',
            ]);
        $profile = app(BillingProviderProfileService::class)->current($tenantId);

        return [
            'tenant_id' => $tenantId,
            'total_count' => (int) ($counts->total_count ?? 0),
            'pending_count' => (int) ($counts->pending_count ?? 0),
            'failed_count' => (int) ($counts->failed_count ?? 0),
            'processed_count' => (int) ($counts->processed_count ?? 0),
            'provider_profile' => app(BillingProviderProfileService::class)->publicSerializeArray($profile),
            'oldest_pending' => $oldestPending !== null ? [
                'event_id' => (int) $oldestPending->id,
                'aggregate_type' => (string) $oldestPending->aggregate_type,
                'aggregate_id' => (int) $oldestPending->aggregate_id,
                'event_type' => (string) $oldestPending->event_type,
                'created_at' => (string) $oldestPending->created_at,
            ] : null,
            'latest_attempt' => $latestAttempt !== null ? [
                'event_id' => (int) $latestAttempt->outbox_event_id,
                'status' => (string) $latestAttempt->status,
                'provider_code' => $latestAttempt->provider_code,
                'provider_environment' => $latestAttempt->provider_environment,
                'provider_reference' => $latestAttempt->provider_reference,
                'sunat_ticket' => $latestAttempt->sunat_ticket,
                'error_message' => $latestAttempt->error_message,
                'created_at' => (string) $latestAttempt->created_at,
            ] : null,
        ];
    }

    public function pendingTenantIds(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('outbox_events')) {
            return [];
        }

        return DB::table('outbox_events')
            ->where('status', 'pending')
            ->orderBy('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn ($tenantId) => (int) $tenantId)
            ->all();
    }

    private function dispatchLockedEvent(int $tenantId, object $event, ?string $outcome): array
    {
        $documentTable = $event->aggregate_type === 'sale_credit_note'
            ? 'sale_credit_notes'
            : 'electronic_vouchers';
        $profile = app(BillingProviderProfileService::class)->current($tenantId);
        $eventPayload = json_decode((string) DB::table('outbox_events')->where('id', $event->id)->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $effectiveProfile = array_merge($profile, [
            'provider_code' => (string) ($eventPayload['provider_code'] ?? $profile['provider_code']),
            'environment' => (string) ($eventPayload['provider_environment'] ?? $profile['environment']),
        ]);
        $provider = app(BillingDispatchProviderRegistry::class)->forCode((string) $effectiveProfile['provider_code']);
        $dispatch = $provider->dispatch(
            $event,
            $eventPayload,
            $effectiveProfile,
            ['simulate_result' => $outcome],
        );

        if ($dispatch['status'] === 'failed') {
            $message = (string) $dispatch['message'];

            DB::table($documentTable)
                ->where('id', $event->aggregate_id)
                ->update([
                    'status' => (string) $dispatch['document_status'],
                    'updated_at' => now(),
                ]);

            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update([
                    'status' => 'failed',
                    'retry_count' => DB::raw('retry_count + 1'),
                    'last_error' => $message,
                    'updated_at' => now(),
                ]);

            DB::table('outbox_attempts')->insert([
                'outbox_event_id' => $event->id,
                'status' => 'failed',
                'provider_code' => $dispatch['provider_code'],
                'provider_environment' => $effectiveProfile['environment'],
                'sunat_ticket' => null,
                'provider_reference' => $dispatch['provider_reference'],
                'error_message' => $message,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->auditDispatch(
                $tenantId,
                $event,
                'billing.outbox.dispatch_failed',
                sprintf('Outbox event %d failed during dispatch.', $event->id),
                ['message' => $message]
            );

            return [
                'event_id' => $event->id,
                'document_id' => $event->aggregate_id,
                'event_type' => $event->event_type,
                'status' => 'failed',
                'provider_code' => $dispatch['provider_code'],
                'provider_environment' => $effectiveProfile['environment'],
                'provider_reference' => $dispatch['provider_reference'],
                'sunat_ticket' => null,
                'http_status' => 503,
                'message' => $message,
            ];
        }

        if ($dispatch['status'] === 'rejected') {
            $message = (string) $dispatch['message'];

            DB::table($documentTable)
                ->where('id', $event->aggregate_id)
                ->update([
                    'status' => (string) $dispatch['document_status'],
                    'rejection_reason' => $message,
                    'updated_at' => now(),
                ]);

            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update([
                    'status' => 'processed',
                    'last_error' => $message,
                    'updated_at' => now(),
                ]);

            DB::table('outbox_attempts')->insert([
                'outbox_event_id' => $event->id,
                'status' => 'rejected',
                'provider_code' => $dispatch['provider_code'],
                'provider_environment' => $effectiveProfile['environment'],
                'sunat_ticket' => null,
                'provider_reference' => $dispatch['provider_reference'],
                'error_message' => $message,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->auditDispatch(
                $tenantId,
                $event,
                'billing.outbox.dispatch_rejected',
                sprintf('Outbox event %d was rejected.', $event->id),
                ['message' => $message]
            );

            return [
                'event_id' => $event->id,
                'document_id' => $event->aggregate_id,
                'event_type' => $event->event_type,
                'status' => 'rejected',
                'provider_code' => $dispatch['provider_code'],
                'provider_environment' => $effectiveProfile['environment'],
                'provider_reference' => $dispatch['provider_reference'],
                'sunat_ticket' => null,
            ];
        }

        $ticket = (string) $dispatch['ticket'];

        DB::table($documentTable)
            ->where('id', $event->aggregate_id)
            ->update([
                'status' => (string) $dispatch['document_status'],
                'sunat_ticket' => $ticket,
                'rejection_reason' => null,
                'updated_at' => now(),
            ]);

        DB::table('outbox_events')
            ->where('id', $event->id)
            ->update([
                'status' => 'processed',
                'last_error' => null,
                'updated_at' => now(),
            ]);

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $event->id,
            'status' => 'accepted',
            'provider_code' => $dispatch['provider_code'],
            'provider_environment' => $effectiveProfile['environment'],
            'sunat_ticket' => $ticket,
            'provider_reference' => $dispatch['provider_reference'],
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->auditDispatch(
            $tenantId,
            $event,
            'billing.outbox.dispatch_processed',
            sprintf('Outbox event %d was processed.', $event->id),
            ['sunat_ticket' => $ticket]
        );

        return [
            'event_id' => $event->id,
            'document_id' => $event->aggregate_id,
            'event_type' => $event->event_type,
            'status' => 'processed',
            'sunat_ticket' => $ticket,
            'provider_code' => $dispatch['provider_code'],
            'provider_environment' => $effectiveProfile['environment'],
            'provider_reference' => $dispatch['provider_reference'],
        ];
    }

    private function auditDispatch(int $tenantId, object $event, string $eventType, string $summary, array $metadata = []): void
    {
        app(TenantActivityLogService::class)->record(
            $tenantId,
            null,
            'billing',
            $eventType,
            'outbox_event',
            (int) $event->id,
            $summary,
            array_merge([
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => (int) $event->aggregate_id,
                'source_event_type' => $event->event_type,
            ], $metadata),
        );
    }

    public function retryFailed(int $tenantId, int $eventId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::transaction(function () use ($tenantId, $eventId) {
            $event = DB::table('outbox_events')
                ->where('id', $eventId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first(['id', 'aggregate_type', 'aggregate_id', 'event_type', 'status', 'retry_count']);

            if ($event === null) {
                throw new HttpException(404, 'Outbox event not found.');
            }

            if ($event->status !== 'failed') {
                throw new HttpException(422, 'Only failed outbox events can be retried.');
            }

            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update([
                    'status' => 'pending',
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            DB::table($event->aggregate_type === 'sale_credit_note' ? 'sale_credit_notes' : 'electronic_vouchers')
                ->where('id', $event->aggregate_id)
                ->update([
                    'status' => 'pending',
                    'updated_at' => now(),
                ]);

            $this->auditDispatch(
                $tenantId,
                $event,
                'billing.outbox.retried',
                sprintf('Outbox event %d was requeued.', $event->id),
                ['retry_count' => (int) $event->retry_count]
            );

            return [
                'event_id' => $event->id,
                'document_id' => $event->aggregate_id,
                'status' => 'pending',
                'retry_count' => $event->retry_count,
            ];
        });
    }
}
