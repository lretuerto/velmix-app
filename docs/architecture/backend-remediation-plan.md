# Backend Remediation Plan

## Scope

This plan converts the backend audit into an executable remediation sequence focused on:

1. Cash ledger correctness
2. Idempotency hardening
3. Cash and receivables read models

The delivery constraint is strict:

- no deadlocks
- no silent write duplication
- no P95 regression on hot commercial paths
- no backward-incompatible API cutover without additive rollout

## Delivery strategy

Ship this in four PR slices, in this exact order:

1. PR-A: cash ledger schema + dual write
2. PR-B: idempotency hardening + route coverage
3. PR-C: cash read model
4. PR-D: receivables and customer statement read models

Do not merge PR-C or PR-D before PR-A, because the current cash summaries depend on time-window reconstruction from `sales.created_at`.

## Track A: Cash ledger

### Objective

Replace time-window cash reconstruction with an explicit session-bound ledger so that:

- cash sales require an open session
- receivable cash payments are ledgered once
- manual in/out are ledgered once
- cash refunds from credit notes are ledgered once
- closing a session reads the session ledger, not all sales created within a time window

### A.1 Schema changes

Create these files:

- [database/migrations/2026_05_05_090000_add_cash_session_id_to_sales.php](C:\Users\user\Documents\Velmix\velmix-app\database\migrations\2026_05_05_090000_add_cash_session_id_to_sales.php)
- [database/migrations/2026_05_05_100000_create_cash_session_ledger_entries_table.php](C:\Users\user\Documents\Velmix\velmix-app\database\migrations\2026_05_05_100000_create_cash_session_ledger_entries_table.php)
- [database/migrations/2026_05_05_110000_add_cash_session_indexes_for_read_models.php](C:\Users\user\Documents\Velmix\velmix-app\database\migrations\2026_05_05_110000_add_cash_session_indexes_for_read_models.php)

Implement these changes:

- Add nullable `cash_session_id` to `sales`.
- Add foreign key `sales.cash_session_id -> cash_sessions.id`.
- Create `cash_session_ledger_entries` with:
  - `id`
  - `tenant_id`
  - `cash_session_id`
  - `source_type`
  - `source_id`
  - `entry_type`
  - `direction`
  - `amount`
  - `reference`
  - `notes`
  - `created_by_user_id`
  - `occurred_at`
  - timestamps
- Add unique constraint on `source_type + source_id + entry_type`.
- Add indexes:
  - `(tenant_id, cash_session_id, occurred_at)`
  - `(tenant_id, cash_session_id, entry_type)`
  - `(tenant_id, source_type, source_id)`
  - `(tenant_id, occurred_at)`

Recommended entry types for v1:

- `sale_cash_in`
- `sale_cash_reversal`
- `receivable_cash_in`
- `manual_in`
- `manual_out`
- `credit_note_refund`

Do not model opening balance as a ledger row yet. Keep `cash_sessions.opening_amount` as the base and add/subtract ledger entries on top.

### A.2 Domain service split

Create these files:

- [app/Services/Cash/CashLedgerService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashLedgerService.php)
- [app/Services/Cash/CashLedgerSummaryService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashLedgerSummaryService.php)

Responsibilities:

- `CashLedgerService`
  - open-session lookup with `lockForUpdate()`
  - insert-one-entry-per-origin guarantees
  - sale cash in registration
  - receivable cash in registration
  - manual movement registration
  - credit note refund registration
- `CashLedgerSummaryService`
  - aggregate expected amount from `opening_amount + signed ledger sum`
  - aggregate counts and profitability without scanning sales by time window
  - return a session summary usable by `current`, `detail`, and `history`

### A.3 Write path changes

Modify these files:

- [app/Services/Sales/PosSaleService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\PosSaleService.php)
- [app/Services/Sales/SaleReceivableService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\SaleReceivableService.php)
- [app/Services/Cash/CashMovementService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashMovementService.php)
- [app/Services/Billing/CreditNoteService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Billing\CreditNoteService.php)
- [app/Services/Sales/SaleCancellationService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\SaleCancellationService.php)
- [app/Services/Pricing/PricingCheckoutService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Pricing\PricingCheckoutService.php)

Exact changes:

- `PosSaleService`
  - if `payment_method = cash`, require an open cash session with `lockForUpdate()`
  - persist `sales.cash_session_id`
  - write one `sale_cash_in` ledger entry inside the same transaction
- `SaleReceivableService::pay`
  - keep requiring an open cash session for cash payments
  - write `receivable_cash_in` into the ledger instead of relying on `cash_movements` as the summary source
- `CashMovementService`
  - keep `cash_movements` as the operator-facing audit table if desired
  - add dual write to `cash_session_ledger_entries`
  - validate `manual_out` against ledger-based expected amount, not `CashSessionService::current()`
- `CreditNoteService`
  - if cash refund exists, write a `credit_note_refund` ledger entry in the same transaction
- `SaleCancellationService`
  - do not silently cancel cash sales with no cash reversal
  - either:
    - forbid cancelling completed cash/card/transfer sales through this path, or
    - generate a compensating refund entry and enforce an open session when the reversal is cash
  - recommended v1: restrict cancellation to operationally safe cases and keep refunds in credit-note flow
- `PricingCheckoutService`
  - no pricing logic change required
  - ensure checkout continues to delegate to the updated cash-safe sale flow

### A.4 Read path cutover for cash

Modify these files after ledger dual-write is merged:

- [app/Services/Cash/CashSessionService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashSessionService.php)
- [app/Services/Cash/CashMovementService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashMovementService.php)

Exact changes:

- Remove `buildSummary()` dependence on:
  - `sales.created_at >= opened_at`
  - `sales.created_at <= closed_at`
- Replace summary calculation with ledger-backed aggregates plus sale profitability aggregates constrained by `sales.cash_session_id`.
- Stop calling `current()` from `CashMovementService` just to compute available cash. Use the ledger summary service directly to avoid recursive read inflation.

### A.5 Backfill and rollout

Create these files:

- [app/Console/Commands/BackfillCashSessionLedgerCommand.php](C:\Users\user\Documents\Velmix\velmix-app\app\Console\Commands\BackfillCashSessionLedgerCommand.php)
- [tests/Feature/Cash/BackfillCashSessionLedgerCommandTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Cash\BackfillCashSessionLedgerCommandTest.php)

Rules:

- Do not backfill inside migrations.
- Command must support:
  - `--tenant=`
  - `--session=`
  - `--dry-run`
  - idempotent reruns
- Backfill strategy:
  - migrate historical `cash_movements` first
  - attach historical cash sales to sessions only when a session window match is unambiguous
  - emit unresolved cases instead of guessing

## Track B: EnsureIdempotency

### Objective

Make idempotency safe under retry, duplicate submit, and partial failure without storing poisoned 5xx responses.

### B.1 Middleware hardening

Modify these files:

- [app/Http/Middleware/EnsureIdempotency.php](C:\Users\user\Documents\Velmix\velmix-app\app\Http\Middleware\EnsureIdempotency.php)
- [app/Services/Platform/IdempotencyService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Platform\IdempotencyService.php)
- [app/Models/IdempotencyKey.php](C:\Users\user\Documents\Velmix\velmix-app\app\Models\IdempotencyKey.php)

Exact changes:

- Add strict mode:
  - missing `Idempotency-Key` becomes `428 Precondition Required` for protected routes
  - keep backward compatibility through configuration while frontend clients are upgraded
- Do not store 5xx responses as completed replays.
- Add explicit `fail()` or `release()` path for transient exceptions.
- Normalize first-write race:
  - on unique constraint collision during create, re-select the row and continue deterministically
- Persist structured metadata:
  - `request_fingerprint_version`
  - optional `error_class`
  - optional `completed_at`

### B.2 Schema extension

Create this file:

- [database/migrations/2026_05_05_120000_harden_idempotency_keys_table.php](C:\Users\user\Documents\Velmix\velmix-app\database\migrations\2026_05_05_120000_harden_idempotency_keys_table.php)

Suggested schema changes:

- add `completed_at`
- add `error_class`
- add `request_fingerprint_version`
- add index `(tenant_id, method, path, idempotency_key, status)`

Do not remove the existing unique scope.

### B.3 Route coverage

Modify this file:

- [routes/web.php](C:\Users\user\Documents\Velmix\velmix-app\routes\web.php)

Add `idempotent` to these routes:

- `POST /sales/customers`
- `PATCH /sales/customers/{customer}`
- `POST /sales/receivables/{receivable}/follow-ups`

Recommended rollout:

- Phase 1:
  - add middleware to routes
  - accept missing header but log warning and emit deprecation header
- Phase 2:
  - frontend ships `Idempotency-Key`
  - strict mode on for these routes

### B.4 Write-service normalization

Modify these files:

- [app/Services/Sales/CustomerService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\CustomerService.php)
- [app/Services/Sales/SaleReceivableService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\SaleReceivableService.php)

Exact changes:

- `CustomerService`
  - keep pre-checks for UX, but catch unique violations and return `409`
  - do not rely only on `exists()`
- `SaleReceivableService::addFollowUp`
  - make reference-safe replay possible through route-level idempotency
  - optional improvement: add a natural dedupe token for promise follow-ups if business accepts it

### B.5 Tests

Create or modify these files:

- [tests/Feature/Platform/IdempotencyFlowTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Platform\IdempotencyFlowTest.php)
- [tests/Feature/Concurrency/MysqlConcurrencySmokeTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Concurrency\MysqlConcurrencySmokeTest.php)
- [tests/Feature/Sales/CustomerFlowTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Sales\CustomerFlowTest.php)
- [tests/Feature/Sales/SaleReceivableFlowTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Sales\SaleReceivableFlowTest.php)

Add these cases:

- same key, same payload, same response
- same key, different payload, `409`
- same key, first attempt throws 500, second attempt is processed fresh
- two first-time writers racing the same key under MySQL
- customer create replay
- customer patch replay
- receivable follow-up replay

## Track C: Cash read model

### Objective

Stop rebuilding cash summaries per session with `1 + 2N` query fan-out and expose paginated read APIs.

### C.1 Service split

Create this file:

- [app/Services/Cash/CashSessionReadService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashSessionReadService.php)

Move read concerns here:

- `current()`
- `detail()`
- `history()`

Keep [CashSessionService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashSessionService.php) for commands only:

- `open()`
- `close()`

### C.2 API shape

Modify this file:

- [routes/web.php](C:\Users\user\Documents\Velmix\velmix-app\routes\web.php)

Additive read contract:

- `GET /cash/sessions/current`
- `GET /cash/sessions?cursor=&limit=`
- `GET /cash/sessions/{session}`
- `GET /cash/sessions/{session}/ledger?cursor=&limit=`

Do not return the full history array forever. Add cursor-based pagination and keep the old list shape only during a temporary compatibility window if the frontend still needs it.

### C.3 Summary query design

Implement ledger-backed aggregates with:

- one query for session metadata
- one grouped aggregate query for ledger totals
- one grouped aggregate query for sale profitability by `cash_session_id`
- one optional query for denominations

That replaces the current N+1 history path in [CashSessionService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Cash\CashSessionService.php).

### C.4 Tests

Create these files:

- [tests/Feature/Cash/CashSessionReadApiTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Cash\CashSessionReadApiTest.php)
- [tests/Feature/Cash/CashLedgerSummaryServiceTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Cash\CashLedgerSummaryServiceTest.php)

Add these cases:

- history is paginated
- current/detail summary comes from ledger, not time window
- close amount remains stable with mixed manual movements and cash refunds
- concurrent cash sale during close is serialized correctly under MySQL lane

## Track D: Receivables and customer statement read models

### Objective

Replace RAM-heavy arrays and multi-collection statement payloads with SQL aggregates and paginated detail slices.

### D.1 Service split

Create these files:

- [app/Services/Sales/SaleReceivableReadService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\SaleReceivableReadService.php)
- [app/Services/Sales/CustomerStatementReadService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\CustomerStatementReadService.php)

Keep write commands in existing services:

- [app/Services/Sales/SaleReceivableService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\SaleReceivableService.php)
- [app/Services/Sales/CustomerService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\CustomerService.php)

### D.2 Receivables list and aging

Modify or move logic from:

- [app/Services/Sales/SaleReceivableService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\SaleReceivableService.php)

Target contract:

- `GET /sales/receivables?status=&customer_id=&due_from=&due_to=&cursor=&limit=`
- `GET /sales/receivables/aging`
- `GET /sales/receivables/{receivable}`
- `GET /sales/receivables/{receivable}/follow-ups?cursor=&limit=`

Exact changes:

- `agingSummary()` becomes pure SQL aggregation.
- `list()` becomes paginated and filterable.
- `detail()` stays focused on one receivable.
- `followUps()` becomes paginated for long histories.

### D.3 Customer statement decomposition

Modify or replace:

- [app/Services/Sales/CustomerService.php](C:\Users\user\Documents\Velmix\velmix-app\app\Services\Sales\CustomerService.php)

Break the current fat statement endpoint into:

- `GET /sales/customers/{customer}/statement/summary`
- `GET /sales/customers/{customer}/statement/sales?cursor=&limit=`
- `GET /sales/customers/{customer}/statement/receivables?cursor=&limit=`
- `GET /sales/customers/{customer}/statement/payments?cursor=&limit=`
- `GET /sales/customers/{customer}/statement/follow-ups?cursor=&limit=`

Keep the old statement endpoint only as a short-lived compatibility facade if the frontend still needs it.

### D.4 Indexes

Create this file:

- [database/migrations/2026_05_05_130000_add_receivable_read_model_indexes.php](C:\Users\user\Documents\Velmix\velmix-app\database\migrations\2026_05_05_130000_add_receivable_read_model_indexes.php)

Recommended indexes:

- `sale_receivables`
  - `(tenant_id, status, due_at, id)`
  - `(tenant_id, customer_id, status, id)`
  - `(tenant_id, outstanding_amount, due_at)`
- `sale_receivable_payments`
  - `(sale_receivable_id, id)`
- `sale_receivable_follow_ups`
  - `(tenant_id, sale_receivable_id, id)`
- `sales`
  - `(tenant_id, customer_id, id)`
  - `(tenant_id, cash_session_id, id)`

### D.5 Tests

Create these files:

- [tests/Feature/Sales/SaleReceivableReadApiTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Sales\SaleReceivableReadApiTest.php)
- [tests/Feature/Sales/CustomerStatementReadApiTest.php](C:\Users\user\Documents\Velmix\velmix-app\tests\Feature\Sales\CustomerStatementReadApiTest.php)

Add these cases:

- aging buckets are SQL-accurate
- receivable list honors filters and cursor
- statement summary matches detail slices
- heavy follow-up history remains paginated

## Implementation order inside each PR

Use this order every time:

1. schema migration
2. service or read-model implementation
3. route wiring
4. tests
5. compatibility path
6. cleanup

Do not start with route or frontend changes.

## Release choreography

### Deploy 1

- additive schema only
- no behavior change

### Deploy 2

- dual write for cash ledger
- soft idempotency coverage on missing routes
- no frontend dependency yet

### Deploy 3

- ledger-backed cash reads
- new receivables/customer read endpoints
- frontend migrates to paginated contracts

### Deploy 4

- strict idempotency enforcement
- deprecate old statement/history payloads
- optional cleanup of legacy time-window summary logic

## Definition of done

Cash ledger is done when:

- every cash-affecting business event writes exactly one ledger row
- cash sale cannot complete without open session
- close summary does not depend on `sales.created_at` window reconstruction
- MySQL concurrency test proves `cash sale vs close` serialization

Idempotency is done when:

- protected routes require or explicitly deprecate missing keys
- 5xx responses are not replay-poisoned
- first-write races resolve deterministically
- customer create/update and follow-up writes are replay-safe

Read models are done when:

- cash history is paginated and no longer `1 + 2N`
- aging summary is computed in SQL
- customer statement is decomposed into summary + slices
- the frontend no longer depends on full-array payloads for caja/cartera

## First executable PR recommendation

Start with PR-A and make it intentionally narrow:

1. add `sales.cash_session_id`
2. add `cash_session_ledger_entries`
3. create `CashLedgerService`
4. dual-write from `PosSaleService`, `SaleReceivableService`, `CashMovementService`, and `CreditNoteService`
5. add MySQL concurrency test for `cash close vs cash sale`

Do not attempt read-model refactors in that first PR.
