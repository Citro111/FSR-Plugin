# FSR ET/IT Link Marker

Das Modul markiert problematische Links im Frontend und stellt im bestehenden FSR-Adminbereich einen manuellen Link-Bericht bereit.

## Einbindung

Im Hauptplugin:

```php
require_once FSR_ETIT_DIR . 'link-marker/link-marker.php';
```

`link-marker.php` lädt `admin.php` automatisch. Das Hauptplugin rendert die öffentliche Funktion `fsr_etit_link_marker_render_admin_page()` im vorhandenen Admin-Router unter **FSR ET/IT > Links**.

## Admin-Einstellungen

Für jede Markierung gibt es drei Sichtbarkeitszustände:

- Alle Besucher
- Nur Administratoren
- Deaktiviert

Zusätzlich können unter **Alte Website-URLs** beliebig viele alte Basis-URLs hinterlegt werden, eine pro Zeile. Erlaubt sind komplette Domains und Unterpfade, z. B. `https://fsr-etit.de/` oder `https://example.org/altes-wiki/`. Eine Domain ohne Protokoll wird als HTTPS interpretiert.

Gespeichert werden die Werte unter `fsr_etit_link_marker_settings`.

## Markierungen

- `404`: interne Links mit HTTP 404
- `LEER`: WordPress-Seiten ohne substantiellen Inhalt bzw. nur Überschriften/Leerraum
- `ALT`: Links auf eine der konfigurierten alten Basis-URLs

## Link-Bericht

Der manuelle Scan durchsucht alle veröffentlichten öffentlichen WordPress-Beitragstypen und gruppiert Fundstellen in:

- 404 / fehlende Ziele
- Links auf leere Seiten
- Links auf alte Website-URLs

Pro Fundstelle werden Quellseite, Linktext, Ziel-URL sowie Links zum Ansehen und Bearbeiten angezeigt. Gleiche Ziel-URLs werden während eines Scans nur einmal klassifiziert. Der letzte Bericht bleibt gespeichert, bis ein neuer Scan ausgeführt wird.

Der Inhalts-Scan wertet gespeichertes `post_content` aus. Links, die ausschließlich zur Laufzeit durch JavaScript, ein Theme oder dynamische Plugins entstehen, sind daher nicht zwingend enthalten.

## Entwicklungsumgebungen

Unaufgelöste interne URLs werden nicht mehr per serverseitigem Loopback-Request geprüft. Solche Self-Requests können besonders in Local nach einigen Sekunden hängen. Im Frontend prüft stattdessen der Browser unresolved interne URLs direkt. Im Admin-Bericht landen sie zunächst unter „Noch nicht serverseitig prüfbare interne Links“ und können per Button im Browser geprüft werden; bestätigte 404s werden anschließend in die 404-Gruppe verschoben.

## Sicherheitsregel für Browser-Prüfungen (1.2.1)

Der Link-Marker verändert niemals das `href` eines Links. Automatische Browser-Prüfungen
werden nur noch für query-freie Frontend-URLs derselben Origin per `HEAD` ausgeführt.
WordPress-Adminleiste, `wp-login.php`, `wp-admin`, REST-, Cron- und XML-RPC-URLs werden
nicht geprüft. Redirects werden nicht verfolgt und es gibt keinen GET-Fallback. Damit können
Aktionslinks (insbesondere der WordPress-Abmelde-Link) nicht versehentlich ausgeführt werden.
