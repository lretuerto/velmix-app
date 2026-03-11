<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OutboxDispatchService
{
    public function dispatchNext(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::transaction(function () use ($tenantId) {
            $event = DB::table('outbox_events')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id', 'aggregate_id', 'event_type']);

            if ($event === null) {
                throw new HttpException(404, 'No pending outbox events.');
            }

            $ticket = 'SUNAT-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('electronic_vouchers')
                ->where('id', $event->aggregate_id)
                ->update([
                    'status' => 'accepted',
                    'sunat_ticket' => $ticket,
                    'updated_at' => now(),
                ]);

            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update([
                    'status' => 'processed',
                    'updated_at' => now(),
                ]);

            return [
                'event_id' => $event->id,
                'voucher_id' => $event->aggregate_id,
                'event_type' => $event->event_type,
                'status' => 'processed',
                'sunat_ticket' => $ticket,
            ];
        });
    }
}
