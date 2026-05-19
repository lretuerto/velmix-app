<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function nextVoucherNumber(int $tenantId, string $series = 'B001'): int
    {
        return $this->nextBillingDocumentNumber('electronic_vouchers', $tenantId, $series);
    }

    protected function nextCreditNoteNumber(int $tenantId, string $series = 'NC01'): int
    {
        return $this->nextBillingDocumentNumber('sale_credit_notes', $tenantId, $series);
    }

    protected function nextBillingDocumentNumber(string $table, int $tenantId, string $series): int
    {
        return ((int) DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('series', $series)
            ->max('number')) + 1;
    }
}
