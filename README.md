# BillMySales - WooCommerce Webhook Notifier & Custom Fields

**BillMySales** es un plugin para WordPress/WooCommerce que envía notificaciones automáticas a una URL (webhook) configurable cuando una orden alcanza estados específicos. Además, permite agregar campos personalizados al checkout (por bloques) y los incluye en la notificación.

---

## Características

- Notifica a una URL configurable (webhook propio) cuando una orden cambia a un estado seleccionado.
- Incluye campos personalizados configurables en el checkout (compatible con el checkout por bloques de WooCommerce).
- Cada destino tiene su propio secreto (obligatorio) para autenticar las notificaciones.
- Firma cada notificación con HMAC-SHA256 (header `X-WC-Webhook-Signature`), además del secreto compartido, para validar la integridad del payload.
- Opción de activar/desactivar el envío sin perder la configuración.
- Compatible con HPOS (High-Performance Order Storage) de WooCommerce.
- Registro de errores en el log de WooCommerce.
- Totalmente configurable desde el panel de administración de WooCommerce.

---

## Requisitos

| Requisito               | Versión mínima |
|-------------------------|----------------|
| WordPress               | 5.6            |
| PHP                     | 7.4            |
| WooCommerce             | 8.9            |
| WooCommerce (probado)   | hasta 10.9     |

---

## Instalación

1. Sube la carpeta `BillMySales` al directorio `/wp-content/plugins/` o puedes subir el plugin en formato .zip en la interfaz de wordpres.
2. Activa el plugin desde el panel de administración de WordPress.
3. Ve a **WooCommerce → BillMySales** para configurar el webhook y los campos personalizados.

---

## Configuración

### 🔹 Pestaña "Configuración"

- **Activo**: Activa o desactiva el envío de notificaciones.
- **URL de notificación**: Endpoint que recibirá el payload de la orden.
- **Secreto**: Clave compartida que se enviará en el header `X-WCON-Secret`, y que además se usa para firmar cada notificación con HMAC-SHA256 (header `X-WC-Webhook-Signature`).
- **Estados que notifican**: Selecciona los estados de la orden que activarán la notificación.

### 🔹 Pestaña "Campos del checkout"

- Agrega campos adicionales al checkout (ubicación `order`).
- Soporta campos de texto libre y selectores (definidos por valores separados por coma).
- Cada campo puede marcarse como obligatorio.
- Los valores se almacenan como metadatos de la orden (`_wc_other/BillMySales/{key}`).

---

## Payload enviado

El payload sigue la misma estructura que la API REST nativa de WooCommerce
(`GET /wc/v3/orders/{id}`), recortada a solo los campos que BillMySales
efectivamente utiliza para procesar el pedido. Los campos personalizados
configurados en la pestaña "Campos del checkout" se agregan dentro de
`meta_data`, en el mismo formato `{id, key, value}` que usa WooCommerce para
sus propios metadatos.

Ejemplo de payload JSON enviado al webhook:

```json
{
  "id": 123,
  "number": "123",
  "status": "processing",
  "date_created": "2026-08-24T13:45:54",
  "date_paid": "2026-08-24T13:45:55",
  "date_completed": null,
  "prices_include_tax": false,
  "total": "100.00",
  "total_tax": "0",
  "discount_total": "0",
  "discount_tax": "0",
  "shipping_total": "0",
  "shipping_tax": "0",
  "customer_id": 0,
  "currency": "CLP",
  "payment_method": "bacs",
  "payment_method_title": "Transferencia bancaria directa",
  "billing": {
    "first_name": "Juan",
    "last_name": "Pérez",
    "company": "",
    "address_1": "Calle Falsa 123",
    "address_2": "",
    "city": "Santiago",
    "state": "",
    "postcode": "",
    "country": "CL",
    "email": "juan@example.com",
    "phone": "+56912345678"
  },
  "shipping": {
    "first_name": "Juan",
    "last_name": "Pérez",
    "company": "",
    "address_1": "Calle Falsa 123",
    "address_2": "",
    "city": "Santiago",
    "state": "",
    "postcode": "",
    "country": "CL",
    "phone": ""
  },
  "line_items": [
    {
      "id": 32,
      "name": "Producto Ejemplo",
      "product_id": 42,
      "variation_id": 0,
      "quantity": 2,
      "subtotal": "100.00",
      "total": "100.00",
      "total_tax": "0",
      "price": 50,
      "sku": "",
      "taxes": [],
      "meta_data": []
    }
  ],
  "fee_lines": [],
  "coupon_lines": [],
  "shipping_lines": [],
  "meta_data": [
    {
      "id": 0,
      "key": "rut",
      "value": "12.345.678-9"
    }
  ]
}
```
