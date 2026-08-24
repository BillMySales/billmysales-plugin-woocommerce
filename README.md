# BillMySales - WooCommerce Webhook Notifier & Custom Fields

**BillMySales** es un plugin para WordPress/WooCommerce que envía notificaciones automáticas a una URL (webhook) configurable cuando una orden alcanza estados específicos. Además, permite agregar campos personalizados al checkout (por bloques) y los incluye en la notificación.

---

## Características

- Notifica a una URL configurable (webhook propio) cuando una orden cambia a un estado seleccionado.
- Incluye campos personalizados configurables en el checkout (compatible con el checkout por bloques de WooCommerce).
- Cada destino tiene su propio secreto (obligatorio) para autenticar las notificaciones.
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
- **Secreto**: Clave compartida que se enviará en el header `X-WCON-Secret`.
- **Estados que notifican**: Selecciona los estados de la orden que activarán la notificación.

### 🔹 Pestaña "Campos personalizados"

- Agrega campos adicionales al checkout (ubicación `order`).
- Soporta campos de texto libre y selectores (definidos por valores separados por coma).
- Cada campo puede marcarse como obligatorio.
- Los valores se almacenan como metadatos de la orden (`_wc_other/BillMySales/{key}`).

---

## Payload enviado

Ejemplo de payload JSON enviado al webhook:

```json
{
  "event": "order_status_notification",
  "order_id": 123,
  "status": "completed",
  "currency": "USD",
  "total": "150.00",
  "date_created": "2026-08-21T12:00:00+00:00",
  "date_modified": "2026-08-21T12:05:00+00:00",
  "date_paid": "2026-08-21T12:03:00+00:00",
  "billing": {
    "first_name": "Juan",
    "last_name": "Pérez",
    "email": "juan@example.com",
    "phone": "+56912345678"
  },
  "line_items": [
    {
      "product_id": 42,
      "name": "Producto Ejemplo",
      "quantity": 2,
      "total": "100.00"
    }
  ],
  "custom_fields": {
    "rut": "12.345.678-9",
    "razon_social": "Empresa Ejemplo Ltda."
  },
  "source": "https://tutienda.com"
}
