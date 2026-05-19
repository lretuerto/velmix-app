# POS quote-first smoke runbook

## Objetivo

Validar manualmente que el POS frontend opera sobre el contrato comercial correcto:

- Buscar producto activo desde la pantalla real.
- Generar `POST /pricing/quotes` antes de confirmar venta.
- Revisar precio base, promociones, total y TTL del quote.
- Confirmar `POST /pricing/quotes/{quote}/checkout` con `Idempotency-Key`.
- Verificar que la venta resultante aparezca en POS, caja y cartera cuando aplique.
- Capturar evidencia minima para rollback o soporte.

Este runbook complementa las pruebas automatizadas y debe ejecutarse antes de considerar el frontend POS listo para demo operativa o UAT.

Para cierre formal por modulo, usar tambien:

```text
docs/frontend/uat-signoff-checklist.md
```

## Alcance y restricciones

- No usar `POST /pos/sales` como flujo primario del POS visual.
- No editar datos productivos durante smoke sin autorizacion de negocio.
- No usar productos controlados sin registrar `prescription_code` o `approval_code`.
- No confirmar ventas cash si no hay una caja abierta para el tenant.
- No repetir checkout con distinto payload bajo el mismo `Idempotency-Key`.

## Preconditions

1. Backend y frontend construyen sin errores:

```bash
composer run velmix:qa
npm run test
npm run typecheck
npm run lint
npm run build
```

2. Usuario autenticado con permisos:

```text
pos.sale.read
pos.sale.execute
pricing.quote.create
pricing.quote.read
inventory.product.read
sales.customer.read
cash.session.read
```

3. Tenant activo con al menos:

- Un producto `active` con stock disponible.
- Una lista de precios default o aplicable al canal `retail`.
- Si se valida venta cash: una sesion de caja abierta.
- Si se valida venta credit: un cliente activo con cupo suficiente.

Para preparar esos datos de forma reproducible en local/UAT, ejecutar:

```bash
php artisan frontend:seed-pos-smoke --json
```

Despues del seed, validar que el ambiente esta listo para UAT visual sin mutar datos:

```bash
php artisan frontend:uat-readiness --json
```

Antes del recorrido visual firmado, ejecutar el smoke transaccional quote-first. Este comando crea ventas smoke en local/UAT, valida quote, checkout, caja, cartera, producto controlado y escribe evidencia JSON versionada:

```bash
php artisan frontend:pos-quote-first-uat-smoke --json
```

Evidencia generada:

```text
storage/app/frontend-uat/pos-quote-first-smoke-latest.json
```

El estado `passed` confirma el flujo transaccional. El campo `signoff.status=pending_visual_review` recuerda que aun falta la firma humana de UI/Network.

Con readiness y smoke en verde, generar el paquete firmable:

```bash
php artisan frontend:uat-signoff-pack --base-url=http://127.0.0.1:8010 --json
```

Artefactos generados:

```text
storage/app/frontend-uat/signoff/frontend-uat-signoff-latest.md
storage/app/frontend-uat/signoff/frontend-uat-signoff-latest.json
```

El estado `ready_for_visual_signoff` habilita el recorrido humano de UI/Network. No equivale a firma final; los responsables deben completar las capturas y firmas del paquete.

Generar la plantilla JSON/Markdown donde se cargan evidencias visuales reales:

```bash
php artisan frontend:uat-visual-evidence-template --json
```

Artefactos generados:

```text
storage/app/frontend-uat/signoff/frontend-uat-visual-evidence-latest.md
storage/app/frontend-uat/signoff/frontend-uat-visual-evidence-latest.json
```

Despues de completar el JSON con capturas, HAR/request IDs y firmas humanas, verificar la firma visual:

```bash
php artisan frontend:uat-visual-evidence-verify --json
```

El unico cierre visual aceptado es `status=signed` en:

```text
storage/app/frontend-uat/signoff/frontend-uat-visual-signoff-latest.json
storage/app/frontend-uat/signoff/frontend-uat-visual-signoff-latest.md
```

Finalmente, validar el gate agregado de salida frontend. Este comando es read-only y consolida smoke transaccional, paquete UAT y firma visual:

```bash
php artisan frontend:uat-release-readiness --freshness-hours=24 --json
```

El unico resultado aceptado para avanzar a release/cutover frontend es:

```text
status=ready_for_release
```

Para que el preflight global bloquee un cutover cuando falte esta evidencia, habilitar el gate por ambiente:

```bash
VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true
VELMIX_FRONTEND_UAT_RELEASE_GATE_REQUIRED_ENVS=staging,production
VELMIX_FRONTEND_UAT_RELEASE_GATE_FRESHNESS_HOURS=24
```

Luego validar:

```bash
php artisan system:preflight --json --fail-on-critical
php artisan system:observability-report --json
```

Con gate global activo y evidencia firmada, generar el paquete final de cierre:

```bash
php artisan frontend:uat-release-closure-pack --freshness-hours=24 --json
```

Para cierre productivo no usar flags de bypass. `--allow-gate-disabled` y `--allow-observability-critical` quedan reservados para dry-runs locales/UAT con aprobacion explicita, y el artefacto deja esos overrides visibles.

Artefactos finales:

```text
storage/app/frontend-uat/closure/frontend-uat-release-closure-latest.json
storage/app/frontend-uat/closure/frontend-uat-release-closure-latest.md
```

El cierre profesional solo queda listo si responde:

```text
status=ready_for_release_closure
go_no_go.production_go_allowed=true
```

Si `status=ready_for_release_closure` aparece con `go_no_go.production_go_allowed=false`, el paquete solo sirve como ensayo local/UAT. No debe usarse como aprobacion de salida productiva.

El comando es idempotente y crea/actualiza:

- Usuario smoke `pos-smoke@velmix.test` con rol `CAJERO`.
- Producto regular `SMOKE-POS-REG-001`.
- Producto controlado `SMOKE-POS-RX-001`.
- Lotes disponibles para ambos productos.
- Precio retail para ambos productos en la lista default existente o en `SMOKE-RETAIL` si no habia default.
- Promocion `SMOKE-PROMO10` con 10% de descuento para el producto regular.
- Cliente `Smoke Farmacia UAT`.
- Caja abierta, salvo que se use `--skip-cash`.

Opciones utiles:

```bash
php artisan frontend:seed-pos-smoke --tenant=10 --user-email=pos-smoke@velmix.test --password="cambiar-en-uat" --opening-amount=1000 --json
```

El comando se bloquea en `production` salvo uso explicito de `--force-production`.

4. Iniciar sesion web en:

```text
/app/login?tenant=botica-central&redirect=/pos/sales
```

Credenciales smoke local/UAT por defecto:

```text
email: pos-smoke@velmix.test
password: pos-smoke-local-only
```

En UAT compartido, preferir pasar un password explicito al seed o configurar `VELMIX_POS_SMOKE_PASSWORD`.

5. Ventana de DevTools abierta en `Network` filtrando:

```text
pricing/quotes
pos/sales
cash/sessions
sales/receivables
```

## Smoke A: venta card sin dependencia de caja

Usar este camino como smoke rapido porque evita depender de caja abierta.

1. Abrir:

```text
/pos/sales
```

2. Confirmar que se ve:

- `Ventas POS`
- `Cotizar y vender`
- `Sin cotizacion activa`

3. En `Metodo de pago`, seleccionar:

```text
card
```

4. Buscar un producto activo por SKU o nombre.

5. Agregarlo desde `Busqueda rapida de productos`.

6. Ajustar cantidad con los botones `+` y `-`.

7. Click en:

```text
Cotizar venta POS
```

8. Validar en la UI:

- Aparece `Quote #...`
- Aparece lista de precios.
- Aparecen subtotal, descuento y total.
- Si hay promocion, aparece en `Promociones aplicadas`.
- El boton `Confirmar venta con quote` esta habilitado.

9. Validar en Network:

- Existe `POST /pricing/quotes`.
- Incluye `Idempotency-Key`.
- No existe `POST /pos/sales` antes de confirmar.

10. Click en:

```text
Confirmar venta con quote
```

11. Validar en Network:

- Existe `POST /pricing/quotes/{quote}/checkout`.
- Incluye `quote_hash`.
- Incluye `Idempotency-Key`.
- No se usa `POST /pos/sales` desde el navegador.

12. Validar en UI:

- Toast `Venta registrada`.
- La tabla muestra la referencia de venta.
- El detalle muestra total, costo, margen y items vendidos.

## Smoke B: venta cash con caja

Usar este camino cuando se quiera validar impacto en caja.

1. Abrir `/cash/sessions`.

2. Si no hay caja abierta, abrir una sesion con monto de apertura controlado.

3. Volver a `/pos/sales`.

4. Repetir el Smoke A con `Metodo de pago = cash`.

5. Volver a `/cash/sessions`.

6. Validar:

- Esperado de caja aumenta por el total de la venta.
- El ledger de caja contiene la entrada de la venta.
- El resumen no depende solo de la ventana temporal, sino del ledger.

## Smoke C: venta credit con cartera

Usar este camino para validar cuentas por cobrar.

1. Abrir `/sales/customers`.

2. Confirmar cliente activo con cupo disponible.

3. Abrir `/pos/sales`.

4. Seleccionar:

```text
Metodo de pago = credit
Cliente = cliente activo
Vence el = fecha valida
```

5. Cotizar y confirmar la venta.

6. Abrir `/sales/receivables`.

7. Validar:

- Aparece cuenta por cobrar `pending`.
- `outstanding_amount` coincide con total de venta.
- El statement del cliente refleja la venta y la deuda.

## Smoke D: quote expirado o checkout rechazado

1. Generar un quote.

2. Simular expiracion esperando TTL o usando datos que provoquen rechazo controlado en ambiente local.

3. Confirmar que la UI:

- Muestra `Quote expirado` cuando aplica.
- Deshabilita `Confirmar venta con quote` cuando el snapshot ya expiro.
- Ofrece `Recotizar carrito`.
- Si el checkout falla, conserva el quote visible y muestra `Request ID`.

## Evidencia minima

Registrar en el ticket o documento de UAT:

- Ambiente y URL.
- Tenant.
- Usuario y roles principales.
- SKU usado.
- Metodo de pago.
- Quote ID.
- Sale reference.
- Request ID del quote.
- Request ID del checkout.
- Captura de Network con `POST /pricing/quotes`.
- Captura de Network con `POST /pricing/quotes/{quote}/checkout`.
- Captura de UI del detalle de venta.
- Si cash: captura de caja/ledger.
- Si credit: captura de receivable/statement.

## Criterios de aceptacion

- El flujo visual nunca define precio comercial desde `POST /pos/sales`.
- El quote muestra total final y promociones antes del checkout.
- El checkout consume `quote_hash` y no recalcula desde inputs libres del usuario.
- El checkout exitoso invalida/read-refreshea POS, caja, cartera y clientes segun aplique.
- Errores `409` y `422` son visibles, trazables y recuperables.
- Usuarios sin `pricing.quote.create` no pueden iniciar checkout POS.
- Usuarios sin `pos.sale.execute` no pueden confirmar venta.

## Rollback seguro

Si el smoke falla en UAT:

1. No seguir confirmando ventas en ese tenant.
2. Capturar `Request ID`, quote ID y payload resumido.
3. Validar si la falla es visual, contrato HTTP o dato maestro.
4. Si hay venta creada, usar flujo formal de anulacion o credit note segun corresponda.
5. Revertir solo el frontend deployado si el backend checkout sigue sano.
6. Si hay inconsistencia de caja/cartera, bloquear cutover y ejecutar auditoria:

```bash
php artisan cash:ledger-audit --json
php artisan system:preflight --json --fail-on-warning
```

## Automatizacion relacionada

- `resources/js/modules/pos/sales/pages/PosSaleIndexPage.test.tsx`
- `resources/js/modules/pos/sales/components/PosSaleForm.test.tsx`
- `resources/js/modules/pos/sales/components/PosQuotePreview.test.tsx`
- `tests/Feature/Pricing/PricingCheckoutApiTest.php`
- `tests/Feature/Pricing/PricingCheckoutServiceTest.php`
