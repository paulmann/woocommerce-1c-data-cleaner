# WooCommerce 1C Data Cleaner

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6%2B-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![MySQL / MariaDB](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)](1c-data-cleaner.php)
[![Einzeldatei](https://img.shields.io/badge/deploy-single%20file-informational)](1c-data-cleaner.php)

<!-- md-i18n:switcher -->
**In anderen Sprachen:** [English](README.md) · Deutsch · [中文](README.zh.md) · [हिन्दी](README.hi.md) · [Español](README.es.md) · [Русский](README.ru.md)

**Eine einzige PHP-Datei, um veraltete Austausch-IDs zwischen 1C und WooCommerce sicher zurückzusetzen: Produkt-, Preis- und Lager-GUIDs.** Das Werkzeug ist auf das Plugin **ItGalaxy WooCommerce Synchronization 1C** ausgerichtet — «WooCommerce — 1C:Enterprise — Data Exchange» — und ist vor allem nach einem Upgrade der Konfiguration oder der 1C-Version wichtig, wenn die Kennungen aus dem alten Austausch nicht mehr zu den aktuellen Daten passen.

Das Skript löscht die alten Kennungen und die zugehörigen Servicedaten **ohne Produkte zu löschen**. Bei der nächsten Synchronisation findet das ItGalaxy-Plugin jedes Produkt **über die SKU** und schreibt seine aktuelle ID / GUID aus 1C. Preis- und Lagerverknüpfungen werden dabei neu aufgebaut.

> [!IMPORTANT]
> Produkt- und Variantendatensätze in `{prefix}posts` werden **niemals gelöscht**. Das Skript arbeitet ausschließlich mit Metadaten, Service-Optionen und der Cache-Tabelle von WooCommerce. Vor dem Ausführen immer eine Sicherung der Datenbank anlegen.

---

## Inhaltsverzeichnis

- [Zweck und Einsatzfall](#zweck-und-einsatzfall)
- [Wie das GUID-Problem gelöst wird](#wie-das-guid-problem-gelöst-wird)
- [Funktionen](#funktionen)
- [Was sich in der Datenbank ändert](#was-sich-in-der-datenbank-ändert)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Erster Start und Zugangspasswort](#erster-start-und-zugangspasswort)
- [Zurücksetzen eines vergessenen Passworts](#zurücksetzen-eines-vergessenen-passworts)
- [Debug-Modus](#debug-modus)
- [Ablauf](#ablauf)
- [CLI-Modus](#cli-modus)
- [Architektur und Sicherheit](#architektur-und-sicherheit)
- [Backup und Rollback](#backup-und-rollback)
- [Fehlerbehebung](#fehlerbehebung)
- [FAQ](#faq)
- [Roadmap](#roadmap)
- [Lizenz und Autor](#lizenz-und-autor)

---

## Zweck und Einsatzfall

Das Skript ist für das ItGalaxy-Plugin **WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange** gebaut. Die offizielle Dokumentation und die Anleitungen zum Plugin liegen auf der ITGALAXY-Website: [«WooCommerce — 1C:Enterprise — Data Exchange — Anleitungen»](https://itgalaxy.company/software/wordpress-woocommerce-1c-%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8/woocommerce-1c%D0%BF%D1%80%D0%B5%D0%B4%D0%BF%D1%80%D0%B8%D1%8F%D1%82%D0%B8%D0%B5-%D0%BE%D0%B1%D0%BC%D0%B5%D0%BD-%D0%B4%D0%B0%D0%BD%D0%BD%D1%8B%D0%BC%D0%B8-%D0%B8%D0%BD%D1%81/).

Ein typisches Szenario sieht so aus:

1. Auf der 1C-Seite wird die Konfiguration aktualisiert, 1C wechselt auf eine neue Version, die Datenbank wird aus einer Sicherung wiederhergestellt, Stammdaten werden migriert oder die Austauschregeln werden geändert.
2. In der Folge können die Produkt-, Preisart- oder Lager-GUIDs / IDs, die das Plugin früher in WooCommerce gespeichert hat, veralten oder in der neuen 1C-Version verschwinden.
3. Der neue Austausch kann einige Objekte nicht mehr über ihre alten IDs zuordnen: Es entstehen Duplikate sowie „hängende" Preise, Bestände oder Lager.
4. Der Administrator führt dieses Skript aus und entfernt nur die veralteten Kennungen und die zugehörigen Austauschdaten.
5. Beim nächsten Austausch ordnet das ItGalaxy-Plugin die Produkte **über die SKU** zu und schreibt die neuen IDs / GUIDs aus der aktuellen 1C-Datenbank.
6. Preise und Bestände werden erneut gemäß den aktuellen Preisarten und Lagern in 1C geladen.

Genau deshalb ist das kein „Katalog-Löscher", sondern ein Servicewerkzeug, um vorhandene Produkte kontrolliert mit einer aktualisierten 1C neu zu verknüpfen.

### Welche Symptome es beseitigt

- Nach dem 1C-Upgrade begannen Produkte beim Austausch zu duplizieren.
- Preise werden nicht aktualisiert, bleiben leer oder gehören zu alten Preisarten.
- Lagerbestände kommen nicht an, werden falsch angezeigt oder „hängen" aus der alten Datenbank.
- Nicht existierende Preise, Lager oder GUIDs bleiben in den Plugin-Einstellungen und in den WooCommerce-Listen.
- Ein vorhandener WooCommerce-Katalog muss mit einer neuen oder wiederhergestellten 1C-Datenbank verbunden werden, ohne Produkte von Hand zu löschen.

> [!WARNING]
> Vor der Bereinigung sicherstellen, dass die SKUs in 1C gefüllt und eindeutig sind. Die SKU ist genau der Schlüssel, über den ein Produkt bei der nächsten Synchronisation neu zugeordnet wird. Wenn verschiedene Produkte eine SKU teilen oder eine SKU fehlt, kann die automatische Zuordnung mehrdeutig werden.

---

## Wie das GUID-Problem gelöst wird

### Vor der Bereinigung

```text
WooCommerce                         Old / updated 1C
───────────                         ──────────────────
Product: "Battery X"                Product: "Battery X"
SKU: BAT-X-100                      SKU: BAT-X-100
_id_1c: OLD-GUID-123   ────────X──> GUID: NEW-GUID-987
```

Nach dem 1C-Upgrade kann das alte `OLD-GUID-123`, das früher in `wp_postmeta` geschrieben wurde, fehlen oder bereits zu einem anderen Objekt gehören. Das Plugin darf die veraltete Verknüpfung nicht weiter verwenden.

### Nach der Bereinigung und einem neuen Austausch

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

Das Skript schafft also einen „sauberen Ausgangspunkt" für eine wiederholte Synchronisation. Es erzeugt selbst keine GUIDs und ändert keine Stammdaten: 1C liefert die aktuellen Kennungen, und das ItGalaxy-Plugin schreibt sie während des Austauschs in WordPress.

---

## Funktionen

- **Zielumfang:** arbeitet mit ItGalaxy WooCommerce Synchronization 1C / WooCommerce — 1C:Enterprise — Data Exchange.
- **Vier Operationen:** Bereinigen der 1C-GUIDs, der Preise, der Bestände oder vollständige Bereinigung aller drei Gruppen in einer Transaktion.
- **Kein Löschen des Katalogs:** Datensätze in `posts`, Produkte, Varianten, Titel, Beschreibungen, Bilder, Kategorien und SKUs werden nicht gelöscht.
- **Vorschau:** vor dem Lauf werden die Anzahl der Produkte, GUIDs, Preis- und Bestandszeilen, Preisarten und Lager angezeigt.
- **Automatische WordPress-Erkennung:** Datenbankparameter werden aus `wp-config.php` gelesen; die Datei wird im Skriptverzeichnis und bis zu drei Ebenen darüber gesucht.
- **Transaktionen:** `START TRANSACTION` → Operationen → `COMMIT`; bei einer Ausnahme läuft `ROLLBACK`.
- **Prepared Statements:** PDO MySQL bevorzugt, mysqli als Rückfall; Benutzerwerte werden nie in SQL konkateniert.
- **Passwort und ACL:** Beim ersten Lauf wird ein Passwort erzeugt, nur dessen Hash gespeichert, eine autorisierte IP für 30 Tage gemerkt.
- **AJAX-Oberfläche:** die Seite lädt nicht neu; die Schaltfläche ändert für die Dauer der Operation ihren Zustand, die übrigen Schaltflächen sind gesperrt, Konsolenfehler werden nicht überschrieben.
- **Debug-Modus:** vollständiges Protokoll von Server- und Client-Ereignissen, SQL, Zeilenzahlen und Laufzeiten — über den Schalter in der Oberfläche oder über `?debug=1`.
- **Wiederherstellung des CLI-Zugangs:** Befehle zum Prüfen, Erzeugen und manuellen Setzen des Passworts.
- **Eine Datei:** kein Composer, keine zusätzlichen Bibliotheken und kein WordPress-Bootstrap.

---

## Was sich in der Datenbank ändert

`{prefix}` ist der Wert von `$table_prefix` aus `wp-config.php`; bei einer Standardinstallation von WordPress ist er `wp_`.

| ItGalaxy-Austauschdaten | Wo sie gespeichert sind | Was das Skript tut |
|---|---|---|
| Produkt- und Varianten-GUID | `{prefix}postmeta.meta_key = '_id_1c'` | Löschen für `product` und `product_variation` |
| Kategorie- und Schlagwort-GUID | `{prefix}termmeta.meta_key = '_id_1c'` | Löschen der alten IDs |
| Attribut-GUID | `{prefix}woocommerce_attribute_taxonomies.id_1c` | Feld auf eine leere Zeichenkette gesetzt |
| GUID-Zuordnungen | `{prefix}options`: `all1cProducts`, `currentAll1cGroup`, `itglx_wc1c_nomenclature_categories` | Wert durch ein leeres serialisiertes Array ersetzt |
| Preise nach Preisart | `{prefix}postmeta.meta_key = '_all_prices'` | Löschen |
| Basispreise von WooCommerce | `{prefix}postmeta`: `_price`, `_regular_price`, `_sale_price` | Löschen — die aktuellen Werte kommen aus 1C |
| Liste der Preisarten | `{prefix}options.option_name = 'all_prices_types'` | Array geleert |
| Einstellungen der Preisart-Zuordnung | `wc-itgalaxy-1c-exchange-settings`, Schlüssel `price_type_*` | Löschen der Schlüssel `price_type_N`, `price_type_N_text`, `price_type_N_summ` |
| Bestand nach Lager | `{prefix}postmeta.meta_key = '_separate_warehouse_stock'` | Löschen |
| Gesamtbestand | `{prefix}postmeta.meta_key = '_stock'` | Löschen |
| Lagerliste | `{prefix}options.option_name = 'all_1c_stocks'` | Array geleert |
| Bestandsstatus | `{prefix}postmeta`: `_stock_status`, `_manage_stock`, `_backorders` | Neutrale Werte: `instock`, `no`, `no` |
| Preis-Cache von WooCommerce | `{prefix}wc_product_meta_lookup`: `min_price`, `max_price`, `onsale` | Auf null gesetzt |
| Bestands-Cache von WooCommerce | `{prefix}wc_product_meta_lookup`: `stock_quantity`, `stock_status` | `0` und `instock` |

Eine fehlende Tabelle `woocommerce_attribute_taxonomies` oder `wc_product_meta_lookup` wird automatisch erkannt und mit einem Protokolleintrag übersprungen.

### Zuordnung von Schaltflächen und Szenarien

| Schaltfläche | Was bereinigt wird | Wann sie zu verwenden ist |
|---|---|---|
| **«Очистить GUID 1C»** (1C-GUIDs löschen) | Alte `_id_1c`, Kategorie-, Schlagwort- und Attribut-GUIDs sowie GUID-Zuordnungen | Nach einem 1C-Upgrade oder einer Migration, bei Duplikaten und falschen Verknüpfungen |
| **«Очистить цены»** (Preise löschen) | Alte Preise, Preisarten, `price_type_*`, Preis-Cache | Wenn sich die Preisarten nach dem 1C-Upgrade geändert haben oder Preise nicht synchronisieren |
| **«Очистить остатки»** (Bestände löschen) | Alte Bestände, Lager, Bestandsstatus und Cache | Wenn sich Lager geändert haben, der Bestand „hängt" oder nicht aus 1C ankommt |
| **«Очистить всё»** (Alles löschen) | Alle oben genannten Gruppen in einer Transaktion | Nach einem umfassenden Upgrade oder einer Migration der 1C-Datenbank |

---

## Voraussetzungen

| Komponente | Minimum | Hinweis |
|---|---|---|
| PHP | 8.3 | `enum`, `readonly`, `match` und strikte Typisierung werden verwendet |
| PHP-Erweiterungen | `pdo_mysql` **oder** `mysqli` | PDO wird bevorzugt; zusätzlich sind `json`, `session` und `hash` erforderlich |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.3 | InnoDB-Transaktionen und `DELETE … INNER JOIN` werden unterstützt |
| WordPress | 5.6+ | Eine lesbare `wp-config.php` ist erforderlich |
| WooCommerce | 6+ | Die Lookup-Tabelle ist optional, ihr Fehlen wird behandelt |
| Austausch-Plugin | ItGalaxy WooCommerce Synchronization 1C | «WooCommerce — 1C:Enterprise — Data Exchange» |
| Dateisystemrechte | Schreibzugriff auf das Skriptverzeichnis | Für die Passwort-Hash-Datei und die ACL |
| Browser | Modern | `fetch`, `URL`, optional die Clipboard API |

---

## Installation

### 1. Datenbank sichern

```bash
# Full backup dump before cleaning
mysqldump --single-transaction --routines --triggers -u USER -p DBNAME \
  | gzip > backup-before-wc1c-clean-$(date +%F-%H%M).sql.gz
```

### 2. Das Skript in das WordPress-Wurzelverzeichnis laden

Die Datei muss neben `wp-config.php` liegen oder höchstens drei Ebenen davon entfernt sein.

```bash
cd /var/www/example.com/public_html
curl -fsSLO https://raw.githubusercontent.com/paulmann/woocommerce-1c-data-cleaner/main/1c-data-cleaner.php

# Example permissions — adjust the web server user for your environment
chmod 640 1c-data-cleaner.php
chown www-data:www-data 1c-data-cleaner.php
```

### 3. Zugriff auf Webserver-Ebene einschränken

Das Passwort des Skripts ersetzt keinen Netzwerkschutz. Der Zugriff ausschließlich von der eigenen IP ist eine zusätzliche Absicherung, die sich lohnt.

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

### 4. Oberfläche öffnen

Im Browser öffnen:

```text
https://example.com/1c-data-cleaner.php
```

Beim ersten Lauf erzeugt das Skript ein Passwort, autorisiert die aktuelle Sitzung und zeigt das Passwort nur einmal an.

---

## Erster Start und Zugangspasswort

Beim ersten Aufruf geschieht Folgendes:

1. Ein Passwort mit 20 Zeichen wird mit dem kryptografisch sicheren Generator `random_int()` erzeugt.
2. **Nur der Hash** des Passworts wird in `.wc1c-cleaner-auth.json` geschrieben: Argon2id, wenn PHP es bereitstellt, sonst bcrypt über `PASSWORD_DEFAULT`.
3. Die Datei wird atomar geschrieben (`.tmp` + `rename`) und erhält die Rechte `0600`.
4. Der aktuelle Benutzer wird automatisch autorisiert, seine IP wird der ACL hinzugefügt.
5. Das Passwort wird einmal angezeigt, mit einer Kopieroption.

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

### Zugangsmodell

- Die aktuelle PHP-Sitzung ist autorisiert, daher wird das Passwort bis zum Ende der Sitzung nicht erneut abgefragt.
- Eine erfolgreich autorisierte IP wird für 30 Tage in die ACL geschrieben. Die IP selbst wird nicht in der Datei gespeichert: verwendet wird `HMAC-SHA-256` mit einem eigenen zufälligen Salt.
- Von einer anderen IP oder einem anderen Rechner wird ein Anmeldeformular angezeigt — das gespeicherte Passwort ist erforderlich.
- Nach 8 fehlgeschlagenen Versuchen innerhalb einer Sitzung wird die weitere Eingabe gesperrt; jedes falsche Passwort fügt eine Verzögerung von 400 ms hinzu.
- «Выйти и забыть IP» (Abmelden und diese IP vergessen) zerstört die Sitzung und entfernt die aktuelle IP aus der ACL.

> [!NOTE]
> Wenn die Website hinter einem Reverse-Proxy oder Cloudflare steht, bei Bedarf `Config::TRUST_PROXY = true` aktivieren. Nur dann, wenn der Proxy die Client-IP korrekt weitergibt: bei direktem Zugriff kann der Header `X-Forwarded-For` gefälscht werden.

---

## Zurücksetzen eines vergessenen Passworts

Ein Zurücksetzen ändert das Salt der ACL, daher muss jeder Client — auch bisher vertrauenswürdige IPs — das neue Passwort erneut eingeben.

### Option 1: SSH / CLI — empfohlen

```bash
# Show the state of the access store
php 1c-data-cleaner.php --status

# Generate a new password and print it to the console
php 1c-data-cleaner.php --reset

# Set your own password manually, at least 10 characters
php 1c-data-cleaner.php --status
```

### Option 2: FTP oder ein Dateimanager

Die Speicherdatei neben dem Skript löschen:

```bash
rm /var/www/example.com/public_html/.wc1c-cleaner-auth.json
```

Beim nächsten Öffnen der Seite wird ein neues Passwort erzeugt und einmal angezeigt.

### Option 3: Reset-Marker-Datei

Wenn Dateien angelegt, vorhandene aber nicht gelöscht werden sollen:

```bash
touch /var/www/example.com/public_html/.wc1c-cleaner-reset
```

Beim nächsten HTTP-Aufruf löscht das Skript den alten Speicher, löscht den Marker und erzeugt ein neues Paar aus Passwort und ACL.

---

## Debug-Modus

Der Debug-Modus wird ohne Eingriff in den Code eingeschaltet:

- über den Schalter **«Режим отладки»** (Debug-Modus) oben in der Oberfläche;
- über den URL-Parameter `?debug=1` zum Einschalten;
- über den URL-Parameter `?debug=0` zum Ausschalten.

Der Zustand wird in der aktuellen PHP-Sitzung gehalten. Bei aktiviertem Debug zeigt das Skript ein vollständiges Protokoll an und sendet es an die Browserkonsole:

```text
+    0.31 ms  INFO  Входящий запрос {"method":"POST","php":"8.3.14","debug":true}
+    1.08 ms  INFO  wp-config.php найден {"path":"/var/www/.../wp-config.php"}
+    3.42 ms  INFO  Подключение к БД установлено {"driver":"PDO","port":3306}
+   12.77 ms  INFO  Транзакция START
+   13.05 ms  INFO  Шаг 1/4: GUID товаров и вариаций
+  184.62 ms  DEBUG SQL выполнен {"rows":10432,"ms":171.2}
+  512.90 ms  INFO  Транзакция COMMIT
```

Das Protokoll umfasst Autorisierungs- und ACL-Ereignisse, die CSRF-Prüfung, die Suche nach `wp-config.php`, den Datenbanktreiber, SQL-Abfragen und ihre Laufzeiten, Transaktionsgrenzen, die Anzahl betroffener Zeilen, die Vorschau vor und nach der Bereinigung sowie JavaScript-Fehler und Netzwerkfehler des Clients. Passwörter, Hashes, Token und Salts werden als `***` maskiert.

---

## Ablauf

1. Prüfen, dass die aktuelle 1C korrekte und eindeutige SKUs an den Produkten hat.
2. Eine Sicherung der Datenbank anlegen.
3. Den automatischen Austausch mit 1C für die Dauer der Arbeiten anhalten, wenn er nach Zeitplan läuft.
4. `1c-data-cleaner.php` in das WordPress-Verzeichnis hochladen und im Browser öffnen.
5. Beim ersten Lauf das erzeugte Passwort speichern.
6. Den Debug-Modus einschalten, wenn der detaillierte Verlauf der Operation festgehalten werden soll.
7. Die Tabelle **«Текущее состояние»** (Aktueller Zustand) prüfen und den benötigten Bereinigungsumfang wählen.
8. Das Kontrollkästchen «Я понимаю, что данные будут удалены, бэкап сделан» (Ich verstehe, dass die Daten gelöscht werden und eine Sicherung existiert) anhaken.
9. Die Schaltfläche für die Bereinigung drücken und die Aktion im Dialog bestätigen.
10. Sicherstellen, dass der Block «Результат» (Ergebnis) keine Fehler enthält und das Debug-Protokoll `Транзакция COMMIT` aufweist.
11. Die Synchronisation im ItGalaxy-Plugin starten. Die Produkte werden über die SKU zugeordnet, danach erzeugt das Plugin die aktuellen GUIDs / IDs, Preise und Bestände.
12. Mehrere Produkte, Preise und Bestände im Shop und im WordPress-Admin prüfen.
13. `1c-data-cleaner.php`, `.wc1c-cleaner-auth.json` und ein eventuelles `.wc1c-cleaner-reset` vom Server löschen.

> [!TIP]
> Für ein umfassendes 1C-Upgrade genügt meist ein Lauf von «Очистить всё» (Alles löschen) mit anschließendem vollständigem Austausch. Bei einem lokalen Problem ist die gezielte Schaltfläche sicherer: zum Beispiel nur «Очистить цены» (Preise löschen), wenn sich nichts außer den Preisarten geändert hat.

---

## CLI-Modus

Der CLI-Modus ist nur für die Verwaltung des Zugangs gedacht, nicht für das Löschen von Datenbankdaten.

```bash
$ php 1c-data-cleaner.php --status
WooCommerce 1C Data Cleaner 3.0.0
Хранилище: /var/www/example.com/public_html/.wc1c-cleaner-auth.json
Создано: 2026-08-27 15:42:10
Тип пароля: сгенерирован автоматически
Доверенных IP: 2
```

| Befehl | Ergebnis |
|---|---|
| `php 1c-data-cleaner.php --status` | Zustand der Zugangsdatei, Erstellungsdatum, Anzahl vertrauenswürdiger IPs |
| `php 1c-data-cleaner.php --reset` | Erzeugt ein neues Zufallspasswort, gibt es aus und setzt die ACL zurück |
| `php 1c-data-cleaner.php --set-password='…'` | Setzt ein Administratorpasswort mit mindestens 10 Zeichen und setzt die ACL zurück |
| `php 1c-data-cleaner.php --help` | Gibt eine kurze Übersicht aus |

---

## Architektur und Sicherheit

Das Skript ist eine einzige PHP-Datei mit `declare(strict_types=1)`, in kleine Komponenten gegliedert.

| Komponente | Aufgabe |
|---|---|
| `Config` | Version, Metadaten, Schlüsselsätze des Austauschs, ACL-TTL, Pfad zu den Servicedateien |
| `EventLog` | Ereignisprotokoll mit Laufzeiten und Maskierung sensibler Werte |
| `Credentials` | Erzeugung und Hashing des Passworts, ACL pro IP, atomares Schreiben des Speichers, Reset-Marker |
| `Session` / `Debug` | Sichere Cookies, CSRF, Speicherung und Umschalten des Debug-Modus |
| `Auth` | Autorisierung über Sitzung, IP und Passwort, Versuchslimit, Abmelden |
| `WpConfig` | Suche nach und sicheres Parsen der Parameter von `wp-config.php` ohne deren Einbindung |
| `Db` | PDO/mysqli, Prepared Statements, Transaktionen, Prüfung der Tabellenbezeichner |
| `Action` | Strikte Aufzählung der verfügbaren Operationen |
| `Cleaner` | Bereinigung von GUIDs, Preisen, Beständen und Service-Zuordnungen |
| `Preview` | Zählen der Daten vor und nach den Aktionen |
| JSON-API | `login`, `logout`, `ack_password`, `set_debug`, `preview`, `guid`, `prices`, `stocks`, `all` |

### Schutzmaßnahmen

- keine SQL-Konkatenation von Benutzerwerten: es werden Prepared Statements verwendet;
- Tabellennamen werden mit dem regulären Ausdruck `[A-Za-z0-9_]+` geprüft;
- `unserialize()` wird mit `allowed_classes => false` aufgerufen;
- das CSRF-Token wird mit `random_bytes(32)` erzeugt und mit `hash_equals` geprüft;
- die Sitzungs-ID wird nach einer erfolgreichen Autorisierung neu erzeugt;
- Sitzungs-Cookies tragen `HttpOnly`, `SameSite=Strict` und über HTTPS `Secure`;
- Antworten tragen `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow` und ein Meta-robots-Tag;
- `display_errors=0`: PHP-Hinweise und -Warnungen gehen in das EventLog, ohne die JSON-Antwort zu beschädigen;
- `.gitignore` schließt den Passwortspeicher, die ACL und den Reset-Marker aus.

> [!WARNING]
> Das Skript bietet direkten Zugriff auf die Datenbank und führt massenhafte `DELETE` / `UPDATE` aus. Es darf nach Abschluss der Arbeiten nicht im öffentlichen Web-Wurzelverzeichnis bleiben. Das Passwort ersetzt keine Einschränkung der IPs über Nginx, Apache, ein VPN oder HTTP Basic Auth.

---

## Backup und Rollback

### Schnelles Backup der betroffenen Tabellen

`wp_` durch das tatsächliche Tabellenpräfix ersetzen, falls es abweicht.

```bash
mysqldump --single-transaction -u USER -p DBNAME \
  wp_postmeta \
  wp_termmeta \
  wp_options \
  wp_woocommerce_attribute_taxonomies \
  wp_wc_product_meta_lookup \
  | gzip > wc1c-before-clean-$(date +%F-%H%M).sql.gz
```

### Wiederherstellung aus einem Dump

```bash
gunzip < wc1c-before-clean-2026-08-27-1542.sql.gz | mysql -u USER -p DBNAME
```

Nach einer Wiederherstellung oder einem neuen vollständigen Austausch kann der Cache geleert werden:

```bash
wp cache flush
wp wc update_db
```

Die Transaktion schützt nur **innerhalb eines einzelnen Laufs** vor teilweise angewendeten Aktionen: Tritt vor `COMMIT` ein Fehler auf, wird ein `ROLLBACK` ausgeführt. Nach einem erfolgreichen `COMMIT` führt der einzige Weg zurück über die Wiederherstellung aus einer Sicherung.

---

## Fehlerbehebung

| Symptom | Mögliche Ursache | Lösung |
|---|---|---|
| «Файл wp-config.php не найден» | Das Skript liegt mehr als drei Ebenen von WordPress entfernt | In das WordPress-Wurzelverzeichnis neben `wp-config.php` verschieben |
| «Не удалось распознать DB_NAME / DB_USER / $table_prefix» | Die Parameter in `wp-config.php` sind ungewöhnlich gesetzt, etwa über `getenv()` | String-Aufrufe von `define()` verwenden oder `WpConfig::parse()` an die Umgebung anpassen |
| «На сервере не включено ни PDO MySQL, ни mysqli» | Die MySQL-Erweiterungen von PHP fehlen | `pdo_mysql` oder `mysqli` in der Hosting-Oberfläche / `php.ini` aktivieren |
| «Недействительный токен сессии» | Die Sitzung ist abgelaufen, Cookies sind blockiert, der Load-Balancer hält die Sitzungsaffinität nicht | Die Seite neu laden, Cookies erlauben, die Konfiguration der PHP-Sitzungen prüfen |
| Das Passwort wird nicht gespeichert | Das Skriptverzeichnis ist nicht beschreibbar, es wird ein temporärer Ordner verwendet | Dem Web-Benutzer Schreibzugriff auf das Skriptverzeichnis geben |
| Die Schaltfläche zeigt lange «Выполняется…» | Große `postmeta`-Tabelle, eine MySQL-Sperre oder ein PHP-FPM-Timeout | Debug einschalten, das langsame SQL finden, Indizes und `request_terminate_timeout` prüfen |
| Preise oder Bestände sind nach der Bereinigung leer | Das ist der erwartete Zustand bis zum nächsten Austausch | Den Austausch in ItGalaxy starten und abwarten |
| Nach dem Austausch sind Duplikate entstanden | Die SKUs stimmen nicht überein oder sind nicht eindeutig | Die SKUs in 1C und WooCommerce korrigieren, die falschen Duplikate entfernen, den Austausch kontrolliert wiederholen |
| Der Bestand ist nach dem Austausch veraltet | Objekt-, Seiten- oder CDN-Cache | `wp cache flush`, den WooCommerce- und den CDN-Cache zurücksetzen |

---

## FAQ

### Bleiben Produkte, Bilder und Beschreibungen wirklich erhalten?

Ja. Das Skript enthält kein `DELETE` aus `{prefix}posts`. Es löscht keine Produkte, Varianten, SKUs, Titel, Beschreibungen, Mediendateien, Kategorien oder Schlagwörter. Bereinigt werden nur die Serviceverknüpfungen des Austauschs, Preise, Bestände und die zugehörigen Cache-Werte.

### Wann sollte das Skript verwendet werden?

Der Hauptfall ist ein Upgrade der 1C-Version oder -Konfiguration, der Umzug auf eine andere Datenbank, die Wiederherstellung der Datenbank aus einer Sicherung und andere Situationen, in denen die alten Produkt-, Preis- oder Lager-GUIDs nicht mehr zu den Daten der neuen 1C passen. Das Werkzeug löscht die alten IDs; der nächste Austausch erzeugt neue.

### Warum werden Produkte nach der Bereinigung in 1C erneut zugeordnet?

Weil das ItGalaxy-Plugin Produkte während der Synchronisation über die SKU zuordnet. Sind die SKUs identisch und eindeutig, findet das Plugin das vorhandene WooCommerce-Produkt und schreibt die aktuelle ID / GUID aus 1C hinein, statt ein neues Produkt anzulegen.

### Sollte alles bereinigt werden?

Nein. Die kleinste benötigte Operation wählen. Haben sich nur die Preisarten geändert — «Очистить цены» (Preise löschen). Betrifft das Problem nur die Lager — «Очистить остатки» (Bestände löschen). «Очистить всё» (Alles löschen) passt zu einem vollständigen Upgrade oder einer Migration der 1C-Datenbank, wenn keiner der alten Bezeichner mehr vertrauenswürdig ist.

### Lässt sich die Bereinigung rückgängig machen?

Innerhalb des HTTP-Aufrufs werden Fehler durch die Transaktion zurückgerollt. Nach einem erfolgreichen `COMMIT` lassen sich die alten Daten nur aus einem Datenbank-Dump wiederherstellen.

### Werden andere 1C-Austausch-Plugins unterstützt?

Nein, das fertige Schlüsselschema ist auf ItGalaxy WooCommerce Synchronization 1C ausgerichtet. Bei anderen Lösungen muss separat geprüft werden, wo sie GUIDs, Preise und Bestände speichern, und die Konstanten in `Config` müssen angepasst werden.

### Wird WordPress Multisite unterstützt?

Das Skript verwendet ein einziges `$table_prefix` aus `wp-config.php` und arbeitet standardmäßig mit der Hauptseite. Für Unterseiten muss das Präfix angepasst werden (`wp_2_`, `wp_3_` und so weiter) — nach vorheriger Sicherung und einem Test an einer Kopie.

### Warum ist das Passwort an eine IP gebunden?

Das ist eine zusätzliche Ebene aus Komfort und Schutz: Nach einer erfolgreichen Anmeldung von einer vertrauenswürdigen Arbeitsadresse muss das Passwort nicht erneut eingegeben werden. Bei einem IP-Wechsel, bei der Arbeit von einem anderen Rechner oder nach einem ACL-Reset ist das gespeicherte Passwort erforderlich.

---

## Roadmap

- [ ] `dry-run`: eine detaillierte Zählung der anstehenden Änderungen ohne Änderung der Datenbank
- [ ] Export des Debug-Protokolls nach JSON und in eine Textdatei
- [ ] Selektive Bereinigung nach Kategorien, Produkten und ID-Bereichen
- [ ] Unterstützung von WordPress Multisite mit Auswahl der Unterseite
- [ ] Unterstützung der Schlüsselkonfigurationen anderer 1C-Austausch-Plugins
- [ ] Zweistufige Bestätigung destruktiver Operationen (TOTP)
- [ ] Automatische Prüfung der Eindeutigkeit der SKU vor dem Löschen von GUIDs

---

## Lizenz und Autor

Das Projekt wird unter der Lizenz [PolyForm Noncommercial 1.0.0](LICENSE) © 2026 Mikhail Deynekin verbreitet. Das Programm wird „wie besehen" ohne Gewährleistung bereitgestellt; der Benutzer ist allein verantwortlich für Sicherungen und für die Folgen der Operationen an den Daten.

**Mikhail Deynekin**

- Website: [Deynekin.com](https://deynekin.com)
- GitHub: [paulmann (Mikhail Deynekin)](https://github.com/paulmann/)
- Repository: [woocommerce-1c-data-cleaner](https://github.com/paulmann/woocommerce-1c-data-cleaner)
- Ein weiteres Werkzeug: [Clean_BOM_Senior](https://github.com/paulmann/Clean_BOM_Senior) — entfernt unsichtbares UTF-8-BOM und CRLF aus Quelldateien

Wenn das Werkzeug geholfen hat, einen Katalog nach einem 1C-Upgrade korrekt neu zu verknüpfen — dem Repository einen Stern geben.
