# Release commit plan - 2026-05-18

## Estado actual

La rama actual es `sprint1/day8-rbac-seeders-smoke`. El paquete de trabajo contiene backend hardening, pricing/promotions v1, POS quote-first, frontend React/TypeScript profesional y gobierno UAT.

La validacion tecnica previa esta verde, pero al 2026-05-18 la evidencia UAT de 24 horas esta vencida:

| Gate | Estado |
|:---|:---|
| Codigo/backend/frontend | Verde en la ultima auditoria completa |
| `git diff --check` | OK |
| `frontend:uat-release-readiness --freshness-hours=24` | `blocked` por evidencia stale |
| Blockers stale | smoke POS, signoff packet y visual signoff |

Esto significa: el codigo puede prepararse para commit/PR, pero no debe declararse release productivo final hasta refrescar evidencia UAT y firma humana.

## Estrategia de commits

Evitar un commit gigante. El cambio es transversal y debe entrar en bloques revisables con tests asociados.

## Commit 1 - Backend hardening e idempotencia

Objetivo: cerrar consistencia transaccional, idempotencia defensiva, reportes de preflight y observabilidad base.

Archivos principales:

- `app/Http/Middleware/EnsureIdempotency.php`
- `app/Models/IdempotencyKey.php`
- `app/Services/Platform/IdempotencyService.php`
- `app/Services/Platform/SystemPreflightService.php`
- `app/Services/Platform/SystemObservabilityReportService.php`
- `app/Providers/AppServiceProvider.php`
- `config/logging.php`
- `config/velmix.php`
- `database/migrations/2026_05_05_120000_harden_idempotency_keys_table.php`
- `tests/Feature/Platform/IdempotencyFlowTest.php`
- `tests/Feature/Platform/SystemPreflightCommandTest.php`
- `tests/Feature/Platform/SystemObservabilityReportCommandTest.php`

Validacion focal:

```bash
php artisan test tests/Feature/Platform/IdempotencyFlowTest.php tests/Feature/Platform/SystemPreflightCommandTest.php tests/Feature/Platform/SystemObservabilityReportCommandTest.php
```

## Commit 2 - Cash ledger y read models operativos

Objetivo: consolidar caja como ledger auditable y lecturas consistentes para caja/cartera.

Archivos principales:

- `app/Services/Cash/CashLedgerService.php`
- `app/Services/Cash/CashLedgerAuditService.php`
- `app/Services/Cash/CashLedgerSummaryService.php`
- `app/Services/Cash/CashSessionLedgerBackfillService.php`
- `app/Services/Cash/CashSessionReadService.php`
- `app/Services/Platform/CashLedgerReadinessService.php`
- `app/Services/Cash/CashMovementService.php`
- `app/Services/Cash/CashSessionService.php`
- `app/Services/Sales/SaleReceivableReadService.php`
- `app/Services/Sales/CustomerStatementReadService.php`
- `app/Services/Sales/SaleReceivableService.php`
- `database/migrations/2026_05_05_090000_add_cash_session_id_to_sales.php`
- `database/migrations/2026_05_05_100000_create_cash_session_ledger_entries_table.php`
- `database/migrations/2026_05_05_110000_add_cash_session_indexes_for_read_models.php`
- `database/migrations/2026_05_05_130000_add_receivable_read_model_indexes.php`
- `database/migrations/2026_05_05_140000_add_cash_ledger_backfill_indexes.php`
- `tests/Feature/Cash/BackfillCashSessionLedgerCommandTest.php`
- `tests/Feature/Cash/CashLedgerAuditCommandTest.php`
- `tests/Feature/Cash/CashSessionFlowTest.php`
- `tests/Feature/Sales/SaleReceivableFlowTest.php`

Validacion focal:

```bash
php artisan test tests/Feature/Cash tests/Feature/Sales/SaleReceivableFlowTest.php
```

## Commit 3 - Pricing/promotions v1 y checkout quote-first

Objetivo: introducir contrato comercial correcto para precio de venta, promociones de laboratorio y checkout desde quote.

Archivos principales:

- `app/Services/Pricing/PriceListResolverService.php`
- `app/Services/Pricing/PricingQuoteService.php`
- `app/Services/Pricing/PromotionEligibilityService.php`
- `app/Services/Pricing/PromotionEngineService.php`
- `app/Services/Pricing/PricingCheckoutService.php`
- `app/Services/Sales/PosSaleService.php`
- `app/Services/Sales/SaleCancellationService.php`
- `app/Services/Sales/CustomerService.php`
- `database/migrations/2026_05_04_100000_add_commercial_metadata_to_suppliers_and_products.php`
- `database/migrations/2026_05_04_110000_create_pricing_foundation_tables.php`
- `database/migrations/2026_05_04_120000_create_promotions_tables.php`
- `database/migrations/2026_05_04_130000_create_pricing_quotes_tables.php`
- `database/migrations/2026_05_04_140000_create_sale_item_pricing_components_table.php`
- `docs/architecture/pricing-promotions-v1.md`
- `docs/openapi/velmix.openapi.yaml`
- `docs/api-guide.md`
- `tests/Feature/Pricing`
- `tests/Feature/Pos`
- `tests/Feature/Docs/OpenApiDocsTest.php`

Validacion focal:

```bash
php artisan test tests/Feature/Pricing tests/Feature/Pos tests/Feature/Docs/OpenApiDocsTest.php
```

## Commit 4 - Frontend foundation React/TypeScript

Objetivo: introducir shell React profesional, API client, routing lazy, feedback UX y tooling TS/Vite.

Archivos principales:

- `.eslintrc.cjs`
- `package.json`
- `package-lock.json`
- `tsconfig.json`
- `vite.config.js`
- `resources/js/app.tsx`
- `resources/js/bootstrap.ts`
- `resources/js/core`
- `resources/js/shared`
- `resources/css/tokens.css`
- `resources/css/app.css`
- `resources/views/app.blade.php`
- `resources/views/welcome.blade.php`
- `routes/web.php`
- `routes/web/platform.php`
- eliminar `resources/js/app.js`
- eliminar `resources/js/bootstrap.js`

Validacion focal:

```bash
npm run typecheck
npm run lint
npm run test
npm run build
```

## Commit 5 - Frontend modulos operativos

Objetivo: entregar modulos usables para POS, caja, cartera, catalogo, clientes y platform dashboard.

Archivos principales:

- `resources/js/modules/auth`
- `resources/js/modules/home`
- `resources/js/modules/platform`
- `resources/js/modules/inventory`
- `resources/js/modules/sales`
- `resources/js/modules/pos`
- `resources/js/modules/cash`
- `resources/js/modules/pricing`
- `tests/Feature/Auth/SessionAuthFlowTest.php`

Validacion focal:

```bash
npm run test
npm run build
php artisan test tests/Feature/Auth/SessionAuthFlowTest.php
```

## Commit 6 - Frontend UAT governance y cierre

Objetivo: formalizar readiness, smoke, signoff, evidencia visual, closure y checklist firmable.

Archivos principales:

- `app/Services/Frontend`
- `routes/console.php`
- `docs/frontend`
- `docs/architecture/frontend-uat-closure-status-2026-05-14.md`
- `docs/architecture/release-readiness-audit-2026-05-16.md`
- `docs/architecture/release-commit-plan-2026-05-18.md`
- `tests/Feature/Frontend`
- `.gitignore`
- `phpunit.xml`

Validacion focal:

```bash
php artisan test tests/Feature/Frontend
php artisan frontend:uat-release-readiness --freshness-hours=24 --json
```

Nota: la segunda validacion debe quedar `ready_for_release` solo despues de refrescar evidencia UAT.

## Commit 7 opcional - RBAC y seeders

Objetivo: si se quiere aislar seguridad/RBAC del resto, separar seeders y permisos.

Archivos principales:

- `database/migrations/2026_03_10_235537_create_rbac_tables.php`
- `database/seeders/PermissionSeeder.php`
- `database/seeders/RbacCatalogSeeder.php`
- `database/seeders/RolePermissionSeeder.php`
- `database/seeders/RoleSeeder.php`
- `tests/Feature/Security`
- `tests/Feature/Auth/ApiTokenAuthFlowTest.php`
- `app/Services/Security/ApiTokenService.php`

Validacion focal:

```bash
php artisan test tests/Feature/Security tests/Feature/Auth
```

## Validacion final antes de PR

Ejecutar en este orden:

```bash
composer run velmix:lint:full
npm run typecheck
npm run lint
npm run test
npm run build
composer run velmix:audit
composer run velmix:ci
```

Si el pipeline MySQL esta disponible:

```bash
composer run velmix:ci:mysql
```

## Refresh obligatorio de evidencia UAT

Como la evidencia esta stale al 2026-05-18, antes de declarar salida real:

1. Levantar local/UAT contra el ambiente objetivo.
2. Ejecutar `php artisan frontend:seed-pos-smoke --json`.
3. Ejecutar `php artisan frontend:pos-quote-first-uat-smoke --json`.
4. Ejecutar `php artisan frontend:uat-signoff-pack --base-url=<url> --json`.
5. Recorrer visualmente POS, caja, cartera, catalogo y clientes.
6. Capturar screenshots, network captures y request IDs frescos.
7. Registrar firma humana nueva.
8. Ejecutar `php artisan frontend:uat-visual-evidence-verify --json`.
9. Persistir `VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true` en el ambiente objetivo.
10. Ejecutar `php artisan frontend:uat-release-closure-pack --freshness-hours=24 --decision-owner="Luis Retuerto" --decision-ticket="<ticket>" --decision-notes="<nota>" --json`.

## Criterio de no-go

No aprobar cutover productivo si cualquiera de estos puntos ocurre:

- `frontend:uat-release-readiness` esta `blocked`.
- El closure usa `--allow-gate-disabled` o `--allow-observability-critical`.
- Falta evidencia visual fresca de algun modulo.
- No se ejecuto la lane MySQL/concurrencia antes de produccion.
- El diff incluye artefactos locales o temporales.
