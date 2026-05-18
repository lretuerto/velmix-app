<?php

namespace App\Services\Frontend;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class FrontendUatReadinessService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, string $userEmail): array
    {
        $userEmail = trim($userEmail);
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['id', 'code', 'name', 'status']);
        $operator = $userEmail !== ''
            ? DB::table('users')->where('email', $userEmail)->first(['id', 'name', 'email'])
            : null;
        $operatorId = $operator !== null ? (int) $operator->id : null;
        $permissions = $operatorId !== null ? $this->permissionsForUser($tenantId, $operatorId) : [];
        $roles = $operatorId !== null ? $this->rolesForUser($tenantId, $operatorId) : [];
        $fixture = $this->fixtureState($tenantId);
        $tenantSelector = $tenant !== null ? (string) $tenant->code : (string) $tenantId;

        $modules = [
            'session' => $this->module('Sesion web', [
                $this->check('tenant.active', $tenant !== null && $tenant->status === 'active', 'Tenant UAT activo para iniciar sesion.'),
                $this->check('operator.exists', $operator !== null, 'Operador smoke existe para login UAT.'),
                $this->check('operator.member', $operatorId !== null && $this->isMember($tenantId, $operatorId), 'Operador pertenece al tenant antes de entrar al shell.'),
                $this->routeCheck('GET', 'app/{any?}'),
                $this->routeCheck('POST', 'auth/session/login'),
                $this->routeCheck('POST', 'auth/session/logout'),
                $this->check('checklist.login_present', $this->documentContains('docs/frontend/uat-signoff-checklist.md', '/app/login'), 'Checklist UAT incluye evidencia de login web.'),
            ], '/app/login?tenant='.$tenantSelector.'&redirect=/pos/sales'),
            'pos' => $this->module('POS', [
                $this->check('tenant.active', $tenant !== null && $tenant->status === 'active', 'Tenant UAT activo.'),
                $this->check('operator.exists', $operator !== null, 'Operador smoke existe.'),
                $this->check('operator.member', $operatorId !== null && $this->isMember($tenantId, $operatorId), 'Operador pertenece al tenant.'),
                $this->permissionsCheck($permissions, [
                    'pos.sale.read',
                    'pos.sale.execute',
                    'pricing.quote.create',
                    'pricing.quote.read',
                    'inventory.product.read',
                    'sales.customer.read',
                ]),
                $this->routeCheck('GET', 'app/{any?}'),
                $this->routeCheck('POST', 'pricing/quotes'),
                $this->routeCheck('GET', 'pricing/quotes/{quote}'),
                $this->routeCheck('POST', 'pricing/quotes/{quote}/checkout'),
                $this->routeCheck('GET', 'pos/sales'),
                $this->check('product.regular.ready', $fixture['regular_product_ready'], 'Producto regular smoke activo, con lote disponible y precio.'),
                $this->check('product.controlled.ready', $fixture['controlled_product_ready'], 'Producto controlado smoke activo, con lote disponible y precio.'),
                $this->check('promotion.ready', $fixture['promotion_ready'], 'Promocion smoke activa, con target, audiencia y regla.'),
                $this->check('runbook.present', is_file(base_path('docs/frontend/pos-quote-first-smoke-runbook.md')), 'Runbook visual POS versionado.'),
                $this->check('checklist.present', is_file(base_path('docs/frontend/uat-signoff-checklist.md')), 'Checklist UAT firmable versionado.'),
                $this->check('smoke_command.documented', $this->documentContains('docs/frontend/pos-quote-first-smoke-runbook.md', 'frontend:pos-quote-first-uat-smoke'), 'Smoke transaccional POS quote-first documentado.'),
            ], '/app/pos/sales?tenant='.$tenantSelector),
            'cash' => $this->module('Caja', [
                $this->permissionsCheck($permissions, [
                    'cash.session.read',
                    'cash.session.open',
                    'cash.session.close',
                    'cash.movement.read',
                ]),
                $this->routeCheck('GET', 'cash/sessions'),
                $this->routeCheck('GET', 'cash/sessions/current'),
                $this->routeCheck('POST', 'cash/sessions/open'),
                $this->routeCheck('GET', 'cash/sessions/{session}/ledger'),
                $this->check('cash.open_session', $fixture['cash_session_ready'], 'Caja abierta disponible para smoke cash.'),
            ], '/app/cash/sessions?tenant='.$tenantSelector),
            'receivables' => $this->module('Cartera', [
                $this->permissionsCheck($permissions, [
                    'sales.receivable.read',
                    'sales.receivable.pay',
                    'sales.receivable.follow-up.create',
                ]),
                $this->routeCheck('GET', 'sales/receivables'),
                $this->routeCheck('GET', 'sales/receivables/aging'),
                $this->routeCheck('POST', 'sales/receivables/{receivable}/payments'),
                $this->routeCheck('POST', 'sales/receivables/{receivable}/follow-ups'),
                $this->check('customer.credit_ready', $fixture['customer_ready'], 'Cliente smoke activo con cupo para venta credit.'),
            ], '/app/sales/receivables?tenant='.$tenantSelector),
            'catalog' => $this->module('Catalogo', [
                $this->permissionsCheck($permissions, [
                    'inventory.product.read',
                ]),
                $this->routeCheck('GET', 'inventory/products'),
                $this->routeCheck('POST', 'inventory/products'),
                $this->check('product.regular.present', $fixture['regular_product_present'], 'Producto regular smoke existe.'),
                $this->check('product.controlled.present', $fixture['controlled_product_present'], 'Producto controlado smoke existe.'),
            ], '/app/inventory/products?tenant='.$tenantSelector),
            'customers' => $this->module('Clientes', [
                $this->permissionsCheck($permissions, [
                    'sales.customer.read',
                    'sales.customer.create',
                    'sales.customer.update',
                ]),
                $this->routeCheck('GET', 'sales/customers'),
                $this->routeCheck('POST', 'sales/customers'),
                $this->routeCheck('PATCH', 'sales/customers/{customer}'),
                $this->routeCheck('GET', 'sales/customers/{customer}/statement'),
                $this->check('customer.ready', $fixture['customer_ready'], 'Cliente smoke activo y utilizable.'),
            ], '/app/sales/customers?tenant='.$tenantSelector),
        ];

        $items = $this->blockedItems($modules);

        return [
            'status' => $items === [] ? 'ready' : 'blocked',
            'checked_at' => now()->toISOString(),
            'tenant' => $tenant !== null ? [
                'id' => (int) $tenant->id,
                'code' => (string) $tenant->code,
                'name' => (string) $tenant->name,
                'status' => (string) $tenant->status,
            ] : null,
            'operator' => $operator !== null ? [
                'id' => (int) $operator->id,
                'email' => (string) $operator->email,
                'roles' => $roles,
            ] : null,
            'modules' => $modules,
            'fixture' => $fixture['summary'],
            'items' => $items,
            'artifacts' => [
                'seed_command' => 'php artisan frontend:seed-pos-smoke --json',
                'readiness_command' => 'php artisan frontend:uat-readiness --json',
                'pos_smoke_command' => 'php artisan frontend:pos-quote-first-uat-smoke --json',
                'login_path' => '/app/login?tenant='.$tenantSelector.'&redirect=/pos/sales',
                'runbook' => 'docs/frontend/pos-quote-first-smoke-runbook.md',
                'signoff_checklist' => 'docs/frontend/uat-signoff-checklist.md',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function permissionsForUser(int $tenantId, int $userId): array
    {
        return DB::table('tenant_user_role')
            ->join('role_permission', 'role_permission.role_id', '=', 'tenant_user_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('tenant_user_role.tenant_id', $tenantId)
            ->where('tenant_user_role.user_id', $userId)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->map(fn (string $code): string => $code)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function rolesForUser(int $tenantId, int $userId): array
    {
        return DB::table('tenant_user_role')
            ->join('roles', 'roles.id', '=', 'tenant_user_role.role_id')
            ->where('tenant_user_role.tenant_id', $tenantId)
            ->where('tenant_user_role.user_id', $userId)
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->map(fn (string $code): string => $code)
            ->values()
            ->all();
    }

    private function isMember(int $tenantId, int $userId): bool
    {
        return DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureState(int $tenantId): array
    {
        $products = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->whereIn('sku', ['SMOKE-POS-REG-001', 'SMOKE-POS-RX-001'])
            ->get(['id', 'sku', 'name', 'status', 'is_controlled'])
            ->keyBy('sku');

        $regular = $products->get('SMOKE-POS-REG-001');
        $controlled = $products->get('SMOKE-POS-RX-001');
        $regularId = $regular !== null ? (int) $regular->id : null;
        $controlledId = $controlled !== null ? (int) $controlled->id : null;
        $priceList = DB::table('price_lists')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('channel', 'retail')
            ->where('is_default', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first(['id', 'code']);

        $regularReady = $regular !== null
            && (string) $regular->status === 'active'
            && ! (bool) $regular->is_controlled
            && $this->hasAvailableLot($tenantId, $regularId, 'LOT-SMOKE-POS-REG-001')
            && $this->hasActivePriceItem($priceList !== null ? (int) $priceList->id : null, $regularId);

        $controlledReady = $controlled !== null
            && (string) $controlled->status === 'active'
            && (bool) $controlled->is_controlled
            && $this->hasAvailableLot($tenantId, $controlledId, 'LOT-SMOKE-POS-RX-001')
            && $this->hasActivePriceItem($priceList !== null ? (int) $priceList->id : null, $controlledId);

        $promotion = DB::table('promotions')
            ->where('tenant_id', $tenantId)
            ->where('code', 'SMOKE-PROMO10')
            ->first(['id', 'status']);
        $promotionId = $promotion !== null ? (int) $promotion->id : null;
        $promotionReady = $promotion !== null
            && (string) $promotion->status === 'active'
            && $this->hasPromotionRule($promotionId)
            && $this->hasPromotionTarget($promotionId, $regularId)
            && $this->hasPromotionAudience($promotionId);

        $customer = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('document_type', 'RUC')
            ->where('document_number', '20999999001')
            ->first(['id', 'status', 'credit_limit']);

        $cashSession = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first(['id', 'opening_amount', 'expected_amount', 'opened_at']);

        return [
            'regular_product_present' => $regular !== null,
            'controlled_product_present' => $controlled !== null,
            'regular_product_ready' => $regularReady,
            'controlled_product_ready' => $controlledReady,
            'promotion_ready' => $promotionReady,
            'customer_ready' => $customer !== null && $customer->status === 'active' && (float) $customer->credit_limit > 0,
            'cash_session_ready' => $cashSession !== null,
            'summary' => [
                'regular_product_id' => $regularId,
                'controlled_product_id' => $controlledId,
                'price_list_id' => $priceList !== null ? (int) $priceList->id : null,
                'price_list_code' => $priceList !== null ? (string) $priceList->code : null,
                'promotion_id' => $promotionId,
                'customer_id' => $customer !== null ? (int) $customer->id : null,
                'cash_session_id' => $cashSession !== null ? (int) $cashSession->id : null,
            ],
        ];
    }

    private function hasAvailableLot(int $tenantId, ?int $productId, string $code): bool
    {
        if ($productId === null) {
            return false;
        }

        return DB::table('lots')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('code', $code)
            ->where('status', 'available')
            ->where('stock_quantity', '>', 0)
            ->exists();
    }

    private function hasActivePriceItem(?int $priceListId, ?int $productId): bool
    {
        if ($priceListId === null || $productId === null) {
            return false;
        }

        return DB::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->where('unit_price', '>', 0)
            ->exists();
    }

    private function hasPromotionRule(?int $promotionId): bool
    {
        if ($promotionId === null) {
            return false;
        }

        return DB::table('promotion_rules')
            ->where('promotion_id', $promotionId)
            ->where('status', 'active')
            ->exists();
    }

    private function hasPromotionTarget(?int $promotionId, ?int $productId): bool
    {
        if ($promotionId === null || $productId === null) {
            return false;
        }

        return DB::table('promotion_targets')
            ->where('promotion_id', $promotionId)
            ->where('target_type', 'product')
            ->where('target_id', $productId)
            ->where('exclude', false)
            ->exists();
    }

    private function hasPromotionAudience(?int $promotionId): bool
    {
        if ($promotionId === null) {
            return false;
        }

        return DB::table('promotion_audiences')
            ->where('promotion_id', $promotionId)
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function module(string $name, array $checks, string $frontendPath): array
    {
        $blockedCount = count(array_filter(
            $checks,
            fn (array $check): bool => ($check['status'] ?? 'blocked') !== 'ok',
        ));

        return [
            'name' => $name,
            'status' => $blockedCount === 0 ? 'ok' : 'blocked',
            'frontend_path' => $frontendPath,
            'blocked_count' => $blockedCount,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $code, bool $ok, string $message): array
    {
        return [
            'code' => $code,
            'status' => $ok ? 'ok' : 'blocked',
            'message' => $message,
        ];
    }

    /**
     * @param  array<int, string>  $actual
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function permissionsCheck(array $actual, array $required): array
    {
        $missing = array_values(array_diff($required, $actual));

        return [
            'code' => 'rbac.permissions',
            'status' => $missing === [] ? 'ok' : 'blocked',
            'message' => $missing === [] ? 'Permisos requeridos disponibles.' : 'Faltan permisos requeridos.',
            'required' => $required,
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routeCheck(string $method, string $uri): array
    {
        $exists = false;

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                $exists = true;
                break;
            }
        }

        return $this->check(
            sprintf('route.%s.%s', strtolower($method), str_replace(['/', '{', '}', '?'], ['.', '', '', ''], $uri)),
            $exists,
            sprintf('Ruta %s %s registrada.', $method, $uri),
        );
    }

    private function documentContains(string $relativePath, string $needle): bool
    {
        $path = base_path($relativePath);

        if (! is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && str_contains($contents, $needle);
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    private function blockedItems(array $modules): array
    {
        $items = [];

        foreach ($modules as $moduleCode => $module) {
            foreach ($module['checks'] as $check) {
                if (($check['status'] ?? 'blocked') === 'ok') {
                    continue;
                }

                $items[] = [
                    'module' => $moduleCode,
                    'code' => $check['code'],
                    'message' => $check['message'],
                    'missing' => $check['missing'] ?? [],
                ];
            }
        }

        return $items;
    }
}
