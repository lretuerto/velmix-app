# Sprint 0 Frontend Base

## Objetivo del sprint

Dejar el frontend profesionalmente arrancado dentro del monorepo Laravel, con shell React + TypeScript, bootstrap server-side, cliente API centralizado, guards por permisos y estructura modular suficiente para empezar Sprint 1 sin rehacer base.

## Estado actual

### Implementado en este sprint

- `package.json`
  - agrega stack React + TypeScript, testing y linting del frontend.
- `vite.config.js`
  - habilita React, alias `@`, Vitest y nuevo entry `resources/js/app.tsx`.
- `tsconfig.json`
  - activa TypeScript estricto para `resources/js`.
- `.eslintrc.cjs`
  - configura lint base para TypeScript del frontend.
- `resources/css/app.css`
  - incorpora fuentes, tokens y discovery de `.ts/.tsx`.
- `resources/css/tokens.css`
  - define la paleta y tokens visuales del shell.
- `resources/views/app.blade.php`
  - crea el contenedor SPA y expone `window.__VELMIX_BOOT__`.
- `routes/web/platform.php`
  - sirve la SPA bajo `/app` sin romper la landing actual.
- `app/Services/Frontend/AppShellBootstrapService.php`
  - resuelve usuario, memberships, tenant activo, roles y permisos para el bootstrap web.
- `resources/js/app.tsx`
  - entry principal React + Router + providers.
- `resources/js/bootstrap.ts`
  - bootstrap HTTP base para navegador.
- `resources/js/core/app/*`
  - boot model, context y providers globales.
- `resources/js/core/api/*`
  - envelope typing, cliente Axios y normalizacion de errores.
- `resources/js/core/query/client.ts`
  - QueryClient base para data fetching.
- `resources/js/core/auth/*`
  - helpers de permisos y boundary de acceso.
- `resources/js/core/router/index.tsx`
  - router modular de arranque.
- `resources/js/core/ui/layout/*`
  - shell principal y tenant switcher.
- `resources/js/core/ui/feedback/StatePanel.tsx`
  - estados reutilizables de shell.
- `resources/js/modules/home/pages/WorkspaceHomePage.tsx`
  - home operacional de Sprint 0.
- `resources/js/modules/platform/pages/PlatformOverviewPage.tsx`
  - placeholder de dashboard operacional.
- `resources/js/modules/inventory/products/pages/ProductIndexPage.tsx`
  - placeholder de maestro de productos.
- `resources/js/modules/sales/customers/pages/CustomerIndexPage.tsx`
  - placeholder de maestro de clientes.
- `resources/js/shared/components/*`
  - primitives base de presentación.
- `resources/js/shared/utils/cn.ts`
  - helper de clases utilitario.
- `resources/js/test/setup.ts`
  - setup de pruebas frontend.
- `resources/js/core/auth/permissions.test.ts`
  - primer test del scaffold.

## Convencion operativa

- La SPA vive bajo `/app`.
- El tenant activo viaja por query string: `/app?tenant=<tenant-code>`.
- El backend sigue exigiendo `X-Tenant-Id` para API; el cliente lo inyecta desde el bootstrap.
- No existe todavía login web dedicado. La app soporta `guest shell` de forma explícita.

## Backlog archivo por archivo para Sprint 1

### Fase 1. Platform dashboard real

1. `resources/js/modules/platform/api/observability.ts`
   - query de `GET /reports/platform-observability`.
2. `resources/js/modules/platform/api/controlTower.ts`
   - query de `GET /reports/operations-control-tower/briefing`.
3. `resources/js/modules/platform/hooks/usePlatformObservability.ts`
   - hook TanStack Query para observabilidad.
4. `resources/js/modules/platform/hooks/useControlTowerBriefing.ts`
   - hook TanStack Query para briefing operativo.
5. `resources/js/modules/platform/components/HealthGateGrid.tsx`
   - cards de health gates.
6. `resources/js/modules/platform/components/RecoveryStatusCard.tsx`
   - recuperación, backup y restore.
7. `resources/js/modules/platform/components/OperationalCertificationCard.tsx`
   - estado de promotion, cutover y operational certification.
8. `resources/js/modules/platform/pages/PlatformOverviewPage.tsx`
   - reemplazar placeholder por dashboard funcional.

### Fase 2. Inventory products usable

1. `resources/js/modules/inventory/products/types.ts`
   - tipos de producto y payloads.
2. `resources/js/modules/inventory/products/api/products.ts`
   - `GET /inventory/products` y `POST /inventory/products`.
3. `resources/js/modules/inventory/products/hooks/useProducts.ts`
   - listado cacheado.
4. `resources/js/modules/inventory/products/hooks/useCreateProduct.ts`
   - mutación con invalidación.
5. `resources/js/modules/inventory/products/components/ProductTable.tsx`
   - tabla principal.
6. `resources/js/modules/inventory/products/components/ProductCreateForm.tsx`
   - alta con `react-hook-form` + `zod`.
7. `resources/js/modules/inventory/products/schema.ts`
   - validación local del formulario.
8. `resources/js/modules/inventory/products/pages/ProductIndexPage.tsx`
   - vista funcional completa.

### Fase 3. Sales customers usable

1. `resources/js/modules/sales/customers/types.ts`
   - tipos de cliente y statement.
2. `resources/js/modules/sales/customers/api/customers.ts`
   - `GET`, `POST`, `PATCH`, `statement`.
3. `resources/js/modules/sales/customers/hooks/useCustomers.ts`
   - query de listado.
4. `resources/js/modules/sales/customers/hooks/useCustomerStatement.ts`
   - query detalle/estado de cuenta.
5. `resources/js/modules/sales/customers/hooks/useUpsertCustomer.ts`
   - create/update con invalidación.
6. `resources/js/modules/sales/customers/components/CustomerTable.tsx`
   - tabla y filtros.
7. `resources/js/modules/sales/customers/components/CustomerDrawer.tsx`
   - alta/edición.
8. `resources/js/modules/sales/customers/components/CustomerStatementPanel.tsx`
   - estado de cuenta.
9. `resources/js/modules/sales/customers/schema.ts`
   - validación local del formulario.
10. `resources/js/modules/sales/customers/pages/CustomerIndexPage.tsx`
   - integración funcional del módulo.

### Fase 4. Hardening transversal

1. `resources/js/core/api/client.ts`
   - agregar helper explícito para `Idempotency-Key`.
2. `resources/js/core/api/errors.ts`
   - mapear `400/403/404/409/422` a mensajes UX por dominio.
3. `resources/js/core/ui/feedback/ToastRegion.tsx`
   - región de notificaciones.
4. `resources/js/core/ui/layout/AppLayout.tsx`
   - integrar toasts, loading global y search palette inicial.
5. `resources/js/core/router/index.tsx`
   - agregar `errorElement` por ruta.
6. `resources/js/test/handlers.ts`
   - MSW handlers para platform, products y customers.
7. `resources/js/modules/platform/pages/PlatformOverviewPage.test.tsx`
   - render y estados.
8. `resources/js/modules/inventory/products/pages/ProductIndexPage.test.tsx`
   - listado y alta.
9. `resources/js/modules/sales/customers/pages/CustomerIndexPage.test.tsx`
   - listado y edición.

## Gate de salida de Sprint 1

- `npm run typecheck`
- `npm run lint`
- `npm run test`
- `npm run build`
- vista `/app` usable con tenant seleccionado
- dashboard platform con datos reales
- productos funcional
- clientes funcional
