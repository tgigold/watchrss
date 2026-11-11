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

- `X-Forwarded-For` und `X-Forwarded-Proto` werden nur noch von Proxys aus
  `TRUSTED_PROXIES` übernommen. Bisher konnte jeder Client damit eine
  beliebige IP vortäuschen und die Abrufbremsen umgehen.
- Feed-, Editor- und API-Links werden nicht mehr aus dem `Host`-Header
  gebaut.

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
