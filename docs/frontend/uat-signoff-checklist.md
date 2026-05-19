# Frontend UAT signoff checklist

## Control del documento

Este checklist formaliza la aceptacion visual y operativa del frontend local/UAT para los modulos POS, caja, cartera, catalogo y clientes. Debe ejecutarse con evidencia trazable antes de declarar el frontend listo para demo operativa, piloto o salida controlada.

| Campo | Valor |
|:---|:---|
| Ambiente | |
| URL | |
| Fecha de ejecucion | |
| Tenant | |
| Usuario ejecutor | |
| Rol/RBAC | |
| Version backend | |
| Version frontend | |
| Seed ejecutado | `php artisan frontend:seed-pos-smoke --json` |
| Smoke transaccional | `php artisan frontend:pos-quote-first-uat-smoke --json` |
| Paquete firmable | `php artisan frontend:uat-signoff-pack --json` |
| Plantilla evidencia visual | `php artisan frontend:uat-visual-evidence-template --json` |
| Verificacion firma visual | `php artisan frontend:uat-visual-evidence-verify --json` |
| Gate salida frontend | `php artisan frontend:uat-release-readiness --json` |
| Paquete cierre profesional | `php artisan frontend:uat-release-closure-pack --json` |
| Overrides dry-run | `--allow-gate-disabled` / `--allow-observability-critical` solo local/UAT |
| Runbook base | `docs/frontend/pos-quote-first-smoke-runbook.md` |
| Ticket/Evidencia | |

## Evidencia tecnica minima

- [ ] `php artisan frontend:seed-pos-smoke --json` ejecutado sin errores.
- [ ] `php artisan frontend:uat-readiness --json` responde `status=ready`.
- [ ] `php artisan frontend:pos-quote-first-uat-smoke --json` responde `status=passed`.
- [ ] Evidencia transaccional adjunta: `storage/app/frontend-uat/pos-quote-first-smoke-latest.json`.
- [ ] `php artisan frontend:uat-signoff-pack --json` responde `status=ready_for_visual_signoff`.
- [ ] Paquete firmable adjunto: `storage/app/frontend-uat/signoff/frontend-uat-signoff-latest.md`.
- [ ] `php artisan frontend:uat-visual-evidence-template --json` genera `status=draft`.
- [ ] Evidencia visual completada: `storage/app/frontend-uat/signoff/frontend-uat-visual-evidence-latest.json`.
- [ ] `php artisan frontend:uat-visual-evidence-verify --json` responde `status=signed`.
- [ ] Reporte de firma visual adjunto: `storage/app/frontend-uat/signoff/frontend-uat-visual-signoff-latest.md`.
- [ ] `php artisan frontend:uat-release-readiness --freshness-hours=24 --json` responde `status=ready_for_release`.
- [ ] Gate global habilitado cuando aplique: `VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true`.
- [ ] `php artisan system:preflight --json --fail-on-critical` no reporta `frontend_uat_release_not_ready`.
- [ ] `php artisan system:observability-report --json` no responde `status=critical`.
- [ ] `php artisan frontend:uat-release-closure-pack --json` responde `status=ready_for_release_closure`.
- [ ] `go_no_go.production_go_allowed=true` en `storage/app/frontend-uat/closure/frontend-uat-release-closure-latest.json`.
- [ ] El paquete final no usa `allow_gate_disabled=true` ni `allow_observability_critical=true` para aprobacion productiva.
- [ ] Paquete final adjunto: `storage/app/frontend-uat/closure/frontend-uat-release-closure-latest.md`.
- [ ] `GET /health/live` responde `200`.
- [ ] `GET /health/ready` responde `200`.
- [ ] `GET /app/pos/sales?tenant=botica-central` sirve el shell React con bootstrap.
- [ ] Login web exitoso en `/app/login?tenant=botica-central&redirect=/pos/sales`.
- [ ] Captura de pantalla inicial de la SPA autenticada.
- [ ] Captura de Network filtrada por `pricing/quotes`, `cash/sessions`, `sales/receivables`, `inventory/products` y `sales/customers`.
- [ ] Request ID registrado para cada flujo critico validado.
- [ ] No se detectan errores JavaScript en consola durante los flujos firmados.

## POS

### Alcance UAT

Validar que el POS sea quote-first: el usuario cotiza con pricing/promotions, revisa totales, confirma checkout y nunca usa precio libre desde el frontend.

### Checklist funcional

- [ ] El usuario ingresa a `/app/pos/sales?tenant=botica-central` autenticado y con tenant correcto.
- [ ] La pantalla muestra estado operativo claro: caja, cliente, metodo de pago, carrito, quote y ventas recientes.
- [ ] La busqueda rapida encuentra `SMOKE-POS-REG-001` por SKU y por nombre.
- [ ] La busqueda rapida encuentra `SMOKE-POS-RX-001` e identifica que es producto controlado.
- [ ] El usuario agrega producto regular al carrito sin recargar la pagina.
- [ ] El usuario edita cantidad con controles visuales y el carrito refleja el cambio.
- [ ] `Cotizar venta POS` ejecuta `POST /pricing/quotes` con `Idempotency-Key`.
- [ ] La UI muestra quote ID, lista de precios, subtotal, descuento, total, TTL y promociones aplicadas.
- [ ] La promocion `SMOKE-PROMO10` aplica 10% al producto regular cuando corresponde.
- [ ] El checkout ejecuta `POST /pricing/quotes/{quote}/checkout` con `quote_hash` e `Idempotency-Key`.
- [ ] El navegador no ejecuta `POST /pos/sales` como flujo primario del POS.
- [ ] Venta `card` queda registrada y visible en ventas recientes.
- [ ] Venta `cash` exige caja abierta y actualiza caja.
- [ ] Venta `credit` exige cliente y fecha de vencimiento, y actualiza cartera.
- [ ] Quote expirado o rechazado muestra estado recuperable, `Request ID` y accion de recotizar.
- [ ] Usuario sin `pricing.quote.create` no puede cotizar.
- [ ] Usuario sin `pos.sale.execute` no puede confirmar venta.

### Evidencia obligatoria

- [ ] Captura de quote antes del checkout.
- [ ] Captura de Network de `POST /pricing/quotes`.
- [ ] Captura de Network de `POST /pricing/quotes/{quote}/checkout`.
- [ ] Captura de venta registrada.
- [ ] Quote ID:
- [ ] Sale reference:
- [ ] Request ID quote:
- [ ] Request ID checkout:

### Firma POS

| Rol | Nombre | Firma | Fecha | Resultado |
|:---|:---|:---|:---|:---|
| Cajero UAT | | | | |
| Lider negocio | | | | |
| QA/UAT | | | | |

## Caja

### Alcance UAT

Validar apertura, lectura, movimientos, ledger y consistencia de caja luego de ventas POS.

### Checklist funcional

- [ ] El usuario ingresa a `/app/cash/sessions?tenant=botica-central`.
- [ ] La pantalla muestra caja abierta creada por el seed o permite abrir una nueva.
- [ ] La apertura registra monto inicial y operador.
- [ ] La sesion abierta muestra esperado, movimientos y estado.
- [ ] Venta POS `cash` incrementa el esperado de caja por el total exacto.
- [ ] El ledger muestra movimiento asociado a la venta.
- [ ] Los totales de caja no dependen de calculos temporales del frontend.
- [ ] Los errores de caja cerrada o caja inexistente se muestran como bloqueo recuperable.
- [ ] La UI no permite cerrar caja sin confirmacion visual.
- [ ] El cierre deja evidencia de diferencia, esperado y contado cuando el flujo este habilitado.

### Evidencia obligatoria

- [ ] Captura de caja antes de venta cash.
- [ ] Captura de venta cash confirmada.
- [ ] Captura de caja despues de venta cash.
- [ ] Cash session ID:
- [ ] Cash movement/ledger reference:
- [ ] Request ID:

### Firma Caja

| Rol | Nombre | Firma | Fecha | Resultado |
|:---|:---|:---|:---|:---|
| Cajero UAT | | | | |
| Supervisor caja | | | | |
| QA/UAT | | | | |

## Cartera

### Alcance UAT

Validar que ventas a credito generen cuentas por cobrar, estados correctos, aging y pagos trazables.

### Checklist funcional

- [ ] El usuario ingresa a `/app/sales/receivables?tenant=botica-central`.
- [ ] La pantalla carga resumen/aging sin errores.
- [ ] Venta POS `credit` crea receivable `pending`.
- [ ] `outstanding_amount` coincide con el total de la venta.
- [ ] La fecha de vencimiento coincide con la ingresada en POS.
- [ ] El detalle de receivable muestra venta, cliente, saldo y estado.
- [ ] Pago parcial reduce saldo y conserva historico.
- [ ] Pago total cambia estado a pagado/cerrado segun contrato vigente.
- [ ] Follow-up tipo nota se registra y queda visible.
- [ ] Follow-up tipo promesa exige monto/fecha cuando aplica.
- [ ] Los filtros por estado, cliente y vencimiento retornan resultados coherentes.
- [ ] Errores 409/422 muestran `Request ID` y no duplican pagos.

### Evidencia obligatoria

- [ ] Captura de receivable creado desde POS credit.
- [ ] Captura de aging/resumen.
- [ ] Captura de pago o follow-up.
- [ ] Receivable ID:
- [ ] Customer ID:
- [ ] Request ID:

### Firma Cartera

| Rol | Nombre | Firma | Fecha | Resultado |
|:---|:---|:---|:---|:---|
| Analista cartera | | | | |
| Lider negocio | | | | |
| QA/UAT | | | | |

## Catalogo

### Alcance UAT

Validar catalogo de productos usable para POS: busqueda, datos comerciales necesarios, control sanitario y estado operativo.

### Checklist funcional

- [ ] El usuario ingresa a `/app/inventory/products?tenant=botica-central`.
- [ ] La lista carga productos activos sin errores.
- [ ] `SMOKE-POS-REG-001` aparece con SKU, nombre y estado.
- [ ] `SMOKE-POS-RX-001` aparece marcado como controlado.
- [ ] La busqueda por SKU y nombre filtra sin recarga completa.
- [ ] El detalle o fila del producto muestra informacion suficiente para decidir venta.
- [ ] El producto regular usado en POS tiene precio comercial via quote, no precio libre en catalogo.
- [ ] Producto controlado exige datos adicionales en checkout cuando corresponde.
- [ ] Usuario sin permiso de creacion no ve acciones de alta/edicion destructiva.
- [ ] Errores de carga muestran estado recuperable y `Request ID`.

### Evidencia obligatoria

- [ ] Captura de catalogo filtrado por `SMOKE-POS-REG-001`.
- [ ] Captura de producto controlado.
- [ ] Product ID regular:
- [ ] Product ID controlado:
- [ ] Request ID:

### Firma Catalogo

| Rol | Nombre | Firma | Fecha | Resultado |
|:---|:---|:---|:---|:---|
| Responsable catalogo | | | | |
| Lider negocio | | | | |
| QA/UAT | | | | |

## Clientes

### Alcance UAT

Validar clientes como soporte de POS credit y cartera: busqueda, alta/edicion controlada, cupo y statement.

### Checklist funcional

- [ ] El usuario ingresa a `/app/sales/customers?tenant=botica-central`.
- [ ] El cliente `Smoke Farmacia UAT` aparece activo.
- [ ] La busqueda por documento `20999999001` retorna el cliente esperado.
- [ ] La busqueda por nombre retorna el cliente esperado.
- [ ] Alta de cliente valida documento, nombre y email.
- [ ] Edicion de cliente respeta permisos y no borra credito accidentalmente.
- [ ] Credit limit y credit days se muestran con formato entendible.
- [ ] Cliente bloqueado o inactivo no puede usarse para credito POS.
- [ ] Statement del cliente refleja venta credit y receivable generado.
- [ ] Errores 409/422 muestran `Request ID` y no duplican clientes.

### Evidencia obligatoria

- [ ] Captura de cliente smoke.
- [ ] Captura de statement luego de venta credit.
- [ ] Customer ID:
- [ ] Sale reference:
- [ ] Request ID:

### Firma Clientes

| Rol | Nombre | Firma | Fecha | Resultado |
|:---|:---|:---|:---|:---|
| Responsable clientes | | | | |
| Lider negocio | | | | |
| QA/UAT | | | | |

## Criterios globales de aprobacion

- [ ] Todos los modulos criticos fueron recorridos con usuario autenticado.
- [ ] Cada flujo critico tiene captura de UI y Network.
- [ ] Cada flujo con escritura usa `Idempotency-Key` cuando corresponde.
- [ ] No hay duplicidad de ventas, pagos, clientes o movimientos al reintentar.
- [ ] Las pantallas muestran errores recuperables con `Request ID`.
- [ ] El frontend no rompe contratos OpenAPI vigentes.
- [ ] El flujo POS queda formalmente aprobado como quote-first.
- [ ] Las brechas se registraron como tickets con severidad, modulo, evidencia y owner.

## Resultado final

| Resultado | Marcar |
|:---|:---|
| Aprobado sin observaciones | [ ] |
| Aprobado con observaciones no bloqueantes | [ ] |
| Rechazado / bloqueante para salida | [ ] |

## Firmas finales

| Rol | Nombre | Firma | Fecha | Comentarios |
|:---|:---|:---|:---|:---|
| Product Owner | | | | |
| Operaciones | | | | |
| QA/UAT | | | | |
| Tecnologia | | | | |
