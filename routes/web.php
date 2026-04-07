<?php

use App\Services\Audit\TenantActivityLogService;
use App\Services\Billing\OutboxDispatchService;
use App\Services\Billing\VoucherService;
use App\Services\Billing\CreditNoteService;
use App\Services\Billing\BillingDocumentPayloadService;
use App\Services\Billing\BillingProviderHealthService;
use App\Services\Billing\BillingProviderMetricsService;
use App\Services\Billing\BillingProviderProfileService;
use App\Services\Billing\BillingReplayService;
use App\Services\Billing\BillingOutboxLineageService;
use App\Services\Cash\CashMovementService;
use App\Services\Cash\CashSessionService;
use App\Services\Inventory\InventorySetupService;
use App\Services\Inventory\LotControlService;
use App\Services\Inventory\StockMovementReadService;
use App\Services\Inventory\StockMovementService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchasePayableService;
use App\Services\Purchasing\PurchaseReplenishmentService;
use App\Services\Purchasing\PurchaseReceiptService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\SupplierService;
use App\Services\Reports\DailyReportService;
use App\Services\Reports\BillingOperationsReportService;
use App\Services\Reports\BillingEscalationHistoryService;
use App\Services\Reports\BillingEscalationMetricsService;
use App\Services\Reports\BillingEscalationStateService;
use App\Services\Reports\BillingEscalationReportService;
use App\Services\Reports\DueReminderReportService;
use App\Services\Reports\FinanceEscalationReportService;
use App\Services\Reports\FinanceOperationsHistoryService;
use App\Services\Reports\FinanceOperationsMetricsService;
use App\Services\Reports\FinanceOperationsReportService;
use App\Services\Reports\FinanceOperationsStateService;
use App\Services\Reports\PromiseComplianceReportService;
use App\Services\Reports\ReceivableRiskReportService;
use App\Services\Security\ApiTokenService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Services\Reports\SalesProfitabilityReportService;
use App\Services\Sales\CustomerService;
use App\Services\Sales\PosSaleService;
use App\Services\Sales\SaleCancellationService;
use App\Services\Sales\SaleReceivableService;
use App\Services\Sales\SaleReadService;
use App\Services\Sales\SaleApprovalService;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', function () {
    return response()->json([
        'data' => [
            'project' => 'VELMiX ERP',
            'version' => 'sprint1-day141',
            'documents' => [
                ['name' => 'OpenAPI YAML', 'path' => '/docs/openapi.yaml'],
                ['name' => 'API Guide', 'path' => '/docs/api-guide'],
                ['name' => 'Release Readiness', 'path' => '/docs/release-readiness'],
            ],
            'conventions' => [
                'Business endpoints support Laravel session auth or Bearer token auth.',
                'Multi-tenant endpoints require X-Tenant-Id.',
                'Most responses are wrapped in a data envelope.',
            ],
        ],
    ]);
});

Route::get('/docs/openapi.yaml', function () {
    return response(
        file_get_contents(base_path('docs/openapi/velmix.openapi.yaml')),
        200,
        ['Content-Type' => 'application/yaml; charset=UTF-8'],
    );
});

Route::get('/docs/api-guide', function () {
    return response(
        file_get_contents(base_path('docs/api-guide.md')),
        200,
        ['Content-Type' => 'text/markdown; charset=UTF-8'],
    );
});

Route::get('/docs/release-readiness', function () {
    return response(
        file_get_contents(base_path('docs/sprint1/day90-release-readiness-checklist.md')),
        200,
        ['Content-Type' => 'text/markdown; charset=UTF-8'],
    );
});

Route::middleware('tenant.context')->get('/tenant/ping', function () {
    return response()->json([
        'ok' => true,
        'tenant' => app('currentTenantId'),
    ]);
});

Route::middleware(['auth', 'tenant.context', 'tenant.access'])->group(function () {
    Route::get('/auth/tokens', function (ApiTokenService $service) {
        $result = $service->listForUser(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
        );

        return response()->json(['data' => $result]);
    });

    Route::post('/auth/tokens', function (ApiTokenService $service) {
        $payload = request()->validate([
            'name' => ['required', 'string'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (string) $payload['name'],
            $payload['abilities'] ?? [],
            $payload['expires_at'] ?? null,
        );

        return response()->json(['data' => $result]);
    });

    Route::delete('/auth/tokens/{token}', function (int $token, ApiTokenService $service) {
        $result = $service->revoke(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $token,
        );

        return response()->json(['data' => $result]);
    });
});

Route::middleware(['auth.hybrid', 'tenant.context', 'tenant.access'])->group(function () {
    Route::get('/auth/me', function () {
        $user = request()->user();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'tenant_id' => (int) request()->attributes->get('tenant_id'),
                'auth_mode' => (string) request()->attributes->get('auth_mode', 'session'),
                'api_token_id' => request()->attributes->get('api_token_id'),
            ],
        ]);
    });

    Route::get('/pos/sale', fn () => response()->json(['ok' => true, 'flow' => 'sale']))
        ->middleware('perm:pos.sale.execute');

    Route::post('/pos/sales', function (PosSaleService $service) {
        $paymentMethod = (string) (request()->validate([
            'payment_method' => ['nullable', 'in:cash,card,transfer,credit'],
            'customer_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
        ])['payment_method'] ?? 'cash');

        $customerId = request()->input('customer_id');
        $dueAt = request()->input('due_at');

        $payload = request()->all();

        if (isset($payload['items'])) {
            request()->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.lot_id' => ['nullable', 'integer'],
                'items.*.product_id' => ['nullable', 'integer'],
                'items.*.quantity' => ['required', 'integer', 'min:1'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            ]);

            $items = array_map(fn (array $item) => [
                'lot_id' => $item['lot_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'prescription_code' => $item['prescription_code'] ?? null,
                'approval_code' => $item['approval_code'] ?? null,
            ], $payload['items']);
        } else {
            $single = request()->validate([
                'lot_id' => ['required', 'integer'],
                'quantity' => ['required', 'integer', 'min:1'],
                'unit_price' => ['required', 'numeric', 'min:0'],
                'prescription_code' => ['nullable', 'string'],
                'approval_code' => ['nullable', 'string'],
            ]);

            $items = [[
                'lot_id' => (int) $single['lot_id'],
                'quantity' => (int) $single['quantity'],
                'unit_price' => (float) $single['unit_price'],
                'prescription_code' => $single['prescription_code'] ?? null,
                'approval_code' => $single['approval_code'] ?? null,
            ]];
        }

        $result = $service->execute(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $items,
            $paymentMethod,
            $customerId !== null ? (int) $customerId : null,
            $dueAt !== null ? (string) $dueAt : null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.execute');

    Route::get('/sales/customers', function (CustomerService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.customer.read');

    Route::post('/sales/customers', function (CustomerService $service) {
        $payload = request()->validate([
            'document_type' => ['required', 'string'],
            'document_number' => ['required', 'string'],
            'name' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0'],
            'block_on_overdue' => ['nullable', 'boolean'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (string) $payload['document_type'],
            (string) $payload['document_number'],
            (string) $payload['name'],
            $payload['phone'] ?? null,
            $payload['email'] ?? null,
            isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : null,
            isset($payload['credit_days']) ? (int) $payload['credit_days'] : null,
            (bool) ($payload['block_on_overdue'] ?? true),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.customer.create');

    Route::patch('/sales/customers/{customer}', function (int $customer, CustomerService $service) {
        $payload = request()->validate([
            'document_type' => ['sometimes', 'string'],
            'document_number' => ['sometimes', 'string'],
            'name' => ['sometimes', 'string'],
            'phone' => ['sometimes', 'nullable', 'string'],
            'email' => ['sometimes', 'nullable', 'email'],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'credit_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'block_on_overdue' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $result = $service->update(
            (int) request()->attributes->get('tenant_id'),
            $customer,
            $payload,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.customer.update');

    Route::get('/sales/customers/{customer}/statement', function (int $customer, CustomerService $service) {
        $result = $service->statement(
            (int) request()->attributes->get('tenant_id'),
            $customer,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.customer.read');

    Route::get('/sales/receivables', function (SaleReceivableService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.receivable.read');

    Route::get('/sales/receivables/aging', function (SaleReceivableService $service) {
        $result = $service->agingSummary((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.receivable.read');

    Route::get('/sales/receivables/{receivable}', function (int $receivable, SaleReceivableService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $receivable,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.receivable.read');

    Route::post('/sales/receivables/{receivable}/payments', function (int $receivable, SaleReceivableService $service) {
        $payload = request()->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string'],
            'reference' => ['required', 'string'],
        ]);

        $result = $service->pay(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $receivable,
            (float) $payload['amount'],
            (string) $payload['payment_method'],
            (string) $payload['reference'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.receivable.pay');

    Route::get('/sales/receivables/{receivable}/follow-ups', function (int $receivable, SaleReceivableService $service) {
        $result = $service->followUps(
            (int) request()->attributes->get('tenant_id'),
            $receivable,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.receivable.read');

    Route::post('/sales/receivables/{receivable}/follow-ups', function (int $receivable, SaleReceivableService $service) {
        $payload = request()->validate([
            'type' => ['required', 'in:note,promise'],
            'note' => ['required', 'string'],
            'promised_amount' => ['nullable', 'numeric', 'min:0.01'],
            'promised_at' => ['nullable', 'date'],
        ]);

        $result = $service->addFollowUp(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $receivable,
            (string) $payload['type'],
            (string) $payload['note'],
            isset($payload['promised_amount']) ? (float) $payload['promised_amount'] : null,
            $payload['promised_at'] ?? null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:sales.receivable.follow-up.create');

    Route::get('/pos/sales', function (SaleReadService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.read');

    Route::get('/pos/sales/{sale}', function (int $sale, SaleReadService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $sale,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.read');

    Route::get('/pos/approve', fn () => response()->json(['ok' => true, 'flow' => 'approve']))
        ->middleware('perm:pos.sale.approve');

    Route::post('/pos/approvals', function (SaleApprovalService $service) {
        $payload = request()->validate([
            'product_id' => ['required', 'integer'],
            'reason' => ['required', 'string'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (int) $payload['product_id'],
            (string) $payload['reason'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.approve');

    Route::post('/pos/sales/{sale}/cancel', function (int $sale, SaleCancellationService $service) {
        $payload = request()->validate([
            'reason' => ['required', 'string'],
        ]);

        $result = $service->cancel(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $sale,
            (string) $payload['reason'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.approve');

    Route::post('/cash/sessions/open', function (CashSessionService $service) {
        $payload = request()->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $service->open(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (float) $payload['opening_amount'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.open');

    Route::get('/cash/sessions/current', function (CashSessionService $service) {
        $result = $service->current((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.read');

    Route::get('/cash/sessions', function (CashSessionService $service) {
        $result = $service->history((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.read');

    Route::get('/cash/sessions/{session}', function (int $session, CashSessionService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $session,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.read');

    Route::get('/cash/sessions/{session}/movements', function (int $session, CashMovementService $service) {
        $result = $service->listForSession(
            (int) request()->attributes->get('tenant_id'),
            $session,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.movement.read');

    Route::post('/cash/sessions/current/close', function (CashSessionService $service) {
        $payload = request()->validate([
            'counted_amount' => ['nullable', 'numeric', 'min:0'],
            'denominations' => ['nullable', 'array', 'min:1'],
            'denominations.*.value' => ['required_with:denominations', 'numeric', 'min:0.01'],
            'denominations.*.quantity' => ['required_with:denominations', 'integer', 'min:1'],
        ]);

        $result = $service->close(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            isset($payload['counted_amount']) ? (float) $payload['counted_amount'] : null,
            array_map(fn (array $denomination) => [
                'value' => (float) $denomination['value'],
                'quantity' => (int) $denomination['quantity'],
            ], $payload['denominations'] ?? []),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.close');

    Route::post('/cash/movements', function (CashMovementService $service) {
        $payload = request()->validate([
            'type' => ['required', 'in:manual_in,manual_out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (string) $payload['type'],
            (float) $payload['amount'],
            (string) $payload['reference'],
            $payload['notes'] ?? null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.movement.create');

    Route::get('/audit/timeline', function (TenantActivityLogService $service) {
        $payload = request()->validate([
            'domain' => ['nullable', 'string'],
            'event_type' => ['nullable', 'string'],
            'aggregate_type' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $result = $service->list(
            (int) request()->attributes->get('tenant_id'),
            $payload,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:audit.timeline.read');

    Route::get('/audit/timeline/{activity}', function (int $activity, TenantActivityLogService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $activity,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:audit.timeline.read');

    Route::get('/reports/daily', function (DailyReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.daily.read');

    Route::get('/reports/due-reminders', function (DueReminderReportService $service) {
        $payload = request()->validate([
            'days_ahead' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['limit'] ?? 5),
            $payload['date'] ?? null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.due-reminders.read');

    Route::get('/reports/billing-operations', function (BillingOperationsReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'failure_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
            (int) ($payload['failure_limit'] ?? 5),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.billing-operations.read');

    Route::get('/reports/billing-escalations', function (BillingEscalationReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
            (int) ($payload['limit'] ?? 10),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.billing-operations.read');

    Route::get('/reports/billing-escalations/history', function (BillingEscalationHistoryService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'history_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $result = $service->index(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
            (int) ($payload['history_days'] ?? 30),
            (int) ($payload['limit'] ?? 10),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.billing-operations.read');

    Route::get('/reports/billing-escalation-metrics', function (BillingEscalationMetricsService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'history_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
            (int) ($payload['history_days'] ?? 30),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.billing-operations.read');

    Route::get('/reports/billing-escalations/{code}', function (string $code, BillingEscalationHistoryService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'history_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'activity_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $code,
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
            (int) ($payload['history_days'] ?? 30),
            (int) ($payload['activity_limit'] ?? 20),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.billing-operations.read');

    Route::post('/reports/billing-escalations/{code}/acknowledge', function (string $code, BillingEscalationStateService $states, BillingEscalationReportService $report) {
        $payload = request()->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
        ]);

        $state = $states->acknowledge(
            (int) request()->attributes->get('tenant_id'),
            (int) request()->user()->id,
            $code,
            $payload['note'] ?? null,
        );

        $activeItem = collect($report->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
        )['items'])
            ->firstWhere('code', $code);

        return response()->json([
            'data' => [
                'state' => $state,
                'active_item' => $activeItem,
            ],
        ]);
    })->middleware('perm:reports.billing-operations.manage');

    Route::post('/reports/billing-escalations/{code}/resolve', function (string $code, BillingEscalationStateService $states, BillingEscalationReportService $report) {
        $payload = request()->validate([
            'note' => ['required', 'string', 'max:1000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
        ]);

        $state = $states->resolve(
            (int) request()->attributes->get('tenant_id'),
            (int) request()->user()->id,
            $code,
            (string) $payload['note'],
        );

        $activeItem = collect($report->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
        )['items'])
            ->firstWhere('code', $code);

        return response()->json([
            'data' => [
                'state' => $state,
                'active_item' => $activeItem,
            ],
        ]);
    })->middleware('perm:reports.billing-operations.manage');

    Route::get('/reports/promise-compliance', function (PromiseComplianceReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['limit'] ?? 5),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.promise-compliance.read');

    Route::get('/reports/finance-operations', function (FinanceOperationsReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'stale_follow_up_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['limit'] ?? 5),
            (int) ($payload['stale_follow_up_days'] ?? 3),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.finance-operations.read');

    Route::get('/reports/finance-escalations', function (FinanceEscalationReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'stale_follow_up_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['limit'] ?? 10),
            (int) ($payload['stale_follow_up_days'] ?? 3),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.finance-operations.read');

    Route::get('/reports/finance-operations/history', function (FinanceOperationsHistoryService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'history_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'stale_follow_up_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $result = $service->index(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['history_days'] ?? 30),
            (int) ($payload['limit'] ?? 10),
            (int) ($payload['stale_follow_up_days'] ?? 3),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.finance-operations.read');

    Route::get('/reports/finance-operations/metrics', function (FinanceOperationsMetricsService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'history_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['history_days'] ?? 30),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.finance-operations.read');

    Route::get('/reports/finance-operations/{kind}/{entity}', function (string $kind, int $entity, FinanceOperationsReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'stale_follow_up_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $kind,
            $entity,
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['stale_follow_up_days'] ?? 3),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.finance-operations.read');

    Route::get('/reports/finance-operations/{kind}/{entity}/history', function (
        string $kind,
        int $entity,
        FinanceOperationsHistoryService $service
    ) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'history_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'activity_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'stale_follow_up_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $kind,
            $entity,
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['history_days'] ?? 30),
            (int) ($payload['activity_limit'] ?? 20),
            (int) ($payload['stale_follow_up_days'] ?? 3),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.finance-operations.read');

    Route::post('/reports/finance-operations/{kind}/{entity}/acknowledge', function (
        string $kind,
        int $entity,
        FinanceOperationsStateService $states,
        FinanceOperationsReportService $report
    ) {
        $payload = request()->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'stale_follow_up_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $state = $states->acknowledge(
            (int) request()->attributes->get('tenant_id'),
            (int) request()->user()->id,
            $kind,
            $entity,
            $payload['note'] ?? null,
        );

        $item = $report->detail(
            (int) request()->attributes->get('tenant_id'),
            $kind,
            $entity,
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['stale_follow_up_days'] ?? 3),
        );

        return response()->json([
            'data' => [
                'state' => $state,
                'item' => $item,
            ],
        ]);
    })->middleware('perm:reports.finance-operations.manage');

    Route::post('/reports/finance-operations/{kind}/{entity}/resolve', function (
        string $kind,
        int $entity,
        FinanceOperationsStateService $states,
        FinanceOperationsReportService $report
    ) {
        $payload = request()->validate([
            'note' => ['required', 'string', 'max:1000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'stale_follow_up_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $state = $states->resolve(
            (int) request()->attributes->get('tenant_id'),
            (int) request()->user()->id,
            $kind,
            $entity,
            (string) $payload['note'],
        );

        $item = $report->detail(
            (int) request()->attributes->get('tenant_id'),
            $kind,
            $entity,
            $payload['date'] ?? null,
            (int) ($payload['days_ahead'] ?? 7),
            (int) ($payload['stale_follow_up_days'] ?? 3),
        );

        return response()->json([
            'data' => [
                'state' => $state,
                'item' => $item,
            ],
        ]);
    })->middleware('perm:reports.finance-operations.manage');

    Route::get('/reports/inventory-alerts', function (\App\Services\Reports\InventoryAlertReportService $service) {
        $payload = request()->validate([
            'low_stock_threshold' => ['nullable', 'integer', 'min:1'],
            'expiring_within_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            (int) ($payload['low_stock_threshold'] ?? 10),
            (int) ($payload['expiring_within_days'] ?? 30),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.inventory.read');

    Route::get('/reports/receivable-risk', function (ReceivableRiskReportService $service) {
        $result = $service->summary((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.receivable-risk.read');

    Route::get('/reports/sales-profitability', function (SalesProfitabilityReportService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:reports.sales-profitability.read');

    Route::get('/purchases/suppliers', function (SupplierService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.supplier.read');

    Route::get('/purchases/suppliers/{supplier}/statement', function (int $supplier, SupplierService $service) {
        $result = $service->statement(
            (int) request()->attributes->get('tenant_id'),
            $supplier,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.supplier.read');

    Route::post('/purchases/suppliers', function (SupplierService $service) {
        $payload = request()->validate([
            'tax_id' => ['required', 'string'],
            'name' => ['required', 'string'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (string) $payload['tax_id'],
            (string) $payload['name'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.supplier.create');

    Route::get('/purchases/receipts', function (PurchaseReceiptService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.receipt.read');

    Route::get('/purchases/receipts/{receipt}', function (int $receipt, PurchaseReceiptService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $receipt,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.receipt.read');

    Route::get('/purchases/returns', function (PurchaseReturnService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.return.read');

    Route::get('/purchases/returns/{return}', function (int $return, PurchaseReturnService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $return,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.return.read');

    Route::post('/purchases/receipts/{receipt}/returns', function (int $receipt, PurchaseReturnService $service) {
        $payload = request()->validate([
            'reason' => ['required', 'string'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.purchase_receipt_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $receipt,
            (string) $payload['reason'],
            array_map(fn (array $item) => [
                'purchase_receipt_item_id' => (int) $item['purchase_receipt_item_id'],
                'quantity' => (int) $item['quantity'],
            ], $payload['items'] ?? []),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.return.create');

    Route::get('/purchases/orders', function (PurchaseOrderService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.order.read');

    Route::get('/purchases/orders/{order}', function (int $order, PurchaseOrderService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $order,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.order.read');

    Route::post('/purchases/orders', function (PurchaseOrderService $service) {
        $payload = request()->validate([
            'supplier_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.ordered_quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (int) $payload['supplier_id'],
            array_map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'ordered_quantity' => (int) $item['ordered_quantity'],
                'unit_cost' => (float) $item['unit_cost'],
            ], $payload['items']),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.order.create');

    Route::post('/purchases/orders/from-replenishment', function (PurchaseOrderService $service) {
        $payload = request()->validate([
            'supplier_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.suggested_order_quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.order_quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $service->createFromReplenishment(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (int) $payload['supplier_id'],
            array_map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'unit_cost' => (float) $item['unit_cost'],
                'suggested_order_quantity' => isset($item['suggested_order_quantity']) ? (int) $item['suggested_order_quantity'] : null,
                'order_quantity' => isset($item['order_quantity']) ? (int) $item['order_quantity'] : null,
            ], $payload['items']),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.order.create');

    Route::get('/purchases/replenishment-suggestions', function (PurchaseReplenishmentService $service) {
        $payload = request()->validate([
            'lookback_days' => ['nullable', 'integer', 'min:1'],
            'coverage_days' => ['nullable', 'integer', 'min:1'],
            'expiring_within_days' => ['nullable', 'integer', 'min:1'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $service->suggestions(
            (int) request()->attributes->get('tenant_id'),
            (int) ($payload['lookback_days'] ?? 30),
            (int) ($payload['coverage_days'] ?? 30),
            (int) ($payload['expiring_within_days'] ?? 30),
            (int) ($payload['low_stock_threshold'] ?? 20),
        );

        return response()->json($result);
    })->middleware('perm:purchase.replenishment.read');

    Route::get('/purchases/payables', function (PurchasePayableService $service) {
        $result = $service->list((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.payable.read');

    Route::get('/purchases/payables/aging', function (PurchasePayableService $service) {
        $result = $service->agingSummary((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.payable.read');

    Route::get('/purchases/payables/{payable}', function (int $payable, PurchasePayableService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $payable,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.payable.read');

    Route::post('/purchases/payables/{payable}/payments', function (int $payable, PurchasePayableService $service) {
        $payload = request()->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string'],
            'reference' => ['required', 'string'],
        ]);

        $result = $service->pay(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $payable,
            (float) $payload['amount'],
            (string) $payload['payment_method'],
            (string) $payload['reference'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.payable.pay');

    Route::post('/purchases/payables/{payable}/apply-credits', function (int $payable, PurchasePayableService $service) {
        $payload = request()->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $result = $service->applyCredits(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $payable,
            isset($payload['amount']) ? (float) $payload['amount'] : null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.payable.pay');

    Route::get('/purchases/payables/{payable}/follow-ups', function (int $payable, PurchasePayableService $service) {
        $result = $service->followUps(
            (int) request()->attributes->get('tenant_id'),
            $payable,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.payable.read');

    Route::post('/purchases/payables/{payable}/follow-ups', function (int $payable, PurchasePayableService $service) {
        $payload = request()->validate([
            'type' => ['required', 'in:note,promise'],
            'note' => ['required', 'string'],
            'promised_amount' => ['nullable', 'numeric', 'min:0.01'],
            'promised_at' => ['nullable', 'date'],
        ]);

        $result = $service->addFollowUp(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $payable,
            (string) $payload['type'],
            (string) $payload['note'],
            isset($payload['promised_amount']) ? (float) $payload['promised_amount'] : null,
            $payload['promised_at'] ?? null,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.payable.follow-up.create');

    Route::post('/purchases/receipts', function (PurchaseReceiptService $service) {
        $payload = request()->validate([
            'supplier_id' => ['required', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.lot_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.lot_code' => ['nullable', 'string'],
            'items.*.expires_at' => ['nullable', 'date'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $service->receive(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (int) $payload['supplier_id'],
            isset($payload['purchase_order_id']) ? (int) $payload['purchase_order_id'] : null,
            array_map(fn (array $item) => [
                'lot_id' => isset($item['lot_id']) ? (int) $item['lot_id'] : null,
                'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                'lot_code' => $item['lot_code'] ?? null,
                'expires_at' => $item['expires_at'] ?? null,
                'quantity' => (int) $item['quantity'],
                'unit_cost' => (float) $item['unit_cost'],
            ], $payload['items']),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:purchase.receipt.create');

    Route::get('/stock/move', fn () => response()->json(['ok' => true, 'flow' => 'stock']))
        ->middleware('perm:stock.move.create');

    Route::get('/inventory/movements', function (StockMovementReadService $service) {
        $payload = request()->validate([
            'lot_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
        ]);

        $result = $service->list(
            (int) request()->attributes->get('tenant_id'),
            array_filter($payload, fn ($value) => $value !== null),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:stock.move.read');

    Route::post('/stock/movements', function (StockMovementService $service) {
        $payload = request()->validate([
            'lot_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) $payload['lot_id'],
            (string) $payload['type'],
            (int) $payload['quantity'],
            (string) $payload['reference'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:stock.move.create');

    Route::get('/rbac/permissions', fn () => response()->json(['ok' => true, 'flow' => 'rbac-permissions']))
        ->middleware('perm:rbac.permission.manage');

    Route::get('/inventory/products', function () {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $products = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->orderBy('sku')
            ->get(['id', 'tenant_id', 'sku', 'name', 'status', 'is_controlled', 'last_cost', 'average_cost']);

        return response()->json(['data' => $products]);
    })->middleware('perm:inventory.product.read');

    Route::post('/inventory/products', function (InventorySetupService $service) {
        $payload = request()->validate([
            'sku' => ['required', 'string'],
            'name' => ['required', 'string'],
            'is_controlled' => ['required', 'boolean'],
        ]);

        $result = $service->createProduct(
            (int) request()->attributes->get('tenant_id'),
            (string) $payload['sku'],
            (string) $payload['name'],
            (bool) $payload['is_controlled'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:inventory.product.create');

    Route::get('/inventory/lots/{lot}', function (int $lot) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $lotData = DB::table('lots')
            ->join('products', 'products.id', '=', 'lots.product_id')
            ->where('lots.id', $lot)
            ->where('lots.tenant_id', $tenantId)
            ->first([
                'lots.id',
                'lots.tenant_id',
                'lots.code',
                'lots.stock_quantity',
                'lots.status',
                'products.sku as product_sku',
            ]);

        if ($lotData === null) {
            abort(404, 'Lot not found.');
        }

        return response()->json(['data' => $lotData]);
    })->middleware('perm:inventory.lot.read');

    Route::post('/inventory/lots', function (InventorySetupService $service) {
        $payload = request()->validate([
            'product_id' => ['required', 'integer'],
            'code' => ['required', 'string'],
            'expires_at' => ['required', 'date'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $result = $service->createLot(
            (int) request()->attributes->get('tenant_id'),
            (int) $payload['product_id'],
            (string) $payload['code'],
            (string) $payload['expires_at'],
            (int) $payload['stock_quantity'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:inventory.lot.create');

    Route::post('/inventory/lots/{lot}/immobilize', function (int $lot, LotControlService $service) {
        $payload = request()->validate([
            'reason' => ['required', 'string'],
        ]);

        $result = $service->immobilize(
            (int) request()->attributes->get('tenant_id'),
            $lot,
            (string) $payload['reason'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:inventory.lot.create');

    Route::post('/billing/vouchers', function (VoucherService $service) {
        $payload = request()->validate([
            'sale_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
        ]);

        $result = $service->createFromSale(
            (int) request()->attributes->get('tenant_id'),
            (int) $payload['sale_id'],
            (string) $payload['type'],
            (int) optional(request()->user())->id,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.voucher.issue');

    Route::get('/billing/vouchers/{voucher}', function (int $voucher) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $voucherData = DB::table('electronic_vouchers')
            ->join('sales', 'sales.id', '=', 'electronic_vouchers.sale_id')
            ->leftJoin('outbox_events', function ($join) {
                $join->on('outbox_events.aggregate_id', '=', 'electronic_vouchers.id')
                    ->where('outbox_events.aggregate_type', '=', 'electronic_voucher');
            })
            ->where('electronic_vouchers.id', $voucher)
            ->where('electronic_vouchers.tenant_id', $tenantId)
            ->first([
                'electronic_vouchers.id',
                'electronic_vouchers.tenant_id',
                'electronic_vouchers.sale_id',
                'electronic_vouchers.type',
                'electronic_vouchers.series',
                'electronic_vouchers.number',
                'electronic_vouchers.status',
                'electronic_vouchers.sunat_ticket',
                'electronic_vouchers.rejection_reason',
                'sales.reference as sale_reference',
                'outbox_events.id as outbox_event_id',
            ]);

        if ($voucherData === null) {
            abort(404, 'Voucher not found.');
        }

        return response()->json(['data' => $voucherData]);
    })->middleware('perm:billing.voucher.read');

    Route::get('/billing/vouchers/{voucher}/payloads', function (int $voucher, BillingDocumentPayloadService $service) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $exists = DB::table('electronic_vouchers')
            ->where('tenant_id', $tenantId)
            ->where('id', $voucher)
            ->exists();

        if (! $exists) {
            abort(404, 'Voucher not found.');
        }

        return response()->json([
            'data' => $service->listForAggregate($tenantId, 'electronic_voucher', $voucher),
        ]);
    })->middleware('perm:billing.voucher.read');

    Route::post('/billing/vouchers/{voucher}/payloads/regenerate', function (int $voucher, BillingDocumentPayloadService $service) {
        $result = $service->regenerateForVoucher(
            (int) request()->attributes->get('tenant_id'),
            $voucher,
            (int) optional(request()->user())->id,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.provider.manage');

    Route::post('/billing/vouchers/{voucher}/replay', function (int $voucher, BillingReplayService $service) {
        $result = $service->replayVoucher(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $voucher,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.dispatch');

    Route::post('/billing/credit-notes', function (CreditNoteService $service) {
        $payload = request()->validate([
            'sale_id' => ['required', 'integer'],
            'reason' => ['required', 'string'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ]);

        $result = $service->createFromSale(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (int) $payload['sale_id'],
            (string) $payload['reason'],
            array_map(fn (array $item) => [
                'sale_item_id' => (int) $item['sale_item_id'],
                'quantity' => (int) $item['quantity'],
            ], $payload['items'] ?? []),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.credit-note.issue');

    Route::get('/billing/credit-notes/{creditNote}', function (int $creditNote, CreditNoteService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $creditNote,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.credit-note.read');

    Route::get('/billing/credit-notes/{creditNote}/payloads', function (int $creditNote, BillingDocumentPayloadService $service) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $exists = DB::table('sale_credit_notes')
            ->where('tenant_id', $tenantId)
            ->where('id', $creditNote)
            ->exists();

        if (! $exists) {
            abort(404, 'Credit note not found.');
        }

        return response()->json([
            'data' => $service->listForAggregate($tenantId, 'sale_credit_note', $creditNote),
        ]);
    })->middleware('perm:billing.credit-note.read');

    Route::post('/billing/credit-notes/{creditNote}/payloads/regenerate', function (int $creditNote, BillingDocumentPayloadService $service) {
        $result = $service->regenerateForCreditNote(
            (int) request()->attributes->get('tenant_id'),
            $creditNote,
            (int) optional(request()->user())->id,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.provider.manage');

    Route::post('/billing/credit-notes/{creditNote}/replay', function (int $creditNote, BillingReplayService $service) {
        $result = $service->replayCreditNote(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $creditNote,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.dispatch');

    Route::post('/billing/outbox/dispatch', function (OutboxDispatchService $service) {
        $payload = request()->validate([
            'simulate_result' => ['nullable', 'in:accepted,rejected,transient_fail'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = (int) request()->attributes->get('tenant_id');
        $outcome = isset($payload['simulate_result']) ? (string) $payload['simulate_result'] : null;
        $limit = (int) ($payload['limit'] ?? 1);

        if ($limit > 1) {
            $result = $service->dispatchBatch($tenantId, $limit, $outcome);

            return response()->json(['data' => $result]);
        }

        $result = $service->dispatchNext($tenantId, $outcome);

        $status = (int) ($result['http_status'] ?? 200);
        unset($result['http_status']);

        return response()->json(['data' => $result], $status);
    })->middleware('perm:billing.outbox.dispatch');

    Route::get('/billing/outbox/summary', function (OutboxDispatchService $service) {
        $result = $service->queueSummary((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.read');

    Route::get('/billing/provider-profile', function (BillingProviderProfileService $service) {
        $result = $service->current((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.provider.manage');

    Route::post('/billing/provider-profile/check', function (BillingProviderHealthService $service) {
        $result = $service->check(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.provider.manage');

    Route::put('/billing/provider-profile', function (BillingProviderProfileService $service) {
        $payload = request()->validate([
            'provider_code' => ['sometimes', 'in:fake_sunat'],
            'environment' => ['sometimes', 'in:sandbox,live'],
            'default_outcome' => ['sometimes', 'in:accepted,rejected,transient_fail'],
            'credentials' => ['sometimes', 'nullable', 'array'],
        ]);

        $result = $service->update(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $payload,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.provider.manage');

    Route::get('/billing/outbox/provider-trace', function (BillingProviderHealthService $service) {
        $payload = request()->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $service->trace(
            (int) request()->attributes->get('tenant_id'),
            (int) ($payload['limit'] ?? 20),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.read');

    Route::get('/billing/provider-metrics', function (BillingProviderMetricsService $service) {
        $payload = request()->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'recent_failures_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $result = $service->summary(
            (int) request()->attributes->get('tenant_id'),
            $payload['date'] ?? null,
            (int) ($payload['days'] ?? 7),
            (int) ($payload['recent_failures_limit'] ?? 5),
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.read');

    Route::post('/billing/outbox/{event}/retry', function (int $event, OutboxDispatchService $service) {
        $result = $service->retryFailed(
            (int) request()->attributes->get('tenant_id'),
            $event,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.dispatch');

    Route::get('/billing/outbox/{event}/lineage', function (int $event, BillingOutboxLineageService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $event,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.read');

    Route::get('/billing/outbox/{event}/attempts', function (int $event) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $eventExists = DB::table('outbox_events')
            ->where('id', $event)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $eventExists) {
            abort(404, 'Outbox event not found.');
        }

        $attempts = DB::table('outbox_attempts')
            ->where('outbox_event_id', $event)
            ->orderBy('id')
            ->get([
                'id',
                'outbox_event_id',
                'status',
                'provider_code',
                'provider_environment',
                'provider_reference',
                'sunat_ticket',
                'error_message',
                'created_at',
            ]);

        return response()->json(['data' => $attempts]);
    })->middleware('perm:billing.outbox.read');
});
