<?php

namespace App\Services\Frontend;

use App\Services\Pricing\PricingCheckoutService;
use App\Services\Pricing\PricingQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PosQuoteFirstUatSmokeService
{
    public function __construct(
        private readonly FrontendUatReadinessService $readinessService,
        private readonly PricingQuoteService $pricingQuoteService,
        private readonly PricingCheckoutService $pricingCheckoutService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(int $tenantId, string $userEmail): array
    {
        if ($tenantId <= 0) {
            throw new RuntimeException('A valid tenant id is required.');
        }

        $readiness = $this->readinessService->summary($tenantId, $userEmail);

        if (($readiness['status'] ?? 'blocked') !== 'ready') {
            return $this->persistEvidence([
                'status' => 'blocked',
                'checked_at' => now()->toISOString(),
                'tenant' => $readiness['tenant'] ?? null,
                'operator' => $readiness['operator'] ?? null,
                'reason' => 'Frontend UAT readiness is not ready.',
                'items' => $readiness['items'] ?? [],
                'readiness' => $readiness,
            ]);
        }

        $operator = $readiness['operator'] ?? null;
        $userId = isset($operator['id']) ? (int) $operator['id'] : 0;

        if ($userId <= 0) {
            throw new RuntimeException('A valid UAT operator is required.');
        }

        $regularProduct = $this->productBySku($tenantId, 'SMOKE-POS-REG-001');
        $controlledProduct = $this->productBySku($tenantId, 'SMOKE-POS-RX-001');
        $customerId = (int) ($readiness['fixture']['customer_id'] ?? 0);
        $cashSessionId = (int) ($readiness['fixture']['cash_session_id'] ?? 0);

        if ($customerId <= 0 || $cashSessionId <= 0) {
            throw new RuntimeException('Smoke customer and open cash session are required for the full UAT smoke.');
        }

        $before = $this->snapshot($tenantId, (int) $regularProduct->id, (int) $controlledProduct->id, $cashSessionId);
        $scenarios = [
            'card_regular_quote_checkout' => $this->runRegularCardScenario($tenantId, $userId, (int) $regularProduct->id),
            'cash_regular_cash_ledger' => $this->runRegularCashScenario($tenantId, $userId, (int) $regularProduct->id, $cashSessionId),
            'credit_customer_receivable' => $this->runCustomerCreditScenario($tenantId, $userId, (int) $regularProduct->id, $customerId),
            'controlled_product_prescription' => $this->runControlledProductScenario($tenantId, $userId, (int) $controlledProduct->id),
        ];
        $after = $this->snapshot($tenantId, (int) $regularProduct->id, (int) $controlledProduct->id, $cashSessionId);

        $result = [
            'status' => 'passed',
            'checked_at' => now()->toISOString(),
            'tenant' => $readiness['tenant'],
            'operator' => $operator,
            'frontend_paths' => [
                'login' => $readiness['artifacts']['login_path'] ?? '/app/login?redirect=/pos/sales',
                'pos' => $readiness['modules']['pos']['frontend_path'] ?? '/app/pos/sales',
                'cash' => $readiness['modules']['cash']['frontend_path'] ?? '/app/cash/sessions',
                'receivables' => $readiness['modules']['receivables']['frontend_path'] ?? '/app/sales/receivables',
                'catalog' => $readiness['modules']['catalog']['frontend_path'] ?? '/app/inventory/products',
                'customers' => $readiness['modules']['customers']['frontend_path'] ?? '/app/sales/customers',
            ],
            'scenarios' => $scenarios,
            'snapshots' => [
                'before' => $before,
                'after' => $after,
                'delta' => [
                    'sales_count' => $after['sales_count'] - $before['sales_count'],
                    'regular_stock' => $after['regular_stock'] - $before['regular_stock'],
                    'controlled_stock' => $after['controlled_stock'] - $before['controlled_stock'],
                    'cash_expected_amount' => round($after['cash_expected_amount'] - $before['cash_expected_amount'], 2),
                    'receivables_count' => $after['receivables_count'] - $before['receivables_count'],
                ],
            ],
            'signoff' => [
                'status' => 'pending_visual_review',
                'reason' => 'Automated transactional smoke passed; browser visual walkthrough still requires human/UAT confirmation.',
                'checklist' => 'docs/frontend/uat-signoff-checklist.md',
                'runbook' => 'docs/frontend/pos-quote-first-smoke-runbook.md',
            ],
        ];

        $this->assertSame(4, $result['snapshots']['delta']['sales_count'], 'The full POS smoke must create exactly four sales.');
        $this->assertSame(-4, $result['snapshots']['delta']['regular_stock'], 'Regular product stock delta must match card, cash, and credit smoke sales.');
        $this->assertSame(-1, $result['snapshots']['delta']['controlled_stock'], 'Controlled product stock delta must match the prescription smoke sale.');
        $this->assertGreaterThan(0, $result['snapshots']['delta']['cash_expected_amount'], 'Cash smoke must increase the open cash session expected amount.');
        $this->assertSame(1, $result['snapshots']['delta']['receivables_count'], 'Credit smoke must create exactly one receivable.');

        return $this->persistEvidence($result);
    }

    private function runRegularCardScenario(int $tenantId, int $userId, int $productId): array
    {
        $quote = $this->pricingQuoteService->create(
            $tenantId,
            $userId,
            [['product_id' => $productId, 'quantity' => 2]],
            'card',
        );
        $checkout = $this->pricingCheckoutService->execute($tenantId, $userId, (int) $quote['id'], (string) $quote['quote_hash']);
        $promotionCodes = collect($quote['applied_promotions'] ?? [])->pluck('code')->values()->all();

        $this->assertContains('SMOKE-PROMO10', $promotionCodes, 'Regular card quote must apply the smoke promotion.');
        $this->assertSame('consumed', $checkout['quote']['status'] ?? null, 'Regular card quote must be consumed.');
        $this->assertSame('card', $checkout['sale']['payment_method'] ?? null, 'Regular card sale must preserve payment method.');

        return $this->scenarioResult('passed', $quote, $checkout, [
            'promotion_codes' => $promotionCodes,
        ]);
    }

    private function runRegularCashScenario(int $tenantId, int $userId, int $productId, int $cashSessionId): array
    {
        $cashExpectedBefore = $this->cashExpectedAmount($tenantId, $cashSessionId);
        $quote = $this->pricingQuoteService->create(
            $tenantId,
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            'cash',
        );
        $checkout = $this->pricingCheckoutService->execute($tenantId, $userId, (int) $quote['id'], (string) $quote['quote_hash']);
        $saleId = (int) ($checkout['sale']['sale_id'] ?? 0);
        $saleTotal = round((float) ($checkout['sale']['total_amount'] ?? 0), 2);
        $cashExpectedAfter = $this->cashExpectedAmount($tenantId, $cashSessionId);
        $ledgerId = DB::table('cash_session_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $cashSessionId)
            ->where('source_type', 'sale')
            ->where('source_id', $saleId)
            ->where('entry_type', 'sale_cash_in')
            ->value('id');

        $this->assertSame(round($cashExpectedBefore + $saleTotal, 2), $cashExpectedAfter, 'Cash expected amount must increase by the cash sale total.');
        $this->assertGreaterThan(0, (int) $ledgerId, 'Cash smoke must persist a sale_cash_in ledger entry.');

        return $this->scenarioResult('passed', $quote, $checkout, [
            'cash_session_id' => $cashSessionId,
            'cash_expected_before' => $cashExpectedBefore,
            'cash_expected_after' => $cashExpectedAfter,
            'ledger_entry_id' => (int) $ledgerId,
        ]);
    }

    private function runCustomerCreditScenario(int $tenantId, int $userId, int $productId, int $customerId): array
    {
        $quote = $this->pricingQuoteService->create(
            $tenantId,
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            'credit',
            $customerId,
        );
        $checkout = $this->pricingCheckoutService->execute(
            $tenantId,
            $userId,
            (int) $quote['id'],
            (string) $quote['quote_hash'],
            [],
            now()->addDays(7)->toDateString(),
        );
        $receivableId = (int) ($checkout['sale']['receivable']['id'] ?? 0);

        $this->assertGreaterThan(0, $receivableId, 'Credit smoke must create a receivable.');
        $this->assertSame('credit', $checkout['sale']['payment_method'] ?? null, 'Credit smoke must preserve payment method.');

        return $this->scenarioResult('passed', $quote, $checkout, [
            'customer_id' => $customerId,
            'receivable_id' => $receivableId,
        ]);
    }

    private function runControlledProductScenario(int $tenantId, int $userId, int $productId): array
    {
        $quote = $this->pricingQuoteService->create(
            $tenantId,
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            'card',
        );
        $quoteItemId = (int) ($quote['items'][0]['id'] ?? 0);
        $prescriptionCode = 'RX-UAT-'.now()->format('YmdHis');
        $checkout = $this->pricingCheckoutService->execute(
            $tenantId,
            $userId,
            (int) $quote['id'],
            (string) $quote['quote_hash'],
            [[
                'quote_item_id' => $quoteItemId,
                'prescription_code' => $prescriptionCode,
            ]],
        );

        $this->assertSame($prescriptionCode, $checkout['sale']['items'][0]['prescription_code'] ?? null, 'Controlled product smoke must persist prescription evidence.');

        return $this->scenarioResult('passed', $quote, $checkout, [
            'prescription_code' => $prescriptionCode,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scenarioResult(string $status, array $quote, array $checkout, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'quote' => [
                'id' => (int) $quote['id'],
                'status' => $checkout['quote']['status'] ?? $quote['status'],
                'hash_present' => trim((string) ($quote['quote_hash'] ?? '')) !== '',
                'expires_at' => $quote['expires_at'] ?? null,
                'total_amount' => round((float) ($quote['summary']['total_amount'] ?? 0), 2),
                'discount_amount' => round((float) ($quote['summary']['discount_amount'] ?? 0), 2),
            ],
            'sale' => [
                'id' => (int) ($checkout['sale']['sale_id'] ?? 0),
                'reference' => $checkout['sale']['reference'] ?? null,
                'payment_method' => $checkout['sale']['payment_method'] ?? null,
                'total_amount' => round((float) ($checkout['sale']['total_amount'] ?? 0), 2),
                'item_count' => count($checkout['sale']['items'] ?? []),
            ],
        ], $extra);
    }

    private function productBySku(int $tenantId, string $sku): object
    {
        $product = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->first(['id', 'sku', 'name']);

        if ($product === null) {
            throw new RuntimeException(sprintf('Smoke product %s is missing.', $sku));
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $tenantId, int $regularProductId, int $controlledProductId, int $cashSessionId): array
    {
        return [
            'sales_count' => DB::table('sales')->where('tenant_id', $tenantId)->count(),
            'regular_stock' => $this->stockForProduct($tenantId, $regularProductId),
            'controlled_stock' => $this->stockForProduct($tenantId, $controlledProductId),
            'cash_expected_amount' => $this->cashExpectedAmount($tenantId, $cashSessionId),
            'receivables_count' => DB::table('sale_receivables')->where('tenant_id', $tenantId)->count(),
        ];
    }

    private function stockForProduct(int $tenantId, int $productId): int
    {
        return (int) DB::table('lots')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->sum('stock_quantity');
    }

    private function cashExpectedAmount(int $tenantId, int $cashSessionId): float
    {
        $openingAmount = round((float) DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $cashSessionId)
            ->value('opening_amount'), 2);

        $ledger = DB::table('cash_session_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $cashSessionId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as in_total,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as out_total
            ")
            ->first();

        return round($openingAmount + (float) ($ledger->in_total ?? 0) - (float) ($ledger->out_total ?? 0), 2);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function persistEvidence(array $result): array
    {
        $directory = FrontendUatArtifactPaths::baseDirectory();
        File::ensureDirectoryExists($directory);

        $historyPath = $directory.'/pos-quote-first-smoke-'.now()->format('Ymd-His').'.json';
        $latestPath = $directory.'/pos-quote-first-smoke-latest.json';
        $result['artifacts'] = array_merge($result['artifacts'] ?? [], [
            'evidence_path' => $historyPath,
            'latest_evidence_path' => $latestPath,
        ]);
        $payload = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Unable to encode POS UAT smoke evidence.');
        }

        File::put($historyPath, $payload.PHP_EOL);
        File::put($latestPath, $payload.PHP_EOL);

        return $result;
    }

    private function assertSame(int|float|string|null $expected, mixed $actual, string $message): void
    {
        if ($expected === $actual) {
            return;
        }

        throw new RuntimeException($message);
    }

    private function assertGreaterThan(int|float $minimum, int|float $actual, string $message): void
    {
        if ($actual > $minimum) {
            return;
        }

        throw new RuntimeException($message);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function assertContains(string $needle, array $values, string $message): void
    {
        if (in_array($needle, $values, true)) {
            return;
        }

        throw new RuntimeException($message);
    }
}
