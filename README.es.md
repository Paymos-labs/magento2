# Paymos para Magento 2

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Módulo oficial de pagos de Paymos para Magento Open Source y Adobe Commerce. Añade un método al
checkout: el comprador realiza el pedido, llega a la página de pago alojada de Paymos, elige un
token y una red, y envía la transferencia desde su propia billetera. Magento factura el pedido
cuando un webhook firmado notifica que el pago se confirmó en la cadena, no cuando el comprador
regresa a la tienda.

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Vídeo demostrativo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- Magento Open Source o Adobe Commerce 2.4 — el módulo requiere `magento/framework` 103.0 o superior
- PHP 8.3, 8.4 u 8.5 (Adobe Commerce 2.4.9 retiró el soporte para 8.1/8.2)
- Acceso de consola para ejecutar `bin/magento`
- Una tienda servida por HTTPS; el flujo de conexión rechaza una URL base en HTTP plano
- Una cuenta de Paymos con el proyecto que quieres vincular abierto en el panel

Esas versiones están declaradas en `app/code/Paymos/Payment/composer.json`. El archivo de
lanzamiento incluye el SDK de Paymos para PHP dentro del directorio `vendor/` del propio módulo,
así que una instalación manual no necesita ningún paso de Composer.

## Instalación

Descarga `paymos-magento2-<version>.zip` desde
[Releases](https://github.com/Paymos-labs/magento2/releases/latest) o desde el panel **CMS
integration** del panel de Paymos y descomprímelo en la raíz de Magento. Las entradas ya vienen
estructuradas como `app/code/Paymos/Payment/…`, por lo que no hay que mover nada después.

```bash
bin/magento module:enable Paymos_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

`setup:upgrade` crea dos tablas: `paymos_payment_invoice`, con una instantánea por pedido, y
`paymos_payment_event`, el registro de deduplicación de webhooks.

En cada versión se publica un único archivo y todos los comercios descargan ese mismo paquete. No
contiene clave API, secreto API, ID de proyecto, secreto de webhook, token OAuth ni código de
dispositivo, y su instalación no conecta nada por sí sola.

## Conexión

1. En el panel de Paymos, abre el proyecto con el que debe facturar esta tienda. Ese es el
   proyecto que quedará asignado: el módulo no tiene selector de proyecto.
2. En Magento ve a **Stores → Configuration → Sales → Payment Methods → Paymos** y pulsa
   **Connect Paymos**.
3. Aprueba la URL de la tienda y el proyecto que aparecen en la pestaña de Paymos que se abre. Si
   el navegador bloquea la pestaña, la fila muestra en su lugar el enlace de aprobación y el código
   de usuario.
4. Espera a que **Connection status** indique conectado y cambia **Enabled** a Yes.

Una sola aprobación aprovisiona Sandbox y Live a la vez. Se reutiliza tu única clave Payment
activa —o se crea una si no existe— y se registra un webhook de tipo Invoice en:

```text
https://your-store.example/paymos/payment/callback
```

Un webhook existente solo se reutiliza si coinciden su URL de callback, categoría y proyecto;
nunca se sobrescribe un webhook en conflicto en la misma dirección. La autorización de dispositivo
es un canal de entrega de un solo uso: el token de vida corta se descarta y a partir de ese
momento cada llamada a la Merchant API va firmada con HMAC.

Las credenciales se guardan en `core_config_data` cifradas mediante `EncryptorInterface` de
Magento y nunca se vuelven a mostrar en la página de administración. No existe ningún campo para
pegar un secreto; volver a conectar es la única manera de sustituirlo.

## Alcance de la configuración

Los campos del módulo se exponen a nivel de **Default Config** y **website**. Las vistas de tienda
(store views) heredan valores siguiendo el respaldo normal de Magento, y el valor que resuelve una
vista de tienda es el que utilizan su checkout y sus pedidos.

| Ajuste | Valor predeterminado | Propósito |
|---|---|---|
| Enabled | No | Si el método se ofrece o no |
| Title | `Pay with crypto (Paymos)` | Etiqueta que ve el comprador |
| Mode | `sandbox` | Conjunto de credenciales que usa el sitio web |
| New order status | `pending_payment` | Aplicado mientras el comprador está pagando |
| Paid order status | `processing` | Aplicado cuando se confirma el pago |
| Debug logging | No | Diagnósticos en `var/log/paymos.log` |
| Sort order | `1` | Posición entre los demás métodos |

Las credenciales conectadas forman un único conjunto por instalación y no por sitio web, de modo
que un segundo sitio web no requiere otra aprobación. Cambia cada sitio entre entornos solo con
**Mode**. Solo es necesario reconectar si cambia la URL base, se revoca una clave Payment o se
rota un secreto de webhook.

## Qué ocurre con el pedido

| Evento de Paymos | Resultado en Magento |
|---|---|
| Factura creada, comprador redirigido | El **New order status** configurado, más un comentario de historial con el ID de la factura |
| `invoice.confirming` | Sin cambios de estado; un comentario registra que el pago está confirmándose |
| `invoice.underpaid_waiting` | Sin cambios de estado; el pedido espera el resto del importe |
| `invoice.awaiting_payment` | Sin cambios de estado; un pago ya contabilizado desapareció en una reorganización de la cadena |
| `invoice.paid`, `invoice.paid_over` | Se crea una factura offline, el pedido pasa al estado Processing y al **Paid order status** configurado, y se envía el correo de factura |
| `invoice.underpaid` | Pedido cancelado |
| `invoice.expired`, `invoice.cancelled` | Pedido cancelado |

El documento de factura registra el hash de transacción en cadena de la última transferencia
confirmada como su ID de transacción, y recurre al ID de factura de Paymos cuando la carga útil
no incluye ninguno.

Dos protecciones actúan antes que esa tabla. Un pedido cuyo total general o moneda cambió después
de crearse la factura nunca se completa automáticamente: recibe un comentario solicitando revisión
manual, y la entrega se confirma igualmente para detener la escalera de reintentos. Además, un
`confirming`, `underpaid`, `expired` o `cancelled` tardío que llegue cuando el pedido ya está
pagado se registra en el log y se descarta, sin aplicarse jamás.

## Probar antes de pasar a producción

1. Configura **Mode** en Sandbox y realiza un pedido desde la tienda.
2. Abre esa factura en el panel de Paymos mientras el panel esté en Sandbox. **Pay Full**,
   **Pay 50%**, **Pay 150%** y **Cancel** recorren los mismos eventos de ciclo de vida que emite
   un pago real. No se mueve criptomoneda.
3. Comprueba el pedido en Magento: estado, comentarios del historial y aparición de la factura.
4. Cambia **Mode** a Live. Las credenciales de Live llegaron con la misma aprobación, así que no
   hay que reconectar ni configurar nada por segunda vez.

Las cargas útiles de Sandbox no llevan transferencias, por lo que un pedido de prueba registra el
ID de factura donde un pedido real registra un hash de transacción.

## Webhooks

Las entregas llegan mediante `POST` a `/paymos/payment/callback`. La ruta está exenta del form
key de Magento porque la cabecera `X-Webhook-Signature` constituye la frontera de confianza; un
cuerpo sin firmar o mal firmado recibe `401` y nunca llega a un pedido. `X-Webhook-Id` es estable
entre reintentos y actúa como clave de deduplicación almacenada en `paymos_payment_event`, así que
un reenvío responde `200` y no modifica nada.

Los eventos finales pasan por verificación inversa. Antes de completar o cancelar un pedido, la
factura se vuelve a consultar desde la Merchant API y se compara con la instantánea almacenada; si
la carga útil discrepa, se rechaza.

Un ciclo de entrega realiza 11 intentos repartidos en unas 16 horas, de manera que una tienda
temporalmente inaccesible igualmente recibe el evento. Detrás de eso, la tarea cron
`paymos_reconcile_invoices` se ejecuta cada 15 minutos para consultar en la API facturas recientes
no finales, mientras que `paymos_reconcile_refunds` se ejecuta cada minuto para revisar reembolsos
pendientes; ambas requieren que el cron de Magento esté en marcha.

## Reembolsos

El módulo admite reembolsos nativos a través de la Merchant API de Paymos (`/v1/refunds`) en dos
modos:

- `full` — reembolsa el importe íntegro de la factura pagada;
- `overpayment` — reembolsa únicamente el exceso recibido en un pedido `invoice.paid_over`.

Los fondos vuelven exclusivamente a la dirección de billetera `first_sender` verificada que Paymos
capturó cuando se pagó la factura. No se introduce manualmente ninguna dirección de destino y se
rechazan reembolsos parciales de artículos o ajustes.

- **Reembolso completo (`full`):** abre en Magento Admin la factura del pedido pagado con Paymos y
  envía un **Credit Memo** nativo con todos los artículos facturados reembolsados al completo y
  cero ajustes de envío o importe. `Paymos\Payment\Plugin\RefundEntry` intercepta el envío,
  registra el reembolso `full` en Paymos sin guardar un Credit Memo local prematuro y redirige al
  panel **Paymos refunds**.
- **Reembolso de exceso (`overpayment`) y consultas de estado:** ve a **Stores → Configuration →
  Sales → Payment Methods → Paymos**, introduce el ID del pedido en el bloque **Paymos refunds**,
  pulsa **Load order** y utiliza **Refund overpayment** o **Check full refund** / **Check
  overpayment refund**.

Magento crea el Credit Memo y el comentario de historial del pedido únicamente después de que
Paymos confirme `completed` mediante `/paymos/payment/callback`, una consulta manual de estado o
el cron de 1 minuto `paymos_reconcile_refunds`. En modo Sandbox es posible simular resultados
finales de reembolso (`completed`, `failed`, `cancelled`) sin mover fondos cripto.

## Resolución de problemas

**Paymos no aparece en el checkout.** El método viene desactivado por defecto. Cambia **Enabled**
a Yes en el sitio web al que pertenece la tienda, comprueba que **Connection status** indica
conectado y vacía la caché de configuración: Magento guarda en caché `payment/paymos/*` igual
que cualquier otra ruta de configuración.

**Los pedidos se quedan en estado de espera de pago.** El webhook no está llegando. El callback
debe ser accesible desde internet público en la URL base exacta que se aprobó: los destinos
loopback, privados y link-local están bloqueados y no se siguen redirecciones, así que si la
tienda cambió de host o añadió una redirección hay que volver a conectar. Ejecutar
`bin/magento cron:run` muestra si el trabajo de conciliación puede cubrir el hueco mientras tanto.

**La conexión falla con "Magento base URL must use HTTPS".** El flujo de dispositivo lee la URL
base de la vista de tienda predeterminada; esa URL es la que muestra la pantalla de aprobación y
la que se registra para el webhook. Sírvela sobre HTTPS y vuelve a iniciar la conexión.

## Enlaces

- Documentación: [paymos.io/es/docs/cms-magento2](https://paymos.io/es/docs/cms-magento2)
- Código fuente y lanzamientos: [Paymos-labs/magento2](https://github.com/Paymos-labs/magento2)
- Soporte: [support@paymos.io](mailto:support@paymos.io)
