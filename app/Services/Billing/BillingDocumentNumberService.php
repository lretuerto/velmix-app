<?php

namespace App\Services\Billing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingDocumentNumberService
{
    public function nextVoucherNumber(int $tenantId, string $series): int
    {
        return $this->nextNumber($tenantId, 'electronic_voucher', 'electronic_vouchers', $series);
    }

    public function nextCreditNoteNumber(int $tenantId, string $series): int
    {
        return $this->nextNumber($tenantId, 'sale_credit_note', 'sale_credit_notes', $series);
    }

    private function nextNumber(int $tenantId, string $documentType, string $sourceTable, string $series): int
    {
        if ($tenantId <= 0 || trim($series) === '') {
            throw new HttpException(403, 'Billing document numbering requires tenant context.');
        }

        $allocate = function () use ($tenantId, $documentType, $sourceTable, $series) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $sequence = DB::table('billing_document_sequences')
                    ->where('tenant_id', $tenantId)
                    ->where('document_type', $documentType)
                    ->where('series', $series)
                    ->lockForUpdate()
                    ->first(['id', 'current_number']);

                if ($sequence === null) {
                    $currentNumber = (int) DB::table($sourceTable)
                        ->where('tenant_id', $tenantId)
                        ->where('series', $series)
                        ->max('number');

                    try {
                        $sequenceId = DB::table('billing_document_sequences')->insertGetId([
                            'tenant_id' => $tenantId,
                            'document_type' => $documentType,
                            'series' => $series,
                            'current_number' => $currentNumber,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        continue;
                    }

                    return $this->advanceSequence($sequenceId, $currentNumber);
                }

                return $this->advanceSequence((int) $sequence->id, (int) $sequence->current_number);
            }

            throw new HttpException(500, 'Unable to allocate billing document number.');
        };

        if (DB::transactionLevel() > 0) {
            return $allocate();
        }

        return DB::transaction($allocate, 5);
    }

    private function advanceSequence(int $sequenceId, int $currentNumber): int
    {
        $nextNumber = $currentNumber + 1;

        DB::table('billing_document_sequences')
            ->where('id', $sequenceId)
            ->update([
                'current_number' => $nextNumber,
                'updated_at' => now(),
            ]);

        return $nextNumber;
    }
}
