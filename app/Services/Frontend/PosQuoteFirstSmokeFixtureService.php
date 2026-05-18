<?php

namespace App\Services\Frontend;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PosQuoteFirstSmokeFixtureService
{
    public function seed(
        int $tenantId,
        string $userEmail,
        string $password,
        bool $openCashSession = true,
        float $openingAmount = 1000.0,
    ): array {
        if ($tenantId <= 0) {
            throw new RuntimeException('A valid tenant id is required.');
        }

        $userEmail = trim($userEmail);

        if ($userEmail === '') {
            throw new RuntimeException('A smoke user email is required.');
        }

        if (trim($password) === '') {
            throw new RuntimeException('A smoke user password is required.');
        }

        if ($openingAmount < 0) {
            throw new RuntimeException('Opening amount must be zero or greater.');
        }

        return DB::transaction(function () use ($tenantId, $userEmail, $password, $openCashSession, $openingAmount) {
            $now = now();
            $tenant = DB::table('tenants')->where('id', $tenantId)->first(['id', 'code', 'name', 'status']);

            if ($tenant === null) {
                throw new RuntimeException(sprintf('Tenant %d does not exist. Run the tenant seed first or pass an existing --tenant.', $tenantId));
            }

            $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

            if ($cashierRoleId === null) {
                throw new RuntimeException('Role CAJERO does not exist. Run RbacCatalogSeeder before creating the smoke fixture.');
            }

            $laboratoryId = $this->upsertLaboratorySupplier($tenantId);
            $regularProductId = $this->upsertProduct(
                $tenantId,
                $laboratoryId,
                'SMOKE-POS-REG-001',
                'Smoke POS Paracetamol Quote-first',
                false,
                4.00,
            );
            $controlledProductId = $this->upsertProduct(
                $tenantId,
                $laboratoryId,
                'SMOKE-POS-RX-001',
                'Smoke POS Producto Controlado RX',
                true,
                6.50,
            );

            $this->upsertLot($tenantId, $regularProductId, 'LOT-SMOKE-POS-REG-001', 80);
            $this->upsertLot($tenantId, $controlledProductId, 'LOT-SMOKE-POS-RX-001', 40);

            $priceList = $this->resolveOrCreateSmokePriceList($tenantId);
            $this->upsertPriceListItem((int) $priceList['id'], $regularProductId, 12.00, 8.00);
            $this->upsertPriceListItem((int) $priceList['id'], $controlledProductId, 18.00, 12.00);
            $priceCoverage = $this->ensurePriceCoverageForActiveProducts($tenantId, (int) $priceList['id']);

            $promotionId = $this->upsertPromotion($tenantId, $laboratoryId, $regularProductId);
            $customerId = $this->upsertCustomer($tenantId);
            $this->upsertCustomerPriceListAssignment($tenantId, $customerId, (int) $priceList['id']);
            $userId = $this->upsertSmokeUser($tenantId, $userEmail, $password, (int) $cashierRoleId);
            $cashSession = $openCashSession
                ? $this->ensureOpenCashSession($tenantId, $userId, $openingAmount)
                : null;

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'frontend',
                'frontend.pos_smoke_fixture.seeded',
                'tenant',
                $tenantId,
                'Fixture POS quote-first preparado',
                [
                    'regular_product_id' => $regularProductId,
                    'controlled_product_id' => $controlledProductId,
                    'price_list_id' => $priceList['id'],
                    'price_list_source' => $priceList['source'],
                    'price_coverage' => $priceCoverage,
                    'promotion_id' => $promotionId,
                    'customer_id' => $customerId,
                    'cash_session_id' => $cashSession['id'] ?? null,
                ],
                $now->toISOString(),
            );

            return [
                'status' => 'seeded',
                'tenant' => [
                    'id' => (int) $tenant->id,
                    'code' => $tenant->code,
                    'name' => $tenant->name,
                    'status' => $tenant->status,
                ],
                'operator' => [
                    'id' => $userId,
                    'email' => $userEmail,
                    'role' => 'CAJERO',
                ],
                'products' => [
                    [
                        'id' => $regularProductId,
                        'sku' => 'SMOKE-POS-REG-001',
                        'name' => 'Smoke POS Paracetamol Quote-first',
                        'is_controlled' => false,
                        'lot_code' => 'LOT-SMOKE-POS-REG-001',
                    ],
                    [
                        'id' => $controlledProductId,
                        'sku' => 'SMOKE-POS-RX-001',
                        'name' => 'Smoke POS Producto Controlado RX',
                        'is_controlled' => true,
                        'lot_code' => 'LOT-SMOKE-POS-RX-001',
                    ],
                ],
                'pricing' => [
                    'price_list_id' => (int) $priceList['id'],
                    'price_list_code' => $priceList['code'],
                    'price_list_source' => $priceList['source'],
                    'price_coverage' => $priceCoverage,
                    'promotion_id' => $promotionId,
                    'promotion_code' => 'SMOKE-PROMO10',
                ],
                'customer' => [
                    'id' => $customerId,
                    'document_type' => 'RUC',
                    'document_number' => '20999999001',
                    'name' => 'Smoke Farmacia UAT',
                ],
                'cash_session' => $cashSession,
                'recommended_paths' => [
                    '/pos/sales',
                    '/cash/sessions',
                    '/sales/receivables',
                ],
            ];
        });
    }

    private function upsertLaboratorySupplier(int $tenantId): int
    {
        DB::table('suppliers')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'tax_id' => '20999999000',
            ],
            [
                'name' => 'Smoke Laboratorio Norte',
                'kind' => 'laboratory',
                'commercial_code' => 'LAB-SMOKE-QF',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('suppliers')
            ->where('tenant_id', $tenantId)
            ->where('tax_id', '20999999000')
            ->value('id');
    }

    private function upsertProduct(
        int $tenantId,
        int $laboratoryId,
        string $sku,
        string $name,
        bool $isControlled,
        float $averageCost,
    ): int {
        DB::table('products')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'sku' => $sku,
            ],
            [
                'name' => $name,
                'status' => 'active',
                'is_controlled' => $isControlled,
                'last_cost' => $averageCost,
                'average_cost' => $averageCost,
                'laboratory_supplier_id' => $laboratoryId,
                'commercial_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->value('id');
    }

    private function upsertLot(int $tenantId, int $productId, string $code, int $stockQuantity): void
    {
        DB::table('lots')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'code' => $code,
            ],
            [
                'product_id' => $productId,
                'expires_at' => now()->addYears(2)->toDateString(),
                'stock_quantity' => $stockQuantity,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function resolveOrCreateSmokePriceList(int $tenantId): array
    {
        $existingDefault = DB::table('price_lists')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('channel', 'retail')
            ->where('is_default', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first(['id', 'code']);

        if ($existingDefault !== null) {
            return [
                'id' => (int) $existingDefault->id,
                'code' => $existingDefault->code,
                'source' => 'existing_default',
            ];
        }

        DB::table('price_lists')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'code' => 'SMOKE-RETAIL',
            ],
            [
                'name' => 'Smoke Retail Quote-first',
                'status' => 'active',
                'channel' => 'retail',
                'currency' => 'PEN',
                'is_default' => true,
                'priority' => 10,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return [
            'id' => (int) DB::table('price_lists')
                ->where('tenant_id', $tenantId)
                ->where('code', 'SMOKE-RETAIL')
                ->value('id'),
            'code' => 'SMOKE-RETAIL',
            'source' => 'created_default',
        ];
    }

    private function upsertPriceListItem(int $priceListId, int $productId, float $unitPrice, float $minUnitPrice): void
    {
        DB::table('price_list_items')->updateOrInsert(
            [
                'price_list_id' => $priceListId,
                'product_id' => $productId,
            ],
            [
                'unit_price' => $unitPrice,
                'min_unit_price' => $minUnitPrice,
                'max_discount_pct' => 50,
                'valid_from' => now()->subDay(),
                'valid_until' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, int>
     */
    private function ensurePriceCoverageForActiveProducts(int $tenantId, int $priceListId): array
    {
        $now = now();

        $existingProductIds = DB::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('status', 'active')
            ->where('valid_from', '<=', $now)
            ->where(fn ($query) => $query
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', $now))
            ->pluck('product_id')
            ->map(fn ($productId) => (int) $productId)
            ->all();

        $missingProducts = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotIn('id', $existingProductIds)
            ->get(['id', 'last_cost', 'average_cost']);

        foreach ($missingProducts as $product) {
            $cost = max((float) $product->average_cost, (float) $product->last_cost, 1.0);
            $unitPrice = round(max($cost * 2, $cost + 1), 2);
            $minUnitPrice = round(max($cost * 1.2, 0.01), 2);

            $this->upsertPriceListItem(
                $priceListId,
                (int) $product->id,
                $unitPrice,
                min($minUnitPrice, $unitPrice),
            );
        }

        return [
            'priced_product_count' => count($existingProductIds) + $missingProducts->count(),
            'added_missing_price_count' => $missingProducts->count(),
        ];
    }

    private function upsertPromotion(int $tenantId, int $laboratoryId, int $productId): int
    {
        DB::table('promotions')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'code' => 'SMOKE-PROMO10',
            ],
            [
                'name' => 'Smoke 10% laboratorio',
                'description' => 'Promocion controlada para smoke POS quote-first.',
                'status' => 'active',
                'sponsor_supplier_id' => $laboratoryId,
                'channel' => 'retail',
                'priority' => 20,
                'stack_mode' => 'best_price_only',
                'stop_further_processing' => false,
                'requires_customer' => false,
                'allowed_payment_methods' => json_encode(['cash', 'card', 'transfer', 'credit']),
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addYear(),
                'budget_cap' => null,
                'budget_used' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $promotionId = (int) DB::table('promotions')
            ->where('tenant_id', $tenantId)
            ->where('code', 'SMOKE-PROMO10')
            ->value('id');

        DB::table('promotion_targets')->where('promotion_id', $promotionId)->delete();
        DB::table('promotion_audiences')->where('promotion_id', $promotionId)->delete();
        DB::table('promotion_rules')->where('promotion_id', $promotionId)->delete();

        DB::table('promotion_targets')->insert([
            'promotion_id' => $promotionId,
            'target_type' => 'product',
            'target_id' => $productId,
            'exclude' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('promotion_audiences')->insert([
            'promotion_id' => $promotionId,
            'audience_type' => 'all',
            'audience_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('promotion_rules')->insert([
            'promotion_id' => $promotionId,
            'rule_type' => 'percent_off',
            'scope' => 'line',
            'config' => json_encode([
                'percent_off' => 10,
                'min_quantity' => 1,
            ]),
            'priority' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $promotionId;
    }

    private function upsertCustomer(int $tenantId): int
    {
        DB::table('customers')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'document_type' => 'RUC',
                'document_number' => '20999999001',
            ],
            [
                'name' => 'Smoke Farmacia UAT',
                'phone' => '+51 999 999 001',
                'email' => 'smoke-farmacia@velmix.test',
                'credit_limit' => 2500,
                'credit_days' => 15,
                'block_on_overdue' => true,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('document_type', 'RUC')
            ->where('document_number', '20999999001')
            ->value('id');
    }

    private function upsertCustomerPriceListAssignment(int $tenantId, int $customerId, int $priceListId): void
    {
        $existingId = DB::table('customer_price_list_assignments')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('price_list_id', $priceListId)
            ->value('id');

        if ($existingId !== null) {
            DB::table('customer_price_list_assignments')
                ->where('id', $existingId)
                ->update([
                    'priority' => 1,
                    'starts_at' => now()->subDay(),
                    'ends_at' => null,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('customer_price_list_assignments')->insert([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'price_list_id' => $priceListId,
            'priority' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function upsertSmokeUser(int $tenantId, string $email, string $password, int $roleId): int
    {
        DB::table('users')->updateOrInsert(
            ['email' => $email],
            [
                'name' => 'POS Smoke Operator',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $userId = (int) DB::table('users')->where('email', $email)->value('id');

        DB::table('tenant_user')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('tenant_user_role')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $userId;
    }

    private function ensureOpenCashSession(int $tenantId, int $userId, float $openingAmount): array
    {
        $existing = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first(['id', 'opening_amount', 'expected_amount', 'opened_at']);

        if ($existing !== null) {
            return [
                'id' => (int) $existing->id,
                'status' => 'already_open',
                'opening_amount' => round((float) $existing->opening_amount, 2),
                'expected_amount' => round((float) $existing->expected_amount, 2),
                'opened_at' => $existing->opened_at,
            ];
        }

        $sessionId = DB::table('cash_sessions')->insertGetId([
            'tenant_id' => $tenantId,
            'opened_by_user_id' => $userId,
            'closed_by_user_id' => null,
            'opening_amount' => $openingAmount,
            'expected_amount' => $openingAmount,
            'counted_amount' => null,
            'discrepancy_amount' => null,
            'status' => 'open',
            'open_guard' => sprintf('tenant:%d', $tenantId),
            'opened_at' => now(),
            'closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $sessionId,
            'status' => 'opened',
            'opening_amount' => round($openingAmount, 2),
            'expected_amount' => round($openingAmount, 2),
            'opened_at' => now()->toISOString(),
        ];
    }
}
