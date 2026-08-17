# FSR ET/IT Website Tools

WordPress-Plugin für die Website des Fachschaftsrats ET/IT. Es bündelt die
DokuWiki-Anbindung, Mitgliedskarten, Sprechstunden, Kalenderdarstellung,
websiteweite Suche und optionale GitHub-Updates.

## Systemanforderungen

- WordPress 6.4 oder neuer
- PHP 8.0 oder neuer
- PHP-Erweiterung `DOM` für die vollständige DokuWiki-Aufbereitung und -Suche
- HTTPS für externe Kalender-, Wiki- und Update-Quellen

Die Plugin-Version 5.0.0 wurde auf die WordPress-API bis einschließlich 7.0
ausgerichtet. Vor dem Einsatz auf einer produktiven Website sollte das Update
immer mit einem Backup in einer Staging-Umgebung geprüft werden.

## Installation und Update

1. Website und Datenbank sichern.
2. Die ZIP-Datei unter **Plugins > Installieren > Plugin hochladen** einspielen.
3. Beim Ersetzen einer vorhandenen Version die WordPress-Rückfrage bestätigen.
4. Das Plugin aktivieren und unter **FSR ET/IT** alle externen URLs und
   Einstellungen kontrollieren.
5. DokuWiki-Seiten, Mitglieder, Sprechstunden, Kalender und Website-Suche in
   einem privaten Browserfenster sowie als Administrator testen.

Der Hauptdateiname, die vorhandenen Optionsschlüssel, Shortcode-Namen und
AJAX-Aktionsnamen bleiben absichtlich kompatibel zu Version 4. Interne
PHP-Symbole und sichtbare Bezeichnungen verwenden einheitlich das Präfix
`fsr_etit_` beziehungsweise den Namen „FSR ET/IT Website Tools“.

Eigene Theme- oder Snippet-Aufrufe, die bisher interne `fsr_*`-PHP-Funktionen
direkt verwendet haben, müssen auf das neue Präfix `fsr_etit_*` angepasst
werden. Die unten aufgeführten Shortcodes sowie bestehende Options- und
AJAX-Namen bleiben davon unberührt.

## Shortcodes

- `[fsr_members]` – Mitgliederkarten
- `[fsr_member_info]` – einzelne Mitgliedsinformation
- `[fsr_office_hours]` – öffentliche Sprechstunden
- `[fsr_office_hours_portal]` – geschützte Sprechstundenverwaltung
- `[fsr_events]` – Kalendertermine

Die verfügbaren Attribute werden zusätzlich unter **FSR ET/IT > Shortcodes**
angezeigt.

## Sicherheitsrelevante Hinweise

### Sprechstundenportal

Das Portal benötigt standardmäßig die WordPress-Berechtigung
`manage_options`. Damit können nur Administratoren Sprechstunden anlegen,
bearbeiten, löschen oder Zu- und Absagen verwalten. Falls eine andere
vertrauenswürdige Rolle zuständig sein soll, kann die Berechtigung gezielt
geändert werden:

```php
add_filter(
    'fsr_etit_office_hours_manage_capability',
    static fn(): string => 'edit_pages'
);
```

Diese Berechtigung darf nur Rollen erhalten, die sämtliche Sprechstunden und
Mitglieder verwalten dürfen. Das Plugin bildet WordPress-Benutzer nicht
automatisch auf einzelne Mitgliedsdatensätze ab.

### Externe Inhalte

- DokuWiki- und Kalender-URLs werden als HTTPS-URLs validiert und über die
  sicheren WordPress-HTTP-Funktionen abgerufen.
- DokuWiki-Assets erhalten kurzlebige signierte Proxy-URLs; erlaubte Hosts und
  Dateitypen werden geprüft.
- Remote-Antworten sind größenbegrenzt und werden zwischengespeichert.
- Externes Wiki-HTML wird auf erlaubtes WordPress-HTML reduziert.

### GitHub-Updater

Für produktive Installationen ist **GitHub-Releases** vorgesehen. Der
Branch-Modus installiert den aktuellen Entwicklungsstand des ausgewählten
Branches und sollte nur in Staging- oder Entwicklungsumgebungen verwendet
werden. Der Updater akzeptiert ausschließlich validierte GitHub-Paket-URLs.

## Kompatibilität

Die Kalenderauswertung unterstützt die üblichen Einzeltermine und einfache
`DAILY`-, `WEEKLY`-, `MONTHLY`- und `YEARLY`-Wiederholungen. Sie ist kein
vollständiger RFC-5545-Interpreter. Kalender mit komplexen BY-Regeln sollten
vor dem Produktiveinsatz anhand konkreter Termine geprüft werden.

Beim Löschen fehlender Mitgliederdatensätze werden Beiträge in den
WordPress-Papierkorb verschoben, nicht dauerhaft gelöscht.

## Änderungen in 5.0.0

- interne Funktionen, Konstanten und sichtbare Plugin-Namen vereinheitlicht
- Plugin-Metadaten und Mindestanforderungen aktualisiert
- Berechtigungs- und Nonce-Prüfungen für alle schreibenden Aktionen ergänzt
- Sprechstundenverwaltung gegen Identitätswechsel und fremde Änderungen
  abgesichert
- Remote-Abrufe gegen SSRF, übergroße Antworten und unsichere Inhalte gehärtet
- DokuWiki-Asset-Proxy signiert und auf erlaubte Ursprünge/Typen begrenzt
- Mitgliederimport validiert, fehlerhafte Suchrückgaben und Template-Markup
  korrigiert sowie konkurrierende Auto-Speicherungen serialisiert
- Kalenderparser, Zeitzonenbehandlung, Wiederholungen und Cache überarbeitet
- GitHub-Updater validiert, Fehlerbehandlung und Aktivierungsablauf korrigiert
- Debug-Logging standardmäßig deaktiviert und begrenzt

## Änderungen in 5.0.1

- veraltete `fsr_updates_log()`-Aufrufe in den Mitgliedskarten auf `fsr_etit_log()` umgestellt
- ungültigen Zugriff auf das nicht vorhandene `[fsr_members]`-Attribut `infos` entfernt
- alte Link-Ziele als frei konfigurierbare Liste von Basis-URLs umgesetzt
- manuellen, nach 404 / leeren Zielseiten / alten Links gruppierten Link-Bericht ergänzt
- interne 404-Prüfung für Local-/private Entwicklungsumgebungen kompatibler gemacht
- Link-Marker prüft nun auch Links mit `target="_blank"`

## Änderungen in 5.0.2

- Admin-Fehler bei den Mitgliedskarten durch den falschen Funktionsnamen `fsr_etit_members_render_admin_interface()` behoben
- drei weitere inkonsistente interne Funktionsaufrufe in Sprechstunden- und Mitgliedersuche korrigiert
- blockierende serverseitige Self-Requests des Link-Markers entfernt
- nicht direkt auflösbare interne Links werden im Frontend vom Browser auf 404 geprüft
- der Link-Bericht sammelt solche Ziele zunächst separat und kann sie per Browser-Prüfung nachträglich verifizieren und bestätigte 404s einsortieren

## Daten und Deinstallation

Das Deaktivieren oder Entfernen des Plugins löscht keine Mitglieder,
Sprechstunden oder Einstellungen automatisch. So gehen Website-Daten bei einem
vorübergehenden Deaktivieren nicht verloren.

Lizenz: GPL-2.0-or-later
