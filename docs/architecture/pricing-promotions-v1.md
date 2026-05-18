# Pricing + Promotions v1

## Estado

- Estado: proposed
- Alcance: backend comercial
- Decicion ejecutiva: pausar el cierre definitivo del backend comercial hasta completar pricing + promotions v1
- Motivacion: el negocio necesita listas de precios y promociones de laboratorio desde el go-live

## 1. Diagnostico del repo actual

Hoy el dominio comercial tiene estas caracteristicas:

- `products` es un catalogo de inventario, no un catalogo de pricing.
- `products` nace con `sku`, `name`, `status`, `is_controlled` y luego recibe `last_cost` y `average_cost`.
- `GET /inventory/products` expone costos, no precio de venta.
- `POST /pos/sales` exige que el cliente mande `unit_price` por linea.
- `PosSaleService` calcula margen usando `unit_price` transaccional contra `average_cost`.

Consecuencia:

- El precio lo esta decidiendo hoy la UI o el operador.
- No existe todavia una capacidad formal de listas de precios.
- No existe todavia una capacidad formal de promociones financiadas por laboratorio.
- No existe una capa centralizada de cotizacion antes del checkout POS.

Con el negocio actual, esto no es suficiente para salida a produccion.

## 2. Objetivos v1

Pricing + promotions v1 debe cubrir:

- precio base por lista
- asignacion de listas por cliente
- lista default del tenant para venta mostrador
- promociones por laboratorio
- promociones por producto y por laboratorio
- promociones por fecha y vigencia
- promociones combinables y no combinables
- prioridad de aplicacion
- trazabilidad de quien financia el beneficio
- cotizacion server-side antes de confirmar la venta
- persistencia historica del precio final y del desglose promocional

## 3. No objetivos v1

Queda fuera de v1:

- motor low-code o DSL libre para reglas arbitrarias
- settlement contable completo con laboratorios
- rebates post-venta complejos
- pricing por geografia multi-tenant cruzada
- simulador analitico avanzado de promociones

La recomendacion v1 es un motor fuerte, auditable y extensible, pero con tipos de regla acotados y explicitamente soportados.

## 4. Principios de arquitectura

1. El frontend no calcula precios.
2. El backend resuelve precio base, promociones y precio final.
3. La venta guarda snapshot final en `sale_items.unit_price`.
4. Toda promocion aplicada debe quedar auditada y reconstruible.
5. La cotizacion se genera antes del checkout y se consume con TTL corto.
6. Las promociones deben ser especificas de dominio, no una caja negra imposible de operar.

## 5. Modelo de dominio propuesto

### 5.1 Catalogo base

#### `suppliers`

Extender tabla existente para distinguir laboratorios de distribuidores.

Campos nuevos:

- `kind` enum: `laboratory`, `distributor`, `other`
- `commercial_code` nullable string

Motivo:

- el repo ya usa `suppliers` para compras
- los laboratorios ya caben naturalmente como un subtipo de supplier
- evita crear otra entidad paralela sin necesidad

#### `products`

Extender tabla existente con metadatos comerciales minimos.

Campos nuevos:

- `laboratory_supplier_id` nullable fk -> `suppliers.id`
- `commercial_status` enum: `active`, `inactive`, `blocked`

Motivo:

- una promocion debe poder identificar al laboratorio sponsor
- una venta/promocion debe saber si el producto participa del catalogo comercial

No recomiendo meter `default_sale_price` en `products` como unica verdad si ya habra listas multiples. Ese valor se vuelve ambiguo demasiado rapido.

### 5.2 Price lists

#### `price_lists`

Tabla nueva.

Campos:

- `id`
- `tenant_id`
- `code`
- `name`
- `status` enum: `draft`, `active`, `inactive`
- `channel` enum: `retail`, `wholesale`, `institutional`, `mixed`
- `currency` string default `PEN`
- `is_default` boolean
- `priority` unsigned integer default `100`
- `starts_at` nullable timestamp
- `ends_at` nullable timestamp
- timestamps

Reglas:

- una lista default activa por canal y tenant
- puede haber varias listas activas, pero la resolucion debe ser deterministica

#### `price_list_items`

Tabla nueva.

Campos:

- `id`
- `price_list_id`
- `product_id`
- `unit_price` decimal(12,2)
- `min_unit_price` nullable decimal(12,2)
- `max_discount_pct` nullable decimal(5,2)
- `valid_from` nullable timestamp
- `valid_until` nullable timestamp
- `status` enum: `active`, `inactive`
- timestamps

Reglas:

- una sola fila activa por `price_list_id + product_id + ventana vigente`
- `min_unit_price` sirve para control de override y descuentos extremos

### 5.3 Asignacion comercial

#### `customer_price_list_assignments`

Tabla nueva.

Campos:

- `id`
- `tenant_id`
- `customer_id`
- `price_list_id`
- `priority` unsigned integer default `100`
- `starts_at` nullable timestamp
- `ends_at` nullable timestamp
- `status` enum: `active`, `inactive`
- timestamps

Motivo:

- permite historia
- evita colgar `price_list_id` directamente en `customers`
- soporta cambios de convenio o segmento sin perder trazabilidad

### 5.4 Promociones

#### `promotions`

Tabla nueva.

Campos:

- `id`
- `tenant_id`
- `code`
- `name`
- `description` nullable text
- `status` enum: `draft`, `scheduled`, `active`, `paused`, `expired`, `archived`
- `sponsor_supplier_id` nullable fk -> `suppliers.id`
- `channel` enum: `retail`, `wholesale`, `institutional`, `mixed`
- `priority` unsigned integer default `100`
- `stack_mode` enum: `exclusive`, `best_price_only`, `stackable`
- `stop_further_processing` boolean default `false`
- `requires_customer` boolean default `false`
- `allowed_payment_methods` nullable json
- `starts_at` timestamp
- `ends_at` nullable timestamp
- `budget_cap` nullable decimal(12,2)
- `budget_used` decimal(12,2) default `0`
- timestamps

#### `promotion_targets`

Tabla nueva.

Campos:

- `id`
- `promotion_id`
- `target_type` enum: `product`, `laboratory`, `price_list`, `all_products`
- `target_id` nullable bigint
- `exclude` boolean default `false`
- timestamps

Uso:

- producto puntual
- laboratorio completo
- una lista de precios especifica
- exclusion de productos puntuales dentro de promo de laboratorio

#### `promotion_rules`

Tabla nueva.

Campos:

- `id`
- `promotion_id`
- `rule_type` enum:
  - `fixed_unit_price`
  - `percent_off`
  - `amount_off`
  - `buy_x_pay_y`
  - `buy_x_get_y_free`
  - `second_unit_percent_off`
  - `tiered_quantity_price`
  - `cart_amount_percent_off`
- `scope` enum: `line`, `group`, `cart`
- `config` json
- `priority` unsigned integer default `100`
- `status` enum: `active`, `inactive`
- timestamps

Motivo:

- soporta variedad amplia sin caer en schema explosion
- el backend valida `config` segun `rule_type`

#### `promotion_audiences`

Tabla nueva.

Campos:

- `id`
- `promotion_id`
- `audience_type` enum: `walk_in`, `customer`, `customer_price_list`, `all`
- `audience_id` nullable bigint
- timestamps

Motivo:

- promos para mostrador
- promos para clientes de convenio
- promos ligadas a una lista

### 5.5 Cotizacion

#### `pricing_quotes`

Tabla nueva.

Campos:

- `id`
- `tenant_id`
- `customer_id` nullable
- `price_list_id` nullable
- `channel` enum
- `payment_method` enum: `cash`, `card`, `transfer`, `credit`
- `status` enum: `draft`, `quoted`, `consumed`, `expired`, `cancelled`
- `quote_hash` string
- `subtotal_amount` decimal(12,2)
- `discount_amount` decimal(12,2)
- `total_amount` decimal(12,2)
- `currency` string default `PEN`
- `expires_at` timestamp
- `sale_id` nullable fk -> `sales.id`
- `created_by_user_id`
- timestamps

#### `pricing_quote_items`

Tabla nueva.

Campos:

- `id`
- `pricing_quote_id`
- `product_id`
- `requested_quantity` integer
- `resolved_price_list_item_id` nullable
- `base_unit_price` decimal(12,2)
- `final_unit_price` decimal(12,2)
- `line_discount_amount` decimal(12,2)
- `line_total` decimal(12,2)
- `commercial_context` json
- timestamps

#### `pricing_quote_adjustments`

Tabla nueva.

Campos:

- `id`
- `pricing_quote_item_id`
- `promotion_id` nullable
- `promotion_rule_id` nullable
- `adjustment_type` enum: `base_price`, `promotion_discount`, `manual_override`
- `description`
- `sponsor_supplier_id` nullable
- `quantity` decimal(12,2)
- `unit_delta` decimal(12,2)
- `total_delta` decimal(12,2)
- `metadata` json nullable
- timestamps

### 5.6 Persistencia historica en la venta

#### `sale_item_pricing_components`

Tabla nueva.

Campos:

- `id`
- `sale_item_id`
- `pricing_quote_item_id` nullable
- `promotion_id` nullable
- `promotion_rule_id` nullable
- `component_type` enum: `base_price`, `promotion_discount`, `manual_override`
- `description`
- `sponsor_supplier_id` nullable
- `unit_amount` decimal(12,2)
- `total_amount` decimal(12,2)
- `metadata` json nullable
- timestamps

Motivo:

- `sale_items` ya existe y puede dividir una linea comercial en varias asignaciones por lote
- este desglose permite prorratear el beneficio por cada `sale_item`
- billing, auditoria y reportes pueden reconstruir exactamente que paso

## 6. Contratos HTTP propuestos

### 6.1 Price lists

- `GET /pricing/price-lists`
- `POST /pricing/price-lists`
- `GET /pricing/price-lists/{priceList}`
- `PATCH /pricing/price-lists/{priceList}`
- `GET /pricing/price-lists/{priceList}/items`
- `PUT /pricing/price-lists/{priceList}/items`

### 6.2 Customer assignments

- `GET /pricing/customers/{customer}/price-lists`
- `POST /pricing/customers/{customer}/price-lists`
- `PATCH /pricing/customer-price-list-assignments/{assignment}`

### 6.3 Promotions

- `GET /pricing/promotions`
- `POST /pricing/promotions`
- `GET /pricing/promotions/{promotion}`
- `PATCH /pricing/promotions/{promotion}`
- `PUT /pricing/promotions/{promotion}/targets`
- `PUT /pricing/promotions/{promotion}/rules`
- `PUT /pricing/promotions/{promotion}/audiences`
- `POST /pricing/promotions/{promotion}/activate`
- `POST /pricing/promotions/{promotion}/pause`

### 6.4 Quote and checkout

- `POST /pricing/quotes`
- `GET /pricing/quotes/{quote}`
- `POST /pricing/quotes/{quote}/checkout`

### 6.5 POS compatibility

Mantener temporalmente:

- `POST /pos/sales`

Uso recomendado:

- backoffice/manual fallback
- pruebas de compatibilidad
- no como contrato primario del nuevo frontend POS

## 7. Payload de cotizacion recomendado

### Request

```json
{
  "customer_id": 12,
  "channel": "retail",
  "payment_method": "cash",
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 3, "quantity": 1 }
  ],
  "requested_overrides": []
}
```

### Response

```json
{
  "id": 104,
  "status": "quoted",
  "quote_hash": "sha256:...",
  "expires_at": "2026-05-04T18:05:00Z",
  "price_list": {
    "id": 5,
    "code": "RETAIL-BASE"
  },
  "summary": {
    "subtotal_amount": 28.00,
    "discount_amount": 4.00,
    "total_amount": 24.00
  },
  "items": [
    {
      "id": 1001,
      "product_id": 1,
      "requested_quantity": 2,
      "base_unit_price": 8.00,
      "final_unit_price": 6.00,
      "line_discount_amount": 4.00,
      "line_total": 12.00,
      "adjustments": [
        {
          "type": "promotion_discount",
          "promotion_code": "LAB-2DA-50",
          "sponsor_supplier": {
            "id": 9,
            "name": "Laboratorio X"
          },
          "total_delta": -4.00
        }
      ]
    }
  ],
  "warnings": []
}
```

## 8. Flujo de cotizacion y checkout

1. El POS arma el carrito con `product_id` y `quantity`.
2. El frontend llama `POST /pricing/quotes`.
3. El backend resuelve:
   - cliente
   - lista aplicable
   - precio base
   - promociones elegibles
   - mejor combinacion valida
4. El backend persiste quote snapshot con TTL corto, recomendado 10 minutos.
5. El frontend muestra precio base, ahorro, promo aplicada y sponsor.
6. El usuario confirma venta.
7. El frontend llama `POST /pricing/quotes/{quote}/checkout`.
8. El backend:
   - verifica TTL y hash
   - verifica permisos de override si existen
   - revalida stock y restricciones de producto controlado
   - crea venta
   - persiste `sale_item_pricing_components`
   - marca quote como `consumed`

## 9. Algoritmo de resolucion v1

### 9.1 Price list resolution

Orden recomendado:

1. asignacion activa del cliente con mayor prioridad
2. lista activa default del tenant para el canal pedido
3. error `422` si no existe precio base del producto

### 9.2 Promotion eligibility

Una promocion aplica si:

- esta activa y vigente
- coincide con canal
- coincide con audiencia
- coincide con medio de pago si restringe
- el producto o laboratorio esta dentro del target
- no esta excluida

### 9.3 Conflict resolution

Orden recomendado:

1. ordenar promociones por `priority asc`
2. dentro de igual prioridad, aplicar la de mejor beneficio economico si `stack_mode = best_price_only`
3. si una promo es `exclusive`, no combinar
4. si `stop_further_processing = true`, cortar pipeline

### 9.4 Manual override

Solo si el usuario tiene permiso:

- `pos.sale.price.override`

Reglas:

- override nunca puede saltarse `min_unit_price`
- override debe registrar motivo
- override queda persistido como `manual_override` en quote y venta

## 10. Permisos RBAC nuevos

- `pricing.price-list.read`
- `pricing.price-list.manage`
- `pricing.promotion.read`
- `pricing.promotion.manage`
- `pricing.quote.create`
- `pricing.quote.read`
- `pos.sale.price.override`

## 11. Cambios de contrato en endpoints existentes

### `GET /inventory/products`

Agregar:

- `laboratory_supplier_id`
- `laboratory_name`
- `commercial_status`

No agregar precio final aqui como fuente unica de verdad.

### `GET /sales/customers`

Agregar read model comercial:

- `active_price_list`
- `commercial_segment` si se incorpora

### POS

El frontend POS debe migrar de:

- precio manual por linea

a:

- quote server-side
- checkout por quote

## 12. Plan de implementacion real para este repo

### Fase 1 - Schema y RBAC

1. nueva migration para `suppliers.kind` y `suppliers.commercial_code`
2. nueva migration para `products.laboratory_supplier_id` y `products.commercial_status`
3. nueva migration `create_pricing_foundation_tables`
4. nueva migration `create_promotions_tables`
5. nueva migration `create_pricing_quotes_tables`
6. nueva migration `create_sale_item_pricing_components_table`
7. extender `RbacCatalogSeeder`

### Fase 2 - Servicios backend

Crear:

- `App\\Services\\Pricing\\PriceListService`
- `App\\Services\\Pricing\\PromotionService`
- `App\\Services\\Pricing\\PriceListResolverService`
- `App\\Services\\Pricing\\PromotionEligibilityService`
- `App\\Services\\Pricing\\PromotionEngineService`
- `App\\Services\\Pricing\\PricingQuoteService`
- `App\\Services\\Pricing\\PricingCheckoutService`

Adaptar:

- `App\\Services\\Sales\\PosSaleService`

Recomendacion:

- extraer de `PosSaleService` una capa reusable que permita crear venta desde un quote ya resuelto

### Fase 3 - Endpoints y OpenAPI

Modificar:

- `routes/web.php`
- `docs/openapi/velmix.openapi.yaml`
- `docs/api-guide.md`

### Fase 4 - Read models y admin basico

Exponer:

- price lists
- items de lista
- promociones
- cotizaciones

No es necesario construir un backoffice ultra rico en la primera pasada, pero si un contrato estable y completo.

### Fase 5 - Frontend POS

Cambiar modulo POS para:

1. seleccionar productos y cantidades
2. pedir quote al backend
3. mostrar promociones aplicadas
4. permitir override solo con permiso
5. confirmar checkout desde quote

## 13. Plan de pruebas

### Unit

- resolucion de lista por cliente
- promo por laboratorio
- promo por producto
- exclusiones
- stacking
- override minimo
- expiracion de quote

### Feature

- `POST /pricing/quotes`
- `POST /pricing/quotes/{quote}/checkout`
- venta cash con promo de laboratorio
- venta credit con promo valida
- venta con producto controlado y promo
- cobranza posterior sin romper caja ni billing

### Regression

- cash sessions
- receivables
- billing voucher payload
- credit notes
- reports de margen

## 14. Riesgos y mitigaciones

### Riesgo: sobre-diseño

Mitigacion:

- usar `promotion_rules.config` JSON validado por tipo
- limitar tipos de regla v1

### Riesgo: ventas con precio incorrecto por cambios concurrentes

Mitigacion:

- quote con TTL corto
- checkout por `quote_hash`
- quote status `consumed`

### Riesgo: promo aplicada pero no auditable

Mitigacion:

- `pricing_quote_adjustments`
- `sale_item_pricing_components`

## 15. Orden recomendado de ejecucion

1. schema pricing/promotions
2. servicios de resolucion
3. quote endpoint
4. checkout desde quote
5. adaptacion POS frontend
6. admin minimo de listas y promociones

## 16. Decision final

Para este repo, la mejor salida profesional no es agregar un `default_sale_price` simple.

La mejor salida es:

- pricing v1 con listas
- promotions v1 con sponsor de laboratorio
- quote server-side
- checkout desde quote
- snapshot historico de precio y promo aplicada

Hasta que esto no exista, el backend comercial no debe considerarse definitivamente cerrado.
