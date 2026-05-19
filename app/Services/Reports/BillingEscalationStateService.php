<?php

namespace App\Services\Reports;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingEscalationStateService
{
    public function listByCode(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('billing_escalation_states')
            ->leftJoin('users as acknowledged_users', 'acknowledged_users.id', '=', 'billing_escalation_states.acknowledged_by_user_id')
            ->leftJoin('users as resolved_users', 'resolved_users.id', '=', 'billing_escalation_states.resolved_by_user_id')
            ->where('billing_escalation_states.tenant_id', $tenantId)
            ->get([
                'billing_escalation_states.id',
                'billing_escalation_states.escalation_code',
                'billing_escalation_states.status',
                'billing_escalation_states.acknowledged_by_user_id',
                'billing_escalation_states.acknowledged_at',
                'billing_escalation_states.acknowledgement_note',
                'billing_escalation_states.resolved_by_user_id',
                'billing_escalation_states.resolved_at',
                'billing_escalation_states.resolution_note',
                'billing_escalation_states.last_seen_at',
                'billing_escalation_states.updated_at',
                'acknowledged_users.name as acknowledged_by_user_name',
                'resolved_users.name as resolved_by_user_name',
            ])
            ->mapWithKeys(fn (object $state) => [
                (string) $state->escalation_code => $this->formatState($state),
            ])
            ->all();
    }

    public function acknowledge(int $tenantId, int $userId, string $code, ?string $note = null): array
    {
        return $this->upsertState(
            tenantId: $tenantId,
            userId: $userId,
            code: $code,
            status: 'acknowledged',
            note: $note,
        );
    }

    public function resolve(int $tenantId, int $userId, string $code, string $note): array
    {
        return $this->upsertState(
            tenantId: $tenantId,
            userId: $userId,
            code: $code,
            status: 'resolved',
            note: $note,
        );
    }

    private function upsertState(int $tenantId, int $userId, string $code, string $status, ?string $note): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $code = trim($code);

        if ($code === '' || ! in_array($code, BillingEscalationReportService::KNOWN_CODES, true)) {
            throw new HttpException(404, 'Billing escalation code not found.');
        }

        if ($status === 'resolved' && trim((string) $note) === '') {
            throw new HttpException(422, 'Resolution note is required.');
        }

        $existingId = DB::table('billing_escalation_states')
            ->where('tenant_id', $tenantId)
            ->where('escalation_code', $code)
            ->value('id');

        if ($existingId === null) {
            $existingId = DB::table('billing_escalation_states')->insertGetId([
                'tenant_id' => $tenantId,
                'escalation_code' => $code,
                'status' => $status,
                'acknowledged_by_user_id' => $status === 'acknowledged' ? $userId : null,
                'acknowledged_at' => $status === 'acknowledged' ? now() : null,
                'acknowledgement_note' => $status === 'acknowledged' ? $note : null,
                'resolved_by_user_id' => $status === 'resolved' ? $userId : null,
                'resolved_at' => $status === 'resolved' ? now() : null,
                'resolution_note' => $status === 'resolved' ? $note : null,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $updates = [
                'status' => $status,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ];

            if ($status === 'acknowledged') {
                $updates['acknowledged_by_user_id'] = $userId;
                $updates['acknowledged_at'] = now();
                $updates['acknowledgement_note'] = $note;
            } else {
                $updates['resolved_by_user_id'] = $userId;
                $updates['resolved_at'] = now();
                $updates['resolution_note'] = $note;
            }

            DB::table('billing_escalation_states')
                ->where('id', $existingId)
                ->update($updates);
        }

        app(TenantActivityLogService::class)->record(
            $tenantId,
            $userId,
            'billing',
            $status === 'acknowledged' ? 'billing.escalation.acknowledged' : 'billing.escalation.resolved',
            'billing_escalation_state',
            (int) $existingId,
            $status === 'acknowledged'
                ? sprintf('Billing escalation %s acknowledged.', $code)
                : sprintf('Billing escalation %s resolved.', $code),
            [
                'escalation_code' => $code,
                'status' => $status,
                'note' => $note,
            ],
        );

        return $this->listByCode($tenantId)[$code];
    }

    private function formatState(object $state): array
    {
        return [
            'id' => (int) $state->id,
            'code' => (string) $state->escalation_code,
            'status' => (string) $state->status,
            'acknowledged_at' => $state->acknowledged_at !== null ? (string) $state->acknowledged_at : null,
            'acknowledgement_note' => $state->acknowledgement_note,
            'acknowledged_by' => $state->acknowledged_by_user_id !== null ? [
                'id' => (int) $state->acknowledged_by_user_id,
                'name' => $state->acknowledged_by_user_name,
            ] : null,
            'resolved_at' => $state->resolved_at !== null ? (string) $state->resolved_at : null,
            'resolution_note' => $state->resolution_note,
            'resolved_by' => $state->resolved_by_user_id !== null ? [
                'id' => (int) $state->resolved_by_user_id,
                'name' => $state->resolved_by_user_name,
            ] : null,
            'last_seen_at' => $state->last_seen_at !== null ? (string) $state->last_seen_at : null,
            'updated_at' => (string) $state->updated_at,
        ];
    }
}
