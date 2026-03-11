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
- `POST /billing/outbox/{event}/retry`
- `GET /billing/outbox/{event}/attempts`

### Reportes y auditoria

- `GET /reports/daily`
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

## Donde mirar el contrato completo

- [`docs/openapi/velmix.openapi.yaml`](C:\Users\user\Desktop\velmix-app\docs\openapi\velmix.openapi.yaml)
- `GET /docs/openapi.yaml`
