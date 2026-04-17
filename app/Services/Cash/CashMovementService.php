<?php

namespace App\Services\Cash;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CashMovementService
{
    public function create(
        int $tenantId,
        int $userId,
        string $type,
        float $amount,
        string $reference,
        ?string $notes = null
    ): array {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if (! in_array($type, ['manual_in', 'manual_out'], true)) {
            throw new HttpException(422, 'Cash movement type is invalid.');
        }

        if ($amount <= 0) {
            throw new HttpException(422, 'Cash movement amount must be valid.');
        }

        if (trim($reference) === '') {
            throw new HttpException(422, 'Cash movement reference is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $type, $amount, $reference, $notes) {
            $session = DB::table('cash_sessions')
                ->where('tenant_id', $tenantId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                throw new HttpException(404, 'No open cash session.');
            }

            $summary = (new CashSessionService())->current($tenantId);

            if ($type === 'manual_out' && $amount > (float) $summary['expected_amount']) {
                throw new HttpException(422, 'Cash movement exceeds available cash in session.');
            }

            $createdAt = now();
            $movementId = DB::table('cash_movements')->insertGetId([
                'tenant_id' => $tenantId,
                'cash_session_id' => $session->id,
                'created_by_user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'reference' => $reference,
                'notes' => $notes,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'cash',
                'cash.movement.created',
                'cash_movement',
                $movementId,
                'Movimiento de caja '.$type.' registrado',
                [
                    'cash_movement_id' => $movementId,
                    'cash_session_id' => $session->id,
                    'type' => $type,
                    'amount' => round($amount, 2),
                    'reference' => $reference,
                ],
                $createdAt->toISOString(),
            );

            return [
                'id' => $movementId,
                'tenant_id' => $tenantId,
                'cash_session_id' => $session->id,
                'type' => $type,
                'amount' => round($amount, 2),
                'reference' => $reference,
                'notes' => $notes,
                'created_at' => $createdAt->toISOString(),
            ];
        });
    }

    public function listForSession(int $tenantId, int $sessionId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $sessionExists = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $sessionId)
            ->exists();

        if (! $sessionExists) {
            throw new HttpException(404, 'Cash session not found.');
        }

        return DB::table('cash_movements')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $sessionId)
            ->orderBy('id')
            ->get([
                'id',
                'tenant_id',
                'cash_session_id',
                'created_by_user_id',
                'type',
                'amount',
                'reference',
                'notes',
                'created_at',
            ])
            ->map(fn (object $movement) => [
                'id' => $movement->id,
                'tenant_id' => $movement->tenant_id,
                'cash_session_id' => $movement->cash_session_id,
                'created_by_user_id' => $movement->created_by_user_id,
                'type' => $movement->type,
                'amount' => (float) $movement->amount,
                'reference' => $movement->reference,
                'notes' => $movement->notes,
                'created_at' => $movement->created_at,
            ])
            ->all();
    }
}
