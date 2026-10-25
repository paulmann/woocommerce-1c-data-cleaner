# WooCommerce 1C Data Cleaner

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6%2B-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)](1c-data-cleaner.php)
[![Single file](https://img.shields.io/badge/deploy-single%20file-informational)](1c-data-cleaner.php)

<!-- md-i18n:switcher -->
**En otros idiomas:** [English](README.md) · [Deutsch](README.de.md) · [中文](README.zh.md) · [हिन्दी](README.hi.md) · Español · [Русский](README.ru.md)

**Un solo archivo PHP para restablecer de forma segura los identificadores de intercambio obsoletos entre 1C y WooCommerce: GUID de productos, precios y almacenes.** La herramienta está dirigida al plugin **ItGalaxy WooCommerce Synchronization 1C** — «WooCommerce — 1C:Enterprise — Data Exchange» — y resulta relevante ante todo después de actualizar la configuración o la versión de 1C, cuando los identificadores del intercambio anterior dejan de coincidir con los datos actuales.

El script elimina los identificadores antiguos y los datos de servicio relacionados **sin borrar productos**. En la siguiente sincronización el plugin de ItGalaxy encuentra cada producto **por SKU** y escribe su ID / GUID actual desde 1C. Los vínculos de precios y almacenes se reconstruyen al mismo tiempo.

> [!IMPORTANT]
> Los registros de productos y variaciones en `{prefix}posts` **no se borran nunca**. El script trabaja únicamente con metadatos, opciones de servicio y la tabla de caché de WooCommerce. Haga siempre una copia de seguridad de la base de datos antes de ejecutarlo.

---

## Índice

- [Propósito y caso de uso](#propósito-y-caso-de-uso)
- [Cómo se resuelve el problema con los GUID](#cómo-se-resuelve-el-problema-con-los-guid)
- [Funcionalidades](#funcionalidades)
- [Qué cambia en la base de datos](#qué-cambia-en-la-base-de-datos)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Primera ejecución y contraseña de acceso](#primera-ejecución-y-contraseña-de-acceso)
- [Restablecimiento de una contraseña olvidada](#restablecimiento-de-una-contraseña-olvidada)
- [Modo de depuración](#modo-de-depuración)
- [Flujo de trabajo](#flujo-de-trabajo)
- [Modo CLI](#modo-cli)
- [Arquitectura y seguridad](#arquitectura-y-seguridad)
- [Copia de seguridad y reversión](#copia-de-seguridad-y-reversión)
- [Resolución de problemas](#resolución-de-problemas)
- [Preguntas frecuentes](#preguntas-frecuentes)
- [Hoja de ruta](#hoja-de-ruta)
- [Licencia y autor](#licencia-y-autor)

---

## Propósito y caso de uso

El script está creado para el plugin de ItGalaxy **WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange**. La documentación oficial y las instrucciones del plugin se encuentran en el sitio web de ITGALAXY: [«WooCommerce — 1C:Enterprise — Data Exchange — Instrucciones»](https://itgalaxy.company/software/wordpress-woocommerce-1c-%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8/woocommerce-1c%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8-%D0%B8%D0%BD%D1%81/).

Un escenario típico es el siguiente:

1. En el lado de 1C se actualiza la configuración, 1C pasa a una nueva versión, la base de datos se restaura desde una copia de seguridad, los datos maestros de artículos se migran o se modifican las reglas de intercambio.
2. Como resultado, los GUID / ID de productos, tipos de precio o almacenes que el plugin guardó antes en WooCommerce pueden quedar obsoletos o desaparecer de la nueva versión de 1C.
3. El nuevo intercambio no puede emparejar algunos objetos por sus identificadores antiguos: aparecen duplicados, además de precios, existencias o almacenes «atascados».
4. El administrador ejecuta este script y borra únicamente los identificadores desactualizados y los datos de intercambio relacionados.
5. En el siguiente intercambio el plugin de ItGalaxy empareja los productos **por SKU** y escribe los nuevos ID / GUID de la base de datos actual de 1C.
6. Los precios y las existencias se cargan de nuevo según los tipos de precio y los almacenes actuales en 1C.

Precisamente por eso no es un «borrador de catálogo», sino una utilidad de servicio para volver a vincular de forma controlada los productos existentes con un 1C actualizado.

### Qué síntomas elimina

- Después de actualizar 1C los productos empezaron a duplicarse durante el intercambio.
- Los precios no se actualizan, quedan vacíos o pertenecen a tipos de precio antiguos.
- Las existencias de almacén no llegan, se muestran de forma incorrecta o quedan «atascadas» desde la base de datos antigua.
- Precios, almacenes o GUID inexistentes permanecen en los ajustes del plugin y en las listas de WooCommerce.
- Un catálogo de WooCommerce existente tiene que conectarse con una base de datos de 1C nueva o restaurada sin borrar productos a mano.

> [!WARNING]
> Antes de la limpieza, asegúrese de que los SKU en 1C estén rellenos y sean únicos. Precisamente el SKU es la clave que se usa para volver a emparejar un producto en la siguiente sincronización. Si distintos productos comparten un SKU o falta un SKU, el emparejamiento automático puede resultar ambiguo.

---

## Cómo se resuelve el problema con los GUID

### Antes de la limpieza

```text
WooCommerce                         Old / updated 1C
───────────                         ──────────────────
Product: "Battery X"                Product: "Battery X"
SKU: BAT-X-100                      SKU: BAT-X-100
_id_1c: OLD-GUID-123   ────────X──> GUID: NEW-GUID-987
```

Después de actualizar 1C, el antiguo `OLD-GUID-123` escrito antes en `wp_postmeta` puede faltar o puede pertenecer ya a otro objeto. El plugin no debe seguir usando el vínculo obsoleto.

### Después de la limpieza y de un nuevo intercambio

```text
1. The script deletes the old _id_1c, the old price / stock data and the GUID maps.

2. The ItGalaxy plugin runs the exchange:

WooCommerce                         1C
───────────                         ──────────────────────
Product: "Battery X"                Product: "Battery X"
SKU: BAT-X-100   <───────────>      SKU: BAT-X-100

3. Matching by SKU succeeds:
   WooCommerce postmeta._id_1c = NEW-GUID-987
   WooCommerce receives current prices and stock.
```

Así, el script crea un «punto de partida limpio» para una sincronización repetida. No genera GUID por sí mismo ni modifica los datos maestros de artículos: los identificadores actuales los aporta 1C, y el plugin de ItGalaxy los escribe en WordPress durante el intercambio.

---

## Funcionalidades

- **Ámbito de aplicación:** funciona con ItGalaxy WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange.
- **Cuatro operaciones:** limpieza de GUID de 1C, de precios, de existencias o una limpieza completa de los tres grupos en una sola transacción.
- **Sin borrado del catálogo:** los registros de `posts`, los productos, las variaciones, los títulos, las descripciones, las imágenes, las categorías y los SKU no se borran.
- **Vista previa:** antes de ejecutar se ven los recuentos de productos, GUID, filas de precios y de existencias, tipos de precio y almacenes.
- **Detección automática de WordPress:** los parámetros de la base de datos se leen de `wp-config.php`; el archivo se busca en el directorio del script y hasta tres niveles por encima.
- **Transacciones:** `START TRANSACTION` → operaciones → `COMMIT`; si se produce una excepción se ejecuta `ROLLBACK`.
- **Consultas preparadas:** se prefiere PDO MySQL y se usa mysqli como alternativa; los valores del usuario nunca se concatenan en el SQL.
- **Contraseña y ACL:** la contraseña se crea en la primera ejecución, se guarda solo su hash y una IP autorizada se recuerda durante 30 días.
- **Interfaz AJAX:** la página no se recarga; el botón cambia de estado mientras dura la operación, los demás botones se bloquean y los errores de consola no se borran.
- **Modo de depuración:** un registro completo de eventos del servidor y del cliente, SQL, recuentos de filas y tiempos — desde el conmutador de la interfaz o mediante `?debug=1`.
- **Recuperación del acceso por CLI:** comandos para comprobar, generar y establecer la contraseña manualmente.
- **Un solo archivo:** sin Composer, sin bibliotecas adicionales y sin arranque de WordPress.

---

## Qué cambia en la base de datos

`{prefix}` es el valor de `$table_prefix` de `wp-config.php`; en una instalación estándar de WordPress es `wp_`.

| Datos del intercambio de ItGalaxy | Dónde se guardan | Qué hace el script |
|---|---|---|
| GUID de producto y de variación | `{prefix}postmeta.meta_key = '_id_1c'` | Borrado para `product` y `product_variation` |
| GUID de categoría y de etiqueta | `{prefix}termmeta.meta_key = '_id_1c'` | Borrado de los identificadores antiguos |
| GUID de atributo | `{prefix}woocommerce_attribute_taxonomies.id_1c` | Campo vaciado a una cadena vacía |
| Mapas de GUID | `{prefix}options`: `all1cProducts`, `currentAll1cGroup`, `itglx_wc1c_nomenclature_categories` | Valor sustituido por un array serializado vacío |
| Precios por tipo de precio | `{prefix}postmeta.meta_key = '_all_prices'` | Borrado |
| Precios base de WooCommerce | `{prefix}postmeta`: `_price`, `_regular_price`, `_sale_price` | Borrado — los valores actuales llegan de 1C |
| Lista de tipos de precio | `{prefix}options.option_name = 'all_prices_types'` | Array vaciado |
| Ajustes de correspondencia de tipos de precio | `wc-itgalaxy-1c-exchange-settings`, claves `price_type_*` | Borrado de las claves `price_type_N`, `price_type_N_text`, `price_type_N_summ` |
| Existencias por almacén | `{prefix}postmeta.meta_key = '_separate_warehouse_stock'` | Borrado |
| Existencias totales | `{prefix}postmeta.meta_key = '_stock'` | Borrado |
| Lista de almacenes | `{prefix}options.option_name = 'all_1c_stocks'` | Array vaciado |
| Estados de existencias | `{prefix}postmeta`: `_stock_status`, `_manage_stock`, `_backorders` | Valores neutros: `instock`, `no`, `no` |
| Caché de precios de WooCommerce | `{prefix}wc_product_meta_lookup`: `min_price`, `max_price`, `onsale` | Puesta a cero |
| Caché de existencias de WooCommerce | `{prefix}wc_product_meta_lookup`: `stock_quantity`, `stock_status` | `0` e `instock` |

La ausencia de la tabla `woocommerce_attribute_taxonomies` o `wc_product_meta_lookup` se detecta automáticamente y se omite con una entrada en el registro.

### Correspondencia entre botones y escenarios

| Botón | Qué se limpia | Cuándo usarlo |
|---|---|---|
| **«Очистить GUID 1C»** (Limpiar GUID de 1C) | Los `_id_1c` antiguos, los GUID de categoría/etiqueta/atributo y los mapas de GUID | Después de una actualización o migración de 1C, con duplicados y vínculos incorrectos |
| **«Очистить цены»** (Limpiar precios) | Los precios antiguos, los tipos de precio, `price_type_*`, la caché de precios | Cuando los tipos de precio cambiaron tras la actualización de 1C o los precios no se sincronizan |
| **«Очистить остатки»** (Limpiar existencias) | Las existencias antiguas, los almacenes, los estados de existencias y la caché | Cuando los almacenes cambiaron, las existencias están «atascadas» o no llegan de 1C |
| **«Очистить всё»** (Limpiar todo) | Todos los grupos anteriores en una sola transacción | Después de una actualización o migración completa de la base de datos de 1C |

---

## Requisitos

| Componente | Mínimo | Nota |
|---|---|---|
| PHP | 8.3 | Se usan `enum`, `readonly`, `match` y tipado estricto |
| Extensiones de PHP | `pdo_mysql` **o** `mysqli` | Se prefiere PDO; también se necesitan las extensiones estándar `json`, `session` y `hash` |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.3 | Soporte de transacciones InnoDB y de `DELETE … INNER JOIN` |
| WordPress | 5.6+ | Se requiere un `wp-config.php` legible |
| WooCommerce | 6+ | La tabla de consulta es opcional, su ausencia está contemplada |
| Plugin de intercambio | ItGalaxy WooCommerce Synchronization 1C | «WooCommerce — 1C:Enterprise — Data Exchange» |
| Permisos del sistema de archivos | Acceso de escritura al directorio del script | Para el archivo con el hash de la contraseña y para la ACL |
| Navegador | Moderno | `fetch`, `URL` y, opcionalmente, la API del portapapeles |

---

## Instalación

### 1. Copia de seguridad de la base de datos

```bash
# Full backup dump before cleaning
mysqldump --single-transaction --routines --triggers -u USER -p DBNAME \
  | gzip > backup-before-wc1c-clean-$(date +%F-%H%M).sql.gz
```

### 2. Descarga del script en la raíz de WordPress

El archivo debe estar junto a `wp-config.php` o a no más de tres niveles de distancia de él.

```bash
cd /var/www/example.com/public_html
curl -fsSLO https://raw.githubusercontent.com/paulmann/woocommerce-1c-data-cleaner/main/1c-data-cleaner.php

# Example permissions — adjust the web server user for your environment
chmod 640 1c-data-cleaner.php
chown www-data:www-data 1c-data-cleaner.php
```

### 3. Restricción del acceso a nivel de servidor web

La contraseña del script no sustituye la protección de red. Permitir el acceso solo desde su propia IP es una salvaguarda adicional que vale la pena añadir.

**Nginx:**

```nginx
location = /1c-data-cleaner.php {
    allow 203.0.113.10; # Replace with your own external IP
    deny all;

    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}

# The auth store and the reset marker must never be served over HTTP
location ~ /\.wc1c-cleaner- {
    deny all;
    return 404;
}
```

**Apache / `.htaccess`:**

```apache
<Files "1c-data-cleaner.php">
    Require ip 203.0.113.10
</Files>

<FilesMatch "^\.wc1c-cleaner-">
    Require all denied
</FilesMatch>
```

### 4. Apertura de la interfaz

Abra en su navegador:

```text
https://example.com/1c-data-cleaner.php
```

En la primera ejecución el script genera una contraseña, autoriza la sesión actual y muestra la contraseña una sola vez.

---

## Primera ejecución y contraseña de acceso

En la primera solicitud ocurre lo siguiente:

1. Se genera una contraseña de 20 caracteres con el generador criptográficamente seguro `random_int()`.
2. En `.wc1c-cleaner-auth.json` se escribe **solo el hash** de la contraseña: Argon2id si PHP lo ofrece, en caso contrario bcrypt mediante `PASSWORD_DEFAULT`.
3. El archivo se escribe de forma atómica (`.tmp` + `rename`) y recibe permisos `0600`.
4. El usuario actual se autoriza automáticamente y su IP se añade a la ACL.
5. La contraseña se muestra una sola vez, con la opción de copiarla.

```text
┌────────────────────────────────────────────────────────────────────┐
│ Пароль доступа сгенерирован — сохраните его сейчас                 │
│                                                                    │
│ 7hK2mQ+xR9tLpZ4wNb3e                          [Скопировать]        │
│                                                                    │
│ Он нужен для входа с другого IP или другого компьютера.           │
│ На текущем IP пароль больше не спрашивается.                      │
└────────────────────────────────────────────────────────────────────┘
```

### Modelo de acceso

- La sesión PHP actual queda autorizada, así que la contraseña no se vuelve a pedir hasta que la sesión termine.
- Una IP autorizada correctamente se escribe en la ACL durante 30 días. La IP en sí no se guarda en el archivo: se usa `HMAC-SHA-256` con una sal aleatoria independiente.
- Desde otra IP u otro ordenador se muestra un formulario de acceso — se requiere la contraseña guardada.
- Tras 8 intentos fallidos dentro de una misma sesión se bloquea la entrada; cada contraseña incorrecta añade un retraso de 400 ms.
- «Выйти и забыть IP» (Cerrar sesión y olvidar esta IP) destruye la sesión y elimina la IP actual de la ACL.

> [!NOTE]
> Si el sitio está detrás de un proxy inverso o de Cloudflare, active `Config::TRUST_PROXY = true` cuando sea necesario. Hágalo solo cuando el proxy reescriba correctamente la IP del cliente: en el acceso directo la cabecera `X-Forwarded-For` se puede falsificar.

---

## Restablecimiento de una contraseña olvidada

Un restablecimiento cambia la sal de la ACL, por lo que todos los clientes — incluidas las IP de confianza anteriores — tienen que introducir la nueva contraseña.

### Opción 1: SSH / CLI — recomendado

```bash
# Show the state of the access store
php 1c-data-cleaner.php --status

# Generate a new password and print it to the console
php 1c-data-cleaner.php --reset

# Set your own password manually, at least 10 characters
php 1c-data-cleaner.php --status
```

### Opción 2: FTP o un gestor de archivos

Elimine el archivo de almacén que está junto al script:

```bash
rm /var/www/example.com/public_html/.wc1c-cleaner-auth.json
```

La próxima vez que se abra la página se crea una nueva contraseña y se muestra una sola vez.

### Opción 3: archivo marcador de restablecimiento

Si se pueden crear archivos pero no se deben eliminar los existentes:

```bash
touch /var/www/example.com/public_html/.wc1c-cleaner-reset
```

En la siguiente solicitud HTTP el script elimina el almacén antiguo, borra el marcador y crea un nuevo par de contraseña + ACL.

---

## Modo de depuración

El modo de depuración se activa sin tocar el código:

- con el conmutador **«Режим отладки»** (Modo de depuración) en la parte superior de la interfaz;
- con el parámetro de URL `?debug=1` para activarlo;
- con el parámetro de URL `?debug=0` para desactivarlo.

El estado se conserva en la sesión PHP actual. Con la depuración activada, el script muestra y envía a la consola del navegador un registro completo:

```text
+    0.31 ms  INFO  Входящий запрос {"method":"POST","php":"8.3.14","debug":true}
+    1.08 ms  INFO  wp-config.php найден {"path":"/var/www/.../wp-config.php"}
+    3.42 ms  INFO  Подключение к БД установлено {"driver":"PDO","port":3306}
+   12.77 ms  INFO  Транзакция START
+   13.05 ms  INFO  Шаг 1/4: GUID товаров и вариаций
+  184.62 ms  DEBUG SQL выполнен {"rows":10432,"ms":171.2}
+  512.90 ms  INFO  Транзакция COMMIT
```

El registro abarca los eventos de autorización y de ACL, la validación de CSRF, la búsqueda de `wp-config.php`, el controlador de base de datos, las consultas SQL y sus tiempos, los límites de las transacciones, el número de filas afectadas, la vista previa antes y después de la limpieza, además de los errores de JavaScript y los errores de red del cliente. Las contraseñas, los hashes, los tokens y las sales se enmascaran como `***`.

---

## Flujo de trabajo

1. Compruebe que el 1C actual tiene SKU correctos y únicos en los productos.
2. Cree una copia de seguridad de la base de datos.
3. Detenga el intercambio automático con 1C mientras dure el trabajo, si funciona según una programación.
4. Suba `1c-data-cleaner.php` al directorio de WordPress y ábralo en un navegador.
5. En la primera ejecución, guarde la contraseña generada.
6. Active el modo de depuración si quiere registrar el progreso detallado de la operación.
7. Revise la tabla **«Текущее состояние»** (Estado actual) y elija el alcance de limpieza que necesita.
8. Marque la casilla «Я понимаю, что данные будут удалены, бэкап сделан» (Entiendo que los datos se eliminarán y que existe una copia de seguridad).
9. Pulse el botón de limpieza y confirme la acción en el diálogo.
10. Asegúrese de que el bloque «Результат» (Resultado) no contiene errores y de que el registro de depuración contiene `Транзакция COMMIT`.
11. Inicie la sincronización en el plugin de ItGalaxy. Los productos se emparejan por SKU, después de lo cual el plugin crea los GUID / ID, los precios y las existencias actuales.
12. Compruebe varios productos, precios y existencias en la tienda y en el panel de WordPress.
13. Elimine `1c-data-cleaner.php`, `.wc1c-cleaner-auth.json` y, si existe, `.wc1c-cleaner-reset` del servidor.

> [!TIP]
> Para una actualización completa de 1C suele bastar una ejecución de «Очистить всё» (Limpiar todo), seguida de un intercambio completo. Para un problema local es más seguro elegir el botón específico: por ejemplo, solo «Очистить цены» (Limpiar precios), si no ha cambiado nada más que los tipos de precio.

---

## Modo CLI

El modo CLI está pensado solo para la gestión del acceso, no para borrar datos de la base de datos.

```bash
$ php 1c-data-cleaner.php --status
WooCommerce 1C Data Cleaner 3.0.0
Хранилище: /var/www/example.com/public_html/.wc1c-cleaner-auth.json
Создано: 2026-08-27 15:42:10
Тип пароля: сгенерирован автоматически
Доверенных IP: 2
```

| Comando | Resultado |
|---|---|
| `php 1c-data-cleaner.php --status` | Estado del archivo de acceso, fecha de creación, número de IP de confianza |
| `php 1c-data-cleaner.php --reset` | Crea e imprime una nueva contraseña aleatoria, restablece la ACL |
| `php 1c-data-cleaner.php --set-password='…'` | Establece una contraseña de administrador de al menos 10 caracteres y restablece la ACL |
| `php 1c-data-cleaner.php --help` | Imprime una breve guía de referencia |

---

## Arquitectura y seguridad

El script es un único archivo PHP con `declare(strict_types=1)`, dividido en componentes pequeños.

| Componente | Responsabilidad |
|---|---|
| `Config` | Versión, metadatos, conjuntos de claves de intercambio, TTL de la ACL, ruta a los archivos de servicio |
| `EventLog` | Registro de eventos con tiempos y enmascaramiento de valores sensibles |
| `Credentials` | Generación y hash de la contraseña, ACL por IP, escritura atómica del almacén, marcador de restablecimiento |
| `Session` / `Debug` | Cookies seguras, CSRF, almacenamiento y conmutación del modo de depuración |
| `Auth` | Autorización por sesión, IP y contraseña, límite de intentos, cierre de sesión |
| `WpConfig` | Búsqueda y análisis seguro de los parámetros de `wp-config.php` sin incluirlo |
| `Db` | PDO/mysqli, consultas preparadas, transacciones, validación de identificadores de tabla |
| `Action` | Enumeración estricta de las operaciones disponibles |
| `Cleaner` | Limpieza de GUID, precios, existencias y mapas de servicio |
| `Preview` | Recuento de datos antes y después de las acciones |
| API JSON | `login`, `logout`, `ack_password`, `set_debug`, `preview`, `guid`, `prices`, `stocks`, `all` |

### Medidas de protección

- no hay concatenación de valores del usuario en el SQL: se usan consultas preparadas;
- los nombres de tabla se validan con la expresión regular `[A-Za-z0-9_]+`;
- `unserialize()` se llama con `allowed_classes => false`;
- el token CSRF se crea con `random_bytes(32)` y se comprueba con `hash_equals`;
- el ID de sesión se regenera después de una autorización correcta;
- las cookies de sesión llevan `HttpOnly`, `SameSite=Strict` y, sobre HTTPS, `Secure`;
- las respuestas llevan `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow` y una etiqueta meta robots;
- `display_errors=0`: los avisos y advertencias de PHP van al EventLog sin romper la respuesta JSON;
- `.gitignore` excluye el almacén de contraseñas, la ACL y el marcador de restablecimiento.

> [!WARNING]
> El script proporciona acceso directo a la base de datos y ejecuta `DELETE` / `UPDATE` masivos. No lo deje en una raíz web pública cuando termine el trabajo. La contraseña no sustituye la restricción de IP mediante Nginx, Apache, una VPN o HTTP Basic Auth.

---

## Copia de seguridad y reversión

### Copia rápida de las tablas afectadas

Sustituya `wp_` por el prefijo de tabla real si es distinto.

```bash
mysqldump --single-transaction -u USER -p DBNAME \
  wp_postmeta \
  wp_termmeta \
  wp_options \
  wp_woocommerce_attribute_taxonomies \
  wp_wc_product_meta_lookup \
  | gzip > wc1c-before-clean-$(date +%F-%H%M).sql.gz
```

### Restauración desde un volcado

```bash
gunzip < wc1c-before-clean-2026-08-27-1542.sql.gz | mysql -u USER -p DBNAME
```

Después de una restauración o de un nuevo intercambio completo se puede vaciar la caché:

```bash
wp cache flush
wp wc update_db
```

La transacción protege contra acciones aplicadas a medias solo **dentro de una misma ejecución**: si se produce un error antes de `COMMIT`, se ejecuta un `ROLLBACK`. Después de un `COMMIT` correcto la única forma de volver atrás es restaurar desde una copia de seguridad.

---

## Resolución de problemas

| Síntoma | Posible causa | Solución |
|---|---|---|
| «Файл wp-config.php не найден» | El script está a más de tres niveles de distancia de WordPress | Muévalo a la raíz de WordPress, junto a `wp-config.php` |
| «Не удалось распознать DB_NAME / DB_USER / $table_prefix» | Los parámetros de `wp-config.php` están definidos de forma no estándar, por ejemplo mediante `getenv()` | Use llamadas `define()` con cadenas o adapte `WpConfig::parse()` a su entorno |
| «На сервере не включено ни PDO MySQL, ни mysqli» | Faltan las extensiones MySQL de PHP | Active `pdo_mysql` o `mysqli` en el panel del hosting / `php.ini` |
| «Недействительный токен сессии» | La sesión caducó, las cookies están bloqueadas, el balanceador no mantiene la afinidad de sesión | Recargue la página, permita las cookies, revise la configuración de sesiones de PHP |
| La contraseña no se guarda | El directorio del script no tiene permiso de escritura, se usa una carpeta temporal | Dé al usuario web acceso de escritura al directorio del script |
| El botón muestra «Выполняется…» durante mucho tiempo | Una tabla `postmeta` grande, un bloqueo de MySQL o un tiempo de espera de PHP-FPM | Active la depuración, localice el SQL lento, revise los índices y `request_terminate_timeout` |
| Los precios o las existencias están vacíos tras la limpieza | Es el estado esperado hasta el siguiente intercambio | Inicie el intercambio en ItGalaxy y espere a que termine |
| Tras el intercambio aparecieron duplicados | Los SKU no coinciden o no son únicos | Corrija los SKU en 1C y en WooCommerce, elimine los duplicados incorrectos, repita un intercambio controlado |
| Las existencias quedan desactualizadas tras el intercambio | Caché de objeto, de página o de CDN | `wp cache flush`, restablezca la caché de WooCommerce y de la CDN |

---

## Preguntas frecuentes

### ¿Los productos, las imágenes y las descripciones se conservan de verdad?

Sí. El script no contiene ningún `DELETE` sobre `{prefix}posts`. No borra productos, variaciones, SKU, títulos, descripciones, archivos multimedia, categorías ni etiquetas. Solo se limpian los vínculos de servicio del intercambio, los precios, las existencias y los valores de caché relacionados.

### ¿Cuándo hay que usar el script?

El caso principal es la actualización de la versión o de la configuración de 1C, el traslado a otra base de datos, la restauración de la base de datos desde una copia de seguridad y otras situaciones en las que los GUID de productos, precios o almacenes antiguos ya no coinciden con los datos del nuevo 1C. La utilidad borra los identificadores antiguos; el siguiente intercambio crea otros nuevos.

### ¿Por qué los productos se vuelven a emparejar en 1C después de la limpieza?

Porque el plugin de ItGalaxy empareja los productos por SKU durante la sincronización. Si los SKU son idénticos y únicos, el plugin encuentra el producto existente de WooCommerce y escribe en él el ID / GUID actual de 1C en lugar de crear un producto nuevo.

### ¿Hay que limpiarlo todo?

No. Elija la operación más pequeña que necesite. Si solo cambiaron los tipos de precio, use «Очистить цены» (Limpiar precios). Si el problema afecta solo a los almacenes, use «Очистить остатки» (Limpiar existencias). «Очистить всё» (Limpiar todo) conviene para una actualización o migración completa de la base de datos de 1C, cuando no se puede confiar en ninguno de los identificadores antiguos.

### ¿Se puede deshacer la limpieza?

Dentro de la solicitud HTTP los errores se revierten con la transacción. Después de un `COMMIT` correcto los datos antiguos solo se pueden recuperar desde un volcado de copia de seguridad de la base de datos.

### ¿Se admiten otros plugins de intercambio con 1C?

No, el esquema de claves ya preparado está dirigido a ItGalaxy WooCommerce Synchronization 1C. Para otras soluciones hay que comprobar por separado dónde guardan los GUID, los precios y las existencias, y adaptar las constantes de `Config`.

### ¿Se admite WordPress Multisite?

El script usa un único `$table_prefix` de `wp-config.php` y trabaja con el sitio principal de forma predeterminada. Para los subsitios hay que adaptar el prefijo (`wp_2_`, `wp_3_`, etc.) después de una copia de seguridad previa y de una prueba sobre una copia.

### ¿Por qué la contraseña está vinculada a una IP?

Es una capa adicional de comodidad y protección: tras un único acceso correcto desde una dirección de trabajo de confianza, la contraseña no hay que introducirla de nuevo. Cuando la IP cambia, cuando se trabaja desde otro ordenador o después de restablecer la ACL, se requiere la contraseña guardada.

---

## Hoja de ruta

- [ ] `dry-run`: un recuento detallado de los próximos cambios sin modificar la base de datos
- [ ] Exportación del registro de depuración a JSON y a un archivo de texto
- [ ] Limpieza selectiva por categorías, productos y rangos de ID
- [ ] Soporte de WordPress Multisite con selección de subsitio
- [ ] Soporte de las configuraciones de claves de otros plugins de intercambio con 1C
- [ ] Confirmación de dos factores para las operaciones destructivas (TOTP)
- [ ] Comprobación automática de la unicidad de los SKU antes de borrar los GUID

---

## Licencia y autor

El proyecto se distribuye bajo la licencia [PolyForm Noncommercial 1.0.0](LICENSE) © 2026 Mikhail Deynekin. El programa se proporciona «tal cual», sin garantía; el usuario es el único responsable de las copias de seguridad y de las consecuencias de las operaciones sobre los datos.

**Mikhail Deynekin**

- Sitio web: [Deynekin.com](https://deynekin.com)
- GitHub: [paulmann (Mikhail Deynekin)](https://github.com/paulmann/)
- Repositorio: [woocommerce-1c-data-cleaner](https://github.com/paulmann/woocommerce-1c-data-cleaner)
- Otra herramienta: [Clean_BOM_Senior](https://github.com/paulmann/Clean_BOM_Senior) — elimina el BOM UTF-8 invisible y los CRLF de los archivos de código

Si la herramienta le ayudó a revincular correctamente el catálogo después de actualizar 1C, dé una estrella al repositorio.
