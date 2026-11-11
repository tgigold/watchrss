# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei
festgehalten.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt

- `config.inc.sample.php` als Vorlage für Zugangsdaten und Geheimnisse.
- `.gitignore` für lokale Konfiguration, `vendor/`, Cache, Logs und Dumps.
- `.htaccess` im Projektstamm und in `www/`, die beim ersten Upload fehlten.
- Automatisches Deployment per FTPS über GitHub Actions
  (`.github/workflows/deploy.yml`, lftp), Actions auf Commit-SHAs gepinnt.
- `composer.lock` eingecheckt.
- `ext-libxml` und `ext-xmlwriter` als Abhängigkeiten in `composer.json`.
- Klare Fehlermeldung, wenn `config.inc.php` fehlt.
- Dieses Changelog.
- Security-Header auf jeder Antwort: Content Security Policy,
  `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, über HTTPS
  zusätzlich HSTS.
- HTTPS-Redirect auf `APP_BASE_URL` in der App und auf `watchrss.de` in
  `www/.htaccess`.
- `TRUSTED_PROXIES`: Liste vertrauenswürdiger Reverse-Proxys.
- Gesamt-Zeitbudget fürs Auflösen von Kanälen (`RESOLVE_BUDGET_SECONDS`,
  Standard 25 s) und fürs Vorab-Abholen neuer Kanäle (10 s).
- Fehler-Log der Web-Anwendung in `var/log/error.log` (`ERROR_LOG_FILE`).
- README-Abschnitt „Betrieb“: Updates, Backup, Logs.
- Tests mit PHPUnit (Unit und Feature, ohne Datenbank und ohne Netzwerk)
  und statische Analyse mit PHPStan auf Level 8.
- CI-Workflow: Lint, PHPUnit und PHPStan unter PHP 8.2 und 8.4 bei jedem
  Push und Pull Request. Der Deploy läuft nur nach grüner CI.
- README-Abschnitt „Lokale Entwicklung“ mit Tests.
- Migrationssystem: nummerierte Migrationen in `migrations/`, Tabelle
  `schema_migrations`, eigene Transaktion je Migration, automatisches Backup
  nach `var/backups/` vor jedem Lauf (`BACKUP_DIR`, `BACKUP_KEEP`).
- Wartungsseite `/admin` zum Ausführen der Migrationen im Browser,
  geschützt durch `ADMIN_TOKEN` (nur im POST-Body) und Sperre nach
  Fehlversuchen.
- Integrationstests für Migrationen und Backup gegen MySQL 8.4 und
  MariaDB 11.4 in der CI.
- **Geführtes Setup** unter `/setup` für Webhosting ohne Kommandozeile:
  Setup-Code aus `var/setup-code.txt` (nur per FTP lesbar), Umgebungsprüfung,
  Datenbank-Test, Tabellen und Migrationen, erzeugte Geheimnisse,
  `config.inc.php`. Danach sperrt sich das Setup selbst.
- Jede Route gibt ausdrücklich an, wer sie aufrufen darf (`Http\Access`),
  jede POST-Route ihren CSRF-Schutz (`Http\Csrf`).
- Circuit Breaker: Ein Dienst wird nach mehreren Komplettausfällen in Folge
  eine Zeit lang übersprungen (`BREAKER_THRESHOLD`,
  `BREAKER_COOLDOWN_SECONDS`).
- `App::VERSION`; Asset-URLs und der Matomo-User-Agent tragen die Version.
- Browser-Tests mit Playwright in der CI (`package.json`,
  `package-lock.json`).
- Editor: Die Bereiche 1–3 lassen sich über einen Pfeil oben rechts
  einklappen; der Zustand bleibt per Cookie `watchrss_collapsed` erhalten
  und wird schon serverseitig gerendert (kein Aufblitzen).
- README-Abschnitt „Fair Use gegenüber YouTube“, inklusive der bewussten
  Ausnahme beim User-Agent.
- `.htaccess` mit Zugriffssperre in `bin/`, `src/`, `templates/`, `var/` und
  (beim Deploy) `vendor/`.

### Geändert

- Matomo ist in `config.app.inc.php` standardmäßig aus und zeigt auf eine
  Beispiel-URL; eigene Werte gehören in `config.inc.php`.
- Lizenzangabe in `composer.json` an die LICENSE-Datei angeglichen
  (`Unlicense`).
- README: Installation mit `config.inc.sample.php`, neuer Abschnitt
  „Deployment“.
- **`APP_BASE_URL` ist im Betrieb Pflicht.** Ohne sie antwortet die App außer
  bei lokalen Hosts mit einem Fehler, statt Links aus dem Host-Header zu
  bauen.

### Sicherheit

- **Bearbeiten-Tokens werden nur noch als SHA-256-Hash gespeichert**
  (`feeds.edit_token_hash`, Migration `0002`). Der Klartext wird genau einmal
  beim Anlegen ausgeliefert. Bestehende Bearbeiten-Links funktionieren
  weiter; ein verlorener Link lässt sich nicht mehr aus der Datenbank
  wiederherstellen. **Nach dem Update Migration 0002 ausführen** (`/admin`
  oder `php bin/migrate.php`).

- API-Eingaben werden explizit auf Typ, gültiges UTF-8 und Grenzen geprüft
  (`Support\Input`). Ein Array statt Text wird mit 422 abgelehnt, statt als
  „Array“ gespeichert zu werden.
- 422-Antworten nennen unter `fields` Feldname, erwarteten und erhaltenen
  Typ – nie den Inhalt.
- Ungültige Werte für `ttl_minutes`, `max_items` und Einträge der Kanalliste
  führen zu 422, statt stillschweigend ersetzt oder übersprungen zu werden.
- Ein Array als `key` im Web-Trigger erzeugt keine PHP-Warnung mehr.
- Ausgehende Anfragen (YouTube, Matomo) nur noch über HTTPS, auch bei
  Weiterleitungen; höchstens 3 Weiterleitungen statt 5. `http://`-Links zu
  YouTube werden auf `https://` hochgestuft, eine `http://`-`MATOMO_URL`
  schaltet das Tracking ab.
- `X-Forwarded-For` und `X-Forwarded-Proto` werden nur noch von Proxys aus
  `TRUSTED_PROXIES` übernommen. Bisher konnte jeder Client damit eine
  beliebige IP vortäuschen und die Abrufbremsen umgehen.
- Feed-, Editor- und API-Links werden nicht mehr aus dem `Host`-Header
  gebaut.
- POST-Requests mit fremdem `Origin` werden mit 403 abgelehnt; die JSON-API
  nimmt nur noch `Content-Type: application/json` an (sonst 415).

- Composer-Plattform auf PHP 8.2 festgelegt.
- `bin/migrate.php` nutzt das Migrationssystem; die bisherigen Schritte
  (Aufteilung von `feeds.token`) sind Migration `0001` und prüfen weiter
  selbst, ob sie nötig sind. `--finish` entfällt.
- `bin/install.php` führt nach `schema.sql` die Migrationen aus und nutzt
  denselben Installer wie das Web-Setup.
- **Web-Root heißt jetzt `public/` statt `www/`.** Document-Root beim
  Hoster umstellen und danach den alten Ordner `www/` löschen.
- Kaputte Einträge im Drossel-Cache gelten als „nichts bekannt“, statt zu
  Typfehlern zu führen.
- Mehrfach angegebenes `--channel` in `bin/fetch.php` wird ignoriert statt
  als „Array“ übernommen.

### Entfernt

- `config.inc.php` mit Zugangsdaten aus der Versionsverwaltung.
- Laufzeit-Cache (`var/cache/`) und generierter Autoloader (`vendor/`) aus
  der Versionsverwaltung.

## [1.0.0] - 2026-11-10

### Hinzugefügt

- Kombinierte RSS-Feeds aus beliebig vielen YouTube-Kanälen, ohne Account:
  geheimer Editor-Link und separater öffentlicher Feed-Link.
- Kanal-Eingabe als Freitext (Handles, Kanal-, Video- und Feed-URLs,
  Channel-IDs) mit Auflösung zur Channel-ID.
- Filter je Kanal: Longform und/oder Shorts.
- Einstellbare TTL und Anzahl der Einträge je Feed, optional Kanalname als
  Titel-Präfix.
- Vorschau der aktuellen Einträge im Editor.
- Neue Feed-Adresse erzeugen (alte wird ungültig).
- Abruf der Kanal-Feeds per CLI (`bin/fetch.php`) oder Web-Trigger
  (`/cron/<token>`) mit Sperre, Zeitbudget und Aufräumen.
- Auslieferung mit ETag, Cache-Control, `ttl` und 429-Bremse gegen zu
  häufiges Abrufen.
- Serverseitiges, anonymisiertes Matomo-Tracking.
- Installation (`bin/install.php`) und zweistufige Migrationen
  (`bin/migrate.php`).

[Unreleased]: https://github.com/tgigold/watchrss/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/tgigold/watchrss/releases/tag/v1.0.0
