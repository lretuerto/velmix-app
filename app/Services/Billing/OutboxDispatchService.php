<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OutboxDispatchService
{
    public function dispatchNext(int $tenantId, string $outcome = 'accepted'): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if (! in_array($outcome, ['accepted', 'rejected', 'transient_fail'], true)) {
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

            $documentTable = $event->aggregate_type === 'sale_credit_note'
                ? 'sale_credit_notes'
                : 'electronic_vouchers';

            if ($outcome === 'transient_fail') {
                $message = 'Temporary transport failure.';

                DB::table($documentTable)
                    ->where('id', $event->aggregate_id)
                    ->update([
                        'status' => 'failed',
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
                    'sunat_ticket' => null,
                    'error_message' => $message,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'event_id' => $event->id,
                    'document_id' => $event->aggregate_id,
                    'event_type' => $event->event_type,
                    'status' => 'failed',
                    'sunat_ticket' => null,
                    'http_status' => 503,
                    'message' => $message,
                ];
            }

            if ($outcome === 'rejected') {
                $message = 'Rejected by SUNAT validation.';

                DB::table($documentTable)
                    ->where('id', $event->aggregate_id)
                    ->update([
                        'status' => 'rejected',
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
                    'sunat_ticket' => null,
                    'error_message' => $message,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'event_id' => $event->id,
                    'document_id' => $event->aggregate_id,
                    'event_type' => $event->event_type,
                    'status' => 'rejected',
                    'sunat_ticket' => null,
                ];
            }

            $ticket = 'SUNAT-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            DB::table($documentTable)
                ->where('id', $event->aggregate_id)
                ->update([
                    'status' => 'accepted',
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
                'sunat_ticket' => $ticket,
                'error_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'event_id' => $event->id,
                'document_id' => $event->aggregate_id,
                'event_type' => $event->event_type,
                'status' => 'processed',
                'sunat_ticket' => $ticket,
            ];
        });
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
                ->first(['id', 'aggregate_type', 'aggregate_id', 'status', 'retry_count']);

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

            return [
                'event_id' => $event->id,
                'document_id' => $event->aggregate_id,
                'status' => 'pending',
                'retry_count' => $event->retry_count,
            ];
        });
    }
}
