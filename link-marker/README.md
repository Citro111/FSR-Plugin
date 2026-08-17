# FSR ET/IT Link Marker

Das Modul markiert interne Links und stellt eine eigene Admin-Unterseite bereit.

## Einbindung

Im Hauptplugin:

```php
require_once FSR_ETIT_DIR . 'link-marker/link-marker.php';
```

`link-marker.php` lädt `admin.php` automatisch und registriert standardmäßig eine Unterseite unter dem WordPress-Admin-Menü `fsr-etit`.

Wenn dein `global/admin.php` bereits einen eigenen Router / Tabsystem besitzt, kannst du stattdessen die öffentliche Render-Funktion direkt verwenden:

```php
fsr_etit_link_marker_render_admin_page();
```

Oder nur die Menüregistrierung selbst aufrufen:

```php
fsr_etit_link_marker_register_admin_menu();
```

Dann solltest du die automatische `admin_menu`-Registrierung in `link-marker.php` entfernen, damit die Seite nicht doppelt registriert wird.

## Admin-Einstellungen

Die Admin-Seite bietet pro Markierung drei Zustände:

- Alle Besucher
- Nur Administratoren
- Deaktiviert

Gespeichert werden sie unter `fsr_etit_link_marker_settings`.

## Aktuelle Markierungen

- `404`: interne Links mit HTTP 404
- `LEER`: WordPress-Seiten ohne substantiellen Inhalt bzw. nur Überschriften/Leerraum
- `ALT`: Links auf `fsr-etit.de`, standardmäßig nur für Administratoren

## Späterer Bericht

Für den gewünschten Bericht „Auf welchen Seiten steht welcher problematische Link?“ kann `fsr_etit_link_marker_classify_url()` direkt weiterverwendet werden. Ein Scan sollte dabei im Admin-Kontext und nicht beim normalen Frontend-Aufruf ausgeführt werden.
