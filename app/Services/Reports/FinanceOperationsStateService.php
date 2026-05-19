<?php

namespace App\Services\Reports;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceOperationsStateService
{
    private const VALID_KINDS = [
        'receivable' => 'sale_receivables',
        'payable' => 'purchase_payables',
    ];

    public function listByEntity(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('finance_operation_states')
            ->leftJoin('users as acknowledged_users', 'acknowledged_users.id', '=', 'finance_operation_states.acknowledged_by_user_id')
            ->leftJoin('users as resolved_users', 'resolved_users.id', '=', 'finance_operation_states.resolved_by_user_id')
            ->where('finance_operation_states.tenant_id', $tenantId)
            ->get([
                'finance_operation_states.id',
                'finance_operation_states.entity_type',
                'finance_operation_states.entity_id',
                'finance_operation_states.status',
                'finance_operation_states.acknowledged_by_user_id',
                'finance_operation_states.acknowledged_at',
                'finance_operation_states.acknowledgement_note',
                'finance_operation_states.resolved_by_user_id',
                'finance_operation_states.resolved_at',
                'finance_operation_states.resolution_note',
                'finance_operation_states.last_seen_at',
                'finance_operation_states.updated_at',
                'acknowledged_users.name as acknowledged_by_user_name',
                'resolved_users.name as resolved_by_user_name',
            ])
            ->mapWithKeys(fn (object $state) => [
                $this->entityKey((string) $state->entity_type, (int) $state->entity_id) => $this->formatState($state),
            ])
            ->all();
    }

    public function acknowledge(int $tenantId, int $userId, string $kind, int $entityId, ?string $note = null): array
    {
        return $this->upsertState(
            tenantId: $tenantId,
            userId: $userId,
            kind: $kind,
            entityId: $entityId,
            status: 'acknowledged',
            note: $note,
        );
    }

    public function resolve(int $tenantId, int $userId, string $kind, int $entityId, string $note): array
    {
        return $this->upsertState(
            tenantId: $tenantId,
            userId: $userId,
            kind: $kind,
            entityId: $entityId,
            status: 'resolved',
            note: $note,
        );
    }

    public function entityKey(string $kind, int $entityId): string
    {
        return $kind.':'.$entityId;
    }

    private function upsertState(
        int $tenantId,
        int $userId,
        string $kind,
        int $entityId,
        string $status,
        ?string $note,
    ): array {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $kind = trim($kind);

        if (! array_key_exists($kind, self::VALID_KINDS)) {
            throw new HttpException(404, 'Finance operation kind not found.');
        }

        if ($entityId <= 0) {
            throw new HttpException(404, 'Finance operation entity not found.');
        }

        if ($status === 'resolved' && trim((string) $note) === '') {
            throw new HttpException(422, 'Resolution note is required.');
        }

        $entity = DB::table(self::VALID_KINDS[$kind])
            ->where('tenant_id', $tenantId)
            ->where('id', $entityId)
            ->first(['id', 'outstanding_amount']);

        if ($entity === null) {
            throw new HttpException(404, 'Finance operation entity not found.');
        }

        if ((float) $entity->outstanding_amount <= 0) {
            throw new HttpException(422, 'Finance operation is no longer outstanding.');
        }

        $stateId = DB::table('finance_operation_states')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $kind)
            ->where('entity_id', $entityId)
            ->value('id');

        if ($stateId === null) {
            $stateId = DB::table('finance_operation_states')->insertGetId([
                'tenant_id' => $tenantId,
                'entity_type' => $kind,
                'entity_id' => $entityId,
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

            DB::table('finance_operation_states')
                ->where('id', $stateId)
                ->update($updates);
        }

        app(TenantActivityLogService::class)->record(
            $tenantId,
            $userId,
            'finance',
            $status === 'acknowledged' ? 'finance.operation.acknowledged' : 'finance.operation.resolved',
            'finance_operation_state',
            (int) $stateId,
            $status === 'acknowledged'
                ? sprintf('Finance operation %s %d acknowledged.', $kind, $entityId)
                : sprintf('Finance operation %s %d resolved.', $kind, $entityId),
            [
                'entity_type' => $kind,
                'entity_id' => $entityId,
                'status' => $status,
                'note' => $note,
            ],
        );

        return $this->listByEntity($tenantId)[$this->entityKey($kind, $entityId)];
    }

    private function formatState(object $state): array
    {
        return [
            'id' => (int) $state->id,
            'entity_type' => (string) $state->entity_type,
            'entity_id' => (int) $state->entity_id,
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
