# Guia API VELMiX

## Objetivo

Esta guia resume como consumir el backend actual de VELMiX sin depender de inspeccionar manualmente cada test o servicio.

## Convenciones base

- Autenticacion: los endpoints de negocio pasan por `auth.hybrid`
- El backend acepta sesion Laravel o `Authorization: Bearer <token>`
- Si una request trae sesion y bearer token al mismo tiempo, se evalua el bearer token
- Contexto tenant: enviar `X-Tenant-Id`
- Formato de salida: casi todos responden `{"data": ...}`
- Errores esperados:
  - `400` si falta `X-Tenant-Id`
  - `403` si el usuario no pertenece al tenant o no tiene permiso
  - `404` si el recurso no existe dentro del tenant
  - `422` si falla la validacion o una regla de negocio

## Flujos prioritarios

### Tenant y seguridad

- `GET /auth/me`
- `GET /auth/tokens`
- `POST /auth/tokens`
- `DELETE /auth/tokens/{token}`
- `GET /tenant/ping`
- `GET /rbac/permissions`

### Inventario

- `GET /inventory/products`
- `POST /inventory/products`
- `GET /inventory/lots/{lot}`
- `POST /inventory/lots`
- `POST /inventory/lots/{lot}/immobilize`
- `GET /inventory/movements`
- `POST /stock/movements`

### POS y ventas

- `POST /pos/sales`
- `GET /pos/sales`
- `GET /pos/sales/{sale}`
- `POST /pos/sales/{sale}/cancel`
- `POST /pos/approvals`

### Clientes y cuentas por cobrar

- `GET /sales/customers`
- `POST /sales/customers`
- `PATCH /sales/customers/{customer}`
- `GET /sales/customers/{customer}/statement`
- `GET /sales/receivables`
- `GET /sales/receivables/{receivable}`
- `POST /sales/receivables/{receivable}/payments`
- `POST /sales/receivables/{receivable}/follow-ups`

### Caja

- `POST /cash/sessions/open`
- `GET /cash/sessions/current`
- `POST /cash/sessions/current/close`
- `POST /cash/movements`
- `GET /cash/sessions/{session}/movements`

### Compras

- `GET /purchases/suppliers`
- `POST /purchases/suppliers`
- `GET /purchases/orders`
- `POST /purchases/orders`
- `POST /purchases/orders/from-replenishment`
- `GET /purchases/receipts`
- `POST /purchases/receipts`
- `POST /purchases/receipts/{receipt}/returns`
- `GET /purchases/payables`
- `POST /purchases/payables/{payable}/payments`
- `POST /purchases/payables/{payable}/apply-credits`

### Billing

- `POST /billing/vouchers`
- `GET /billing/vouchers/{voucher}`
- `GET /billing/vouchers/{voucher}/payloads`
- `POST /billing/vouchers/{voucher}/payloads/regenerate`
- `POST /billing/vouchers/{voucher}/replay`
- `POST /billing/credit-notes`
- `GET /billing/credit-notes/{creditNote}`
- `GET /billing/credit-notes/{creditNote}/payloads`
- `POST /billing/credit-notes/{creditNote}/payloads/regenerate`
- `POST /billing/credit-notes/{creditNote}/replay`
- `GET /billing/provider-profile`
- `PUT /billing/provider-profile`
- `POST /billing/provider-profile/check`
- `POST /billing/outbox/dispatch`
- `GET /billing/outbox/summary`
- `GET /billing/outbox/provider-trace`
- `GET /billing/provider-metrics`
- `POST /billing/outbox/{event}/retry`
- `GET /billing/outbox/{event}/lineage`
- `GET /billing/outbox/{event}/attempts`

### Reportes y auditoria

- `GET /reports/daily`
- `GET /reports/operations-control-tower`
- `GET /reports/operations-control-tower/briefing`
- `GET /reports/operations-control-tower/briefing/export`
- `GET /reports/operations-control-tower/history`
- `GET /reports/operations-control-tower/compare`
- `POST /reports/operations-control-tower/snapshots`
- `GET /reports/operations-control-tower/snapshots`
- `GET /reports/operations-control-tower/snapshots/{snapshot}`
- `GET /reports/operations-control-tower/snapshots/{snapshot}/export`
- `GET /reports/operations-control-tower/snapshots/{snapshot}/compare`
- `GET /reports/operations-control-tower/snapshots/{snapshot}/compare/export`
- `GET /reports/billing-operations`
- `GET /reports/billing-escalations`
- `GET /reports/billing-escalation-metrics`
- `GET /reports/finance-operations`
- `GET /reports/finance-escalations`
- `GET /reports/finance-escalations/history`
- `GET /reports/finance-escalation-metrics`
- `GET /reports/finance-escalations/{code}`
- `POST /reports/finance-escalations/{code}/acknowledge`
- `POST /reports/finance-escalations/{code}/resolve`
- `GET /reports/operations-escalations`
- `GET /reports/operations-escalations/history`
- `GET /reports/operations-escalation-metrics`
- `GET /reports/operations-escalations/{domain}/{code}`
- `POST /reports/operations-escalations/{domain}/{code}/acknowledge`
- `POST /reports/operations-escalations/{domain}/{code}/resolve`
- `GET /reports/finance-operations/history`
- `GET /reports/finance-operations/metrics`
- `GET /reports/finance-operations/{kind}/{entity}`
- `GET /reports/finance-operations/{kind}/{entity}/history`
- `POST /reports/finance-operations/{kind}/{entity}/acknowledge`
- `POST /reports/finance-operations/{kind}/{entity}/resolve`
- `GET /reports/billing-escalations/history`
- `GET /reports/billing-escalations/{code}`
- `POST /reports/billing-escalations/{code}/acknowledge`
- `POST /reports/billing-escalations/{code}/resolve`
- `GET /reports/due-reminders`
- `GET /reports/promise-compliance`
- `GET /reports/receivable-risk`
- `GET /reports/sales-profitability`
- `GET /audit/timeline`
- `GET /audit/timeline/{activity}`

## Request examples

### Venta POS multi-item

```json
{
  "payment_method": "cash",
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "unit_price": 4.5
    },
    {
      "lot_id": 10,
      "quantity": 1,
      "unit_price": 9.9
    }
  ]
}
```

### Cobranza de cuenta por cobrar

```json
{
  "amount": 25.5,
  "payment_method": "cash",
  "reference": "COBRO-CAJA-0001"
}
```

### Recepcion de compra creando lote inline

```json
{
  "supplier_id": 1,
  "items": [
    {
      "product_id": 1,
      "lot_code": "L-ING-001",
      "expires_at": "2027-12-31",
      "quantity": 50,
      "unit_cost": 2.15
    }
  ]
}
```

## Worker operativo

- Comando manual: `php artisan billing:dispatch-outbox --limit=20`
- Si no se pasa `--tenant`, procesa tenants con eventos pendientes
- Para pruebas controladas: `--simulate-result=accepted|rejected|transient_fail`

## Provider profile de billing

- `GET /billing/provider-profile` devuelve el perfil activo del tenant
- `PUT /billing/provider-profile` permite ajustar:
  - `provider_code`
  - `environment`
  - `default_outcome`
  - `credentials`
- `POST /billing/provider-profile/check` ejecuta un health check y persiste:
  - `health_status`
  - `health_checked_at`
  - `health_message`
- El provider actual es `fake_sunat`
- Si no se manda `simulate_result` en el outbox, se usa `default_outcome` del perfil
- `GET /billing/outbox/provider-trace` devuelve resumen e intentos recientes por `provider_code` y `provider_environment`
- `GET /billing/provider-metrics` devuelve SLA operativo del tenant:
  - salud vigente/stale del provider
  - backlog actual de outbox
  - tasa de aceptacion/rechazo/fallo en la ventana consultada
  - replay backlog y fallos recientes

## Dashboard ejecutivo de billing

- `GET /reports/billing-operations` concentra:
  - resumen ejecutivo de salud, backlog y acceptance rate
  - tendencia diaria de eventos/aceptacion/fallos/replays
  - comparativo por `provider_environment`
  - aging actual del backlog pendiente
  - fallos recientes y alertas operativas

## Tablero maestro operativo

- `GET /reports/operations-control-tower` concentra en una sola vista:
  - resumen ejecutivo diario de ventas, cobranza, caja, billing y finanzas
  - `health_gates` por dominio operativo
  - cola de foco con escalaciones cross-domain y prioridades financieras
  - fallos recientes de billing y paths de drill-down
- `GET /reports/operations-control-tower/briefing` arma un paquete ejecutivo reutilizable con:
  - estado live actual
  - tendencia corta
  - ultimo snapshot disponible para esa fecha
  - drift contra snapshot y highlights accionables
- `GET /reports/operations-control-tower/briefing/export` exporta ese briefing en `markdown` o `json`
- `GET /reports/operations-control-tower/history` devuelve la tendencia diaria del tablero maestro para una ventana corta
- `GET /reports/operations-control-tower/compare` contrasta dos fechas y devuelve delta de métricas y cambios de `health_gates`
- `POST /reports/operations-control-tower/snapshots` persiste una captura del tablero maestro con su payload completo
- `GET /reports/operations-control-tower/snapshots` lista snapshots guardados por tenant
- `GET /reports/operations-control-tower/snapshots/{snapshot}/export` entrega el snapshot en `markdown` o `json`
- `GET /reports/operations-control-tower/snapshots/{snapshot}/compare` compara un snapshot guardado contra otra captura o contra el estado live
- `GET /reports/operations-control-tower/snapshots/{snapshot}/compare/export` exporta esa comparación en `markdown` o `json`
- parametros utiles:
  - `date`
  - `days`
  - `base_date`
  - `compare_date`
  - `against_snapshot`
  - `billing_days`
  - `finance_days_ahead`
  - `priority_limit`
  - `failure_limit`
  - `stale_follow_up_days`

## Escalamiento de billing

- `GET /reports/billing-escalations` prioriza alertas abiertas con:
  - severidad `critical|warning|info`
  - prioridad operativa
  - mensaje accionable
  - accion recomendada
  - snapshot de la metrica que disparo la alerta
- `POST /reports/billing-escalations/{code}/acknowledge` registra reconocimiento interno de una alerta
- `POST /reports/billing-escalations/{code}/resolve` registra cierre manual con nota obligatoria
- El reporte de escalaciones ahora devuelve tambien:
  - `workflow_status`
  - `state.acknowledged_*`
  - `state.resolved_*`
- `GET /reports/billing-escalations/history` devuelve el panel historico por codigo con:
  - si la alerta sigue activa o no
  - ultima nota y ultimo actor
  - conteo de timeline por codigo
- `GET /reports/billing-escalation-metrics` devuelve metricas operativas de workflow:
  - backlog activo por severidad y estado
  - count de acknowledge/resolve en la ventana historica
  - SLA de minutos entre acknowledge y resolve
  - alertas acknowledged que ya estan envejeciendo
  - ultimas resoluciones con su duracion
- `GET /reports/billing-escalations/{code}` devuelve el detalle operativo del codigo:
  - alerta activa en la ventana consultada
  - estado persistido `open|acknowledged|resolved`
  - timeline de acknowledge/resolve
  - ultima nota y ultima actividad

## Operaciones financieras

- `GET /reports/finance-operations` consolida en un solo tablero:
  - exposicion vigente de cuentas por cobrar y por pagar
  - vencidos y montos corrientes
  - promesas rotas, pendientes y cumplidas
  - frescura del seguimiento (`missing`, `stale`, `recent`)
  - cola priorizada combinando cobranza y pagos
- la cola priorizada ahora devuelve tambien:
  - `workflow_status`
  - `state`
- Parametros utiles:
  - `date`
  - `days_ahead`
  - `limit`
  - `stale_follow_up_days`
- `GET /reports/finance-operations/{kind}/{entity}` devuelve:
  - detalle operativo del receivable/payable
  - si hoy esta priorizado o no
  - estado persistido del workflow
  - item priorizado actual si aplica
- `GET /reports/finance-operations/history` devuelve:
  - backlog historico de prioridades financieras con estado actual
  - ultima actividad, ultima nota y conteo de timeline por entidad
  - union entre items actualmente priorizados y entidades con workflow previo
- `GET /reports/finance-operations/{kind}/{entity}/history` devuelve:
  - timeline `acknowledge/resolve`
  - snapshot actual de la entidad
  - ultima nota y actividad mas reciente
- `POST /reports/finance-operations/{kind}/{entity}/acknowledge` registra toma de caso
- `POST /reports/finance-operations/{kind}/{entity}/resolve` registra cierre manual con nota obligatoria
- `GET /reports/finance-operations/metrics` devuelve:
  - backlog activo por estado del workflow
  - aging de la cola financiera
  - SLA de resolucion entre acknowledge y resolve
  - ultimas resoluciones y top prioridades actuales
- `GET /reports/finance-escalations` devuelve:
  - vista accionable de la cola financiera ya priorizada
  - severidad operativa por entidad (`critical`, `warning`, `info`)
  - acciones recomendadas para cobranza o pago
  - conteos por workflow, flags y tipo de entidad
- `GET /reports/finance-escalations/history` devuelve el panel historico por codigo con:
  - si la alerta sigue activa o no
  - ultima nota y ultimo actor
  - conteo de timeline por codigo
- `GET /reports/finance-escalation-metrics` devuelve metricas operativas de workflow:
  - backlog activo por severidad y estado
  - count de acknowledge/resolve por alerta agregada
  - SLA de minutos entre acknowledge y resolve
  - alertas `acknowledged` que ya estan envejeciendo
  - ultimas resoluciones con su duracion
- `GET /reports/finance-escalations/{code}` devuelve el detalle operativo de una alerta agregada, con:
  - `state` persistido
  - timeline `acknowledge/resolve`
  - ultima nota y actividad mas reciente
  - muestra de entidades afectadas
- `POST /reports/finance-escalations/{code}/acknowledge` reconoce una alerta agregada de finanzas
- `POST /reports/finance-escalations/{code}/resolve` cierra manualmente una alerta agregada de finanzas con nota obligatoria
- `GET /reports/daily` ahora incluye tambien un snapshot resumido en `finance_operations`, incluyendo `workflow_metrics`

## Cola unificada de escalaciones operativas

- `GET /reports/operations-escalations` concentra en una sola cola:
  - alertas abiertas de billing y finanzas
  - resumen por severidad, workflow y dominio
  - acciones recomendadas deduplicadas
  - top de la cola priorizada cross-domain
- `GET /reports/operations-escalations/history` devuelve:
  - historial consolidado por `queue_key`
  - ultima nota, ultimo actor y conteo de timeline
  - estado actual del workflow aun si la alerta sigue activa
- `GET /reports/operations-escalation-metrics` devuelve:
  - backlog activo por dominio y workflow
  - eventos de `acknowledge/resolve`
  - SLA agregado entre acknowledge y resolve
  - ultimas resoluciones cross-domain
- parametros utiles:
  - `date`
  - `billing_days`
  - `finance_days_ahead`
  - `limit`
  - `stale_follow_up_days`
- `GET /reports/operations-escalations/{domain}/{code}` devuelve el detalle operativo unificado y expone:
  - `queue_key`
  - `actions.detail_path`
  - `actions.source_path`
  - `actions.acknowledge_path`
  - `actions.resolve_path`
- `POST /reports/operations-escalations/{domain}/{code}/acknowledge` registra toma de caso desde la cola unificada
- `POST /reports/operations-escalations/{domain}/{code}/resolve` registra cierre manual con nota obligatoria desde la misma cola
- `GET /reports/daily` ahora incluye tambien un snapshot resumido en `operations_escalations` para el corte diario
  - `workflow_metrics.active_count`
  - `workflow_metrics.acknowledged_count`
  - `workflow_metrics.stale_acknowledged_count`
  - `workflow_metrics.avg_minutes_from_ack_to_resolve`

## Payloads versionados de billing

- Cada voucher y nota de credito genera un snapshot en `billing_document_payloads`
- El outbox ahora referencia:
  - `billing_payload_id`
  - `schema_version`
  - `document_kind`
  - `document_number`
  - `document_payload`
- Lectura operativa:
  - `GET /billing/vouchers/{voucher}/payloads`
  - `GET /billing/credit-notes/{creditNote}/payloads`
- Version inicial actual:
  - `fake_sunat.v1`

## Replay y regeneracion

- `POST /billing/vouchers/{voucher}/payloads/regenerate` crea un snapshot nuevo con el provider profile actual
- `POST /billing/credit-notes/{creditNote}/payloads/regenerate` hace lo mismo para notas de credito
- Si existe un outbox `pending` o `failed`, la regeneracion sincroniza ese payload abierto
- `POST /billing/vouchers/{voucher}/replay` crea un nuevo `outbox_event` con lineage hacia el evento anterior
- `POST /billing/credit-notes/{creditNote}/replay` reencola la nota de credito sin recrear el documento
- El replay limpia `sunat_ticket` y deja el documento nuevamente en `pending`
- `GET /billing/outbox/{event}/lineage` devuelve la cadena completa de eventos, payloads, intentos y actividades relacionadas

## Donde mirar el contrato completo

- [`docs/openapi/velmix.openapi.yaml`](C:\Users\user\Desktop\velmix-app\docs\openapi\velmix.openapi.yaml)
- `GET /docs/openapi.yaml`
