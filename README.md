# watchrss

Beliebig viele YouTube-Kanäle als **ein** RSS-Feed – mit Shorts-Filter pro Kanal,
ohne Account und ohne Google-API-Key.

```
Eingabe (URL / @handle / Channel-ID)
        │
        ▼
 ChannelResolver ──► Channel-ID ──► offizieller YouTube-Atom-Feed
        │                                    │
        ▼                                    ▼
    channels ◄──────── Cronjob (bin/fetch.php) ────► videos
        │
        ▼
   feed_channels (Filter: Longform / Shorts)
        │
        ▼
   /f/<token>.xml  ──► RSS 2.0 mit ETag, Cache-Control, ttl, 429-Bremse
```

---

## Installation

```bash
cp config.inc.sample.php config.inc.php   # Zugangsdaten eintragen
composer install          # nur der Autoloader, keine Fremdpakete
php bin/install.php       # legt die Tabellen an, führt Migrationen aus
```

`bin/install.php` ist beliebig oft ausführbar (`CREATE TABLE IF NOT EXISTS`).
Es spielt `schema.sql` ein und führt danach alle Migrationen aus – auf einer
bestehenden Datenbank mit vorherigem Backup.

### Migrationen

Schema-Änderungen laufen ausschließlich über nummerierte Migrationen in
`migrations/` (`0001_name.php`, `0002_name.php`, …). `schema.sql` ist der
eingefrorene Ausgangsstand und wird nicht mehr geändert. Welche Migrationen
gelaufen sind, steht in der Tabelle `schema_migrations`.

```bash
php bin/migrate.php            # offene Migrationen ausführen (mit Backup)
php bin/migrate.php --status   # nur anzeigen
```

Ohne Shell geht dasselbe im Browser unter **`/admin`**: `ADMIN_TOKEN` aus
der `config.inc.php` eingeben, Status ansehen, „Migrationen ausführen“.

* **Vor jedem Lauf** mit offenen Migrationen schreibt watchrss automatisch
  ein Backup nach `var/backups/backup-<Datum>.sql.gz` (die letzten
  `BACKUP_KEEP` bleiben liegen). Schlägt das Backup fehl, läuft nichts.
* **Jede Migration läuft in einer eigenen Transaktion.** Ausnahme, die man
  kennen muss: MySQL beendet Transaktionen bei `ALTER`/`CREATE`/`DROP TABLE`
  implizit – solche Schritte lassen sich nicht zurückrollen. Dann hilft das
  Backup (phpMyAdmin → Importieren).
* Bricht eine Migration ab, bleiben die folgenden unberührt; die Meldung
  nennt die Migration und das Backup.
* **Ausgerollte Migrationen werden nie geändert.** Jede Korrektur ist eine
  neue Migration mit der nächsten Nummer.

Neue Migration anlegen: Datei `migrations/NNNN_beschreibung.php`, die eine
Instanz von `WatchRss\Migration\Migration` zurückgibt (Vorlage:
`0001_split_feed_tokens.php`).

### Webserver

Der Document-Root muss auf **`www/`** zeigen. Alles andere (Konfiguration,
Klassen, Templates, Cache) liegt eine Ebene darüber und ist damit über die
Domain nicht erreichbar.

* **Apache**: `www/.htaccess` ist dabei, es braucht `mod_rewrite`.
  Im Projektstamm liegt zusätzlich eine `.htaccess`, die einzelne Dateinamen
  sperrt (`config.inc.php`, `*.sql`, …). Sie ist bewusst **nicht** als
  pauschales `Require all denied` formuliert: viele Hoster werten
  `.htaccess`-Dateien auch oberhalb des Document-Roots aus, ein Pauschalverbot
  würde sich nach `www/` vererben und die komplette Seite sperren.
* **nginx**:

  ```nginx
  root /pfad/zu/watchrss/www;
  location / { try_files $uri $uri/ /index.php$is_args$args; }
  ```

Lokal zum Ausprobieren:

```bash
php -S 127.0.0.1:8099 -t www www/index.php
```

### Cronjob

Ohne Cron bleiben die Feeds auf dem Stand des Moments, in dem ein Kanal
hinzugefügt wurde. Empfohlen: alle 10 Minuten.

```cron
*/10 * * * * /usr/bin/php /pfad/zu/watchrss/bin/fetch.php --quiet
0    4 * * * /usr/bin/php /pfad/zu/watchrss/bin/fetch.php --quiet --prune
```

Der Lauf holt nur Kanäle, die

* in mindestens einem Nutzer-Feed vorkommen und
* länger als `CHANNEL_REFRESH_SECONDS` (Standard: 30 Minuten) nicht dran waren.

Bei Fehlern greift ein exponentielles Backoff (`failure_count`), gedeckelt bei
24 Stunden. Optionen: `--force`, `--limit=N`, `--channel=UC…`, `--prune`,
`--max-seconds=N`, `--quiet`.

### Web-Trigger statt Konsolen-Cron

Wenn das Hosting-Panel nur URLs als Cron-Ziel akzeptiert, gibt es denselben
Lauf als HTTP-Endpunkt. Er ruft exakt dieselbe Klasse auf
(`WatchRss\Service\FetchRunner`), es gibt also keine zweite Implementierung,
die auseinanderlaufen könnte.

1. Geheimnis erzeugen und in `config.inc.php` eintragen:

   ```bash
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```

   ```php
   define('CRON_TOKEN', 'dein-erzeugtes-geheimnis');
   ```

2. Im Panel als Ziel eintragen, alle 10 Minuten:

   ```
   https://watchrss.de/cron/<CRON_TOKEN>
   ```

   Einmal täglich zusätzlich mit Aufräumen:

   ```
   https://watchrss.de/cron/<CRON_TOKEN>?prune=1
   ```

Parameter: `?limit=25`, `?force=1`, `?prune=1`, `?format=json`. Auch als
`?key=<CRON_TOKEN>` aufrufbar, falls Pfade im Panel stören.

Verhalten:

| Situation | Antwort |
|---|---|
| Lauf erfolgreich | `200` + Textprotokoll (bzw. JSON) |
| einzelne Kanäle fehlerhaft | `500` – damit Cron-Mail/Monitoring anschlägt |
| es läuft bereits ein Abruf | `409` – Aufruf wird übersprungen, kein Fehler |
| Token falsch | `404` – der Endpunkt verrät sich nicht |
| `CRON_TOKEN` leer | `503` – Trigger ist abgeschaltet |

Der Lauf ist durch eine Dateisperre (`flock`) gegen Überschneidungen
abgesichert und bricht nach `CRON_MAX_SECONDS` sauber ab, bevor der Webserver
die Verbindung kappt – der Rest kommt beim nächsten Aufruf dran. Ein
`limit`-Wert von 20–40 passt bequem in ein solches Zeitfenster.

---

## Lokale Entwicklung

```bash
composer install                              # inkl. PHPUnit und PHPStan
cp config.inc.sample.php config.inc.php       # lokale DB eintragen
php -S 127.0.0.1:8099 -t www www/index.php    # http://127.0.0.1:8099
```

Lokal darf `APP_BASE_URL` leer bleiben (`localhost`, `127.0.0.1`, `*.test`,
`*.local`); dann gibt es auch keinen HTTPS-Redirect.

### Tests und statische Analyse

```bash
vendor/bin/phpunit     # Unit- und Feature-Tests
vendor/bin/phpstan     # statische Analyse, Level 8
```

Die Tests laden nie die echte `config.inc.php`, sprechen keine Datenbank und
keinen externen Dienst an – ihre Konstanten stehen in `tests/constants.php`.
Feature-Tests schicken ganze Requests durch `App::handle()`, beschränkt auf
Pfade ohne Datenbank (Redirects, Header, Validierung, Web-Trigger).

Die CI (`.github/workflows/ci.yml`) führt bei jedem Push und Pull Request
Lint, PHPUnit und PHPStan unter PHP 8.2 und 8.4 aus. `composer.json` legt die
Plattform auf PHP 8.2 fest, damit `composer.lock` zur Mindestversion passt.

---

## Deployment

Jeder Push auf `main` wird – **nur wenn die CI grün ist** – per GitHub Actions (`.github/workflows/deploy.yml`)
über FTPS auf den Server geladen; manuell auslösbar unter *Actions → Deploy →
Run workflow*. Der Workflow erzeugt vorher den Autoloader (`composer install`).

Einmalig einrichten: *Settings → Environments → `production`* anlegen
(Deployment branches: nur `main`) und dort die Secrets `FTP_SERVER`,
`FTP_USERNAME`, `FTP_PASSWORD` und `FTP_SERVER_DIR` (Projektstamm mit
abschließendem `/`) hinterlegen.

`config.inc.php` und `var/cache/` werden nie hochgeladen oder gelöscht; die
Konfiguration auf dem Server wird einmalig von Hand angelegt.

---

## Betrieb

### Updates

Ein Push auf `main` startet den Deploy (siehe oben); im Environment
`production` muss er freigegeben werden. Geänderte Dateien werden
überschrieben, nichts wird gelöscht. Was sich geändert hat, steht in
`CHANGELOG.md`. Bringt ein Update eine neue Migration mit, steht das dort
ausdrücklich – nach dem Deploy dann `/admin` aufrufen (oder
`php bin/migrate.php`) und die offenen Migrationen ausführen.

### Backup

Zu sichern sind zwei Dinge – alles andere kommt aus dem Repository:

* **Datenbank** – Dump über das Hoster-Panel (bei all-inkl: KAS →
  Datenbanken → phpMyAdmin → Exportieren) oder per
  `mysqldump --single-transaction <db> > watchrss.sql`. Vor jeder Migration
  legt watchrss zusätzlich selbst eines in `var/backups/` an.
* **`config.inc.php`** – Zugangsdaten und Geheimnisse; liegt nur auf dem
  Server.

`var/cache/` muss nicht gesichert werden, der Inhalt baut sich selbst neu auf.

### Logs

Fehler der Web-Anwendung landen in `var/log/error.log` (per FTP lesbar, über
das Web gesperrt). Antworten enthalten in Produktion nie Fehlerdetails.
`bin/fetch.php` schreibt nach STDERR, sodass Cron-Mails Fehler zeigen.

---

## Konfiguration

| Datei | Inhalt |
|---|---|
| `config.inc.php` | MySQL-Zugangsdaten und Geheimnisse – nicht versioniert, Vorlage: `config.inc.sample.php` |
| `config.app.inc.php` | Anwendungs-Schalter, alle mit `defined()`-Guard |

Die wichtigsten Werte:

| Konstante | Standard | Bedeutung |
|---|---|---|
| `APP_BASE_URL` | `''` | Öffentliche Basis-URL, z. B. `https://watchrss.de`. **Pflicht im Betrieb** (in `config.inc.php`): Links und HTTPS-Redirect nutzen nur diese Adresse, nie den Host-Header. Leer nur lokal erlaubt (`localhost`, `*.test`, …) |
| `TRUSTED_PROXIES` | `''` | Reverse-Proxys (IPs/CIDR, kommagetrennt), deren `X-Forwarded-For`/`-Proto` übernommen wird. Leer = keine |
| `APP_DEBUG` | `false` | Fehler im Klartext ausgeben (nur lokal) |
| `CHANNEL_REFRESH_SECONDS` | `1800` | Mindestabstand zwischen zwei Abrufen desselben Kanals |
| `FETCH_BATCH_SIZE` | `40` | Kanäle pro Cron-Lauf |
| `VIDEO_RETENTION_PER_CHANNEL` | `200` | So viele Videos werden je Kanal behalten |
| `FEED_DEFAULT_TTL_MINUTES` | `120` | Voreingestelltes Intervall neuer Feeds |
| `FEED_THROTTLE_RATIO` | `0.5` | Zeitfenster der Abrufbremse als Anteil der TTL (`0` = aus) |
| `FEED_THROTTLE_BURST` | `3` | So viele volle Antworten sind je Fenster frei |
| `FEED_ABUSE_LIMIT_PER_HOUR` | `60` | Harte Obergrenze pro Token und IP |
| `FEED_UNKNOWN_LIMIT_PER_HOUR` | `20` | Fehlversuche mit unbekannten Feed-Adressen pro IP |
| `CRON_TOKEN` | `''` | Geheimnis für den Web-Trigger (gehört in `config.inc.php`). Leer = abgeschaltet |
| `CRON_MAX_SECONDS` | `45` | Zeitbudget eines Web-Laufs |
| `MATOMO_ENABLED` | `false` | Schalter für das Tracking |
| `MATOMO_URL` | Beispiel-URL | Endpunkt (nur `https://`); ohne Dateinamen wird `/matomo.php` ergänzt |
| `MATOMO_SITE_ID` | – | Site-ID in Matomo |
| `TRACKING_HONOR_DNT` | `true` | Do Not Track / Global Privacy Control respektieren |
| `TRACKING_SALT` | `''` | Salz für die Besucherkennung (gehört in `config.inc.php`) |
| `RESOLVE_BUDGET_SECONDS` | `25` | Gesamt-Zeitbudget, um eine Eingabe im Editor aufzulösen |
| `ADMIN_TOKEN` | `''` | Geheimnis für `/admin` (gehört in `config.inc.php`). Leer = Seite abgeschaltet |
| `ADMIN_MAX_FAILURES` / `ADMIN_LOCK_MINUTES` | `5` / `15` | Sperre von `/admin` nach Fehlversuchen je IP |
| `BACKUP_DIR` | `var/backups` | Ziel der automatischen Backups vor Migrationen |
| `BACKUP_KEEP` | `10` | So viele Backups bleiben liegen |
| `ERROR_LOG_FILE` | `var/log/error.log` | Fehler-Log der Web-Anwendung (CLI schreibt nach STDERR). Leer = Server-Standard |

---

## Wie oft ein Reader abrufen darf

Erzwingen lässt sich ein Intervall nicht vollständig – ein Feed ist eine
öffentliche URL. watchrss staffelt deshalb drei Mittel, vom höflichen Hinweis
bis zur harten Absage:

1. **Bitten** – im XML stehen `<ttl>` und `<sy:updatePeriod>`/`<sy:updateFrequency>`.
   Ältere Reader (u. a. Tiny Tiny RSS, FreshRSS) werten das aus.
2. **HTTP-Standard** – `Cache-Control: public, max-age=<ttl>`, `Expires`, `ETag`
   und `Last-Modified`. Wer korrekt bedingt anfragt, bekommt ein
   `304 Not Modified` ohne Body: ein paar hundert Byte statt ein paar hundert
   Kilobyte. Das ist der Weg, den gute Reader ohnehin gehen.
3. **Durchsetzen** – wer denselben Inhalt immer wieder komplett herunterlädt,
   bekommt `429 Too Many Requests` mit `Retry-After`. Zwei Regeln halten das fair:

   * Gebremst wird **nur bei unverändertem Inhalt**. Sobald ein neues Video
     drin ist (= neuer ETag), ist der nächste Abruf immer frei – niemand
     verpasst dadurch eine Aktualisierung.
   * Pro Zeitfenster (`TTL × FEED_THROTTLE_RATIO`) sind `FEED_THROTTLE_BURST`
     volle Antworten frei. Man kann seinen Feed also im Browser anschauen,
     ohne sich auszusperren; eine Polling-Schleife im Sekundentakt ist das
     Kontingent dagegen sofort los.

   Bedingte Anfragen mit `304` zählen **nicht** mit – genau dieses Verhalten
   soll ja belohnt werden. Gezählt wird pro Token und IP-Hash, nicht global.

Der ETag ist ein Hash aus Feed-Einstellungen und dem Fingerabdruck der
Videodaten (Anzahl + jüngste Zeitstempel). Solange nichts Neues eingesammelt
wurde, bleibt er stabil – auch über mehrere Abrufe und Server-Neustarts hinweg.
Das fertige XML liegt unter diesem Schlüssel im Datei-Cache (`var/cache`),
ein Abruf kostet dann keine DB-Abfrage über die Feed-Metadaten hinaus.

---

## Shorts vs. Longform

YouTube macht das Format nur an einer Stelle sichtbar: am `alternate`-Link des
Eintrags im Kanalfeed.

```xml
<link rel="alternate" href="https://www.youtube.com/shorts/uB3VfHm6tNA"/>  <!-- Short    -->
<link rel="alternate" href="https://www.youtube.com/watch?v=ld124vHpJ4Y"/> <!-- Longform -->
```

Das landet beim Import als `videos.is_short` in der Datenbank; gefiltert wird
erst beim Zusammenstellen des Feeds. Ändert man den Schalter im Editor, wirkt
das also rückwirkend auf alle gespeicherten Videos – ohne neuen Abruf.

Ein HTTP-Probe auf `youtube.com/shorts/<id>` wäre die naheliegende Alternative,
funktioniert aber nicht: YouTube antwortet für alle IDs mit `302` auf die
Consent-Seite.

---

## Channel-ID finden

Der ursprüngliche Ansatz (`file_get_contents` + `<meta itemprop="channelId">`)
funktioniert so nicht mehr. Geändert wurde:

| Problem | Lösung in `src/Service/ChannelResolver.php` |
|---|---|
| `itemprop="channelId"` existiert nicht mehr | heute heißt es `itemprop="identifier"`; zusätzlich `rel="canonical"`, `"externalId"`, `"channelId"`, `og:url` – in dieser Reihenfolge |
| EU-Besucher landen auf `consent.youtube.com` | Cookie `SOCS=CAI; CONSENT=YES+cb` wird mitgeschickt |
| `file_get_contents` ohne Timeout, ohne gzip | cURL mit Connect-/Gesamt-Timeout, `Accept-Encoding`, kontrollierten Redirects und Zugriff auf die Response-Header |
| jede URL abrufbar → offener Proxy (SSRF) | Host-Whitelist auf `youtube.com` / `youtu.be` |
| `@handle` erzeugt jedes Mal einen 1,5-MB-Download | bekannte Handles kommen aus der eigenen DB, ganz ohne HTTP-Request |
| Fehler waren nicht unterscheidbar (`null`) | `ResolveException` mit einer Meldung, die direkt im UI stehen kann |
| Treffer wurde nicht geprüft | die gefundene ID wird gegen den echten Feed verifiziert, dabei kommt der Kanalname mit |

Akzeptierte Eingaben: `UC…`-ID, `@handle`, `youtube.com/bmw`, `/c/…`, `/user/…`,
`/channel/UC…`, ein Link auf ein beliebiges Video des Kanals, oder die Feed-URL
selbst.

---

## Statistik

Serverseitig über die Matomo HTTP Tracking API – **kein JavaScript**. Zwei
Gründe: Das Frontend lädt keinen fremden Code, und vor allem lassen sich so
auch RSS-Abrufe zählen. Ein Feed-Reader führt kein JavaScript aus, ein
JS-Tracker würde also genau den interessantesten Teil verpassen.

Gemeldet werden ausschließlich drei Ecken, jeweils **ohne Token**:

| Aufruf | gemeldete Adresse | Aktion in Matomo |
|---|---|---|
| Startseite | `/` | `Startseite` |
| `/e/<edit_token>` | `/e/` | `Editor` |
| `/f/<feed_token>` | `/f/` | `RSS-Feed / Ausgeliefert`, `… / Unverändert (304)`, `… / Gebremst (429)`, `… / Unbekannt (404)` |

`/api/`, `/cron/` und statische Dateien tauchen nicht auf. Der Status steckt
beim Feed bewusst im Aktionsnamen: Daran sieht man, wie brav die Reader sich
an den Cache halten – `304` ist die gute Nachricht, `429` heißt, jemand pollt
zu schnell.

Anonymisierung (alles in `Service\Tracking\TrackingEvent`):

* **Keine Tokens.** Aus `/e/<token>` wird `/e/`, aus `/f/<token>` wird `/f/`.
* **Keine internen Referrer.** Klickt man im Editor auf „Neuen Feed anlegen",
  stünde der Edit-Token sonst im `Referer` der Folgeseite und damit in der
  Statistik. Nur externe Referrer werden weitergereicht.
* **Keine IP.** Übermittelt wird eine Besucherkennung aus
  `sha256(TRACKING_SALT | IP | User-Agent | Datum)`. Sie trennt Besucher
  sauber, ist ohne das Salz nicht zurückrechenbar und wechselt jede Nacht.
  Weil kein `cip` gesetzt wird, ist die Geo-Zuordnung in Matomo die des
  Servers – für „wie oft wird was abgerufen" spielt das keine Rolle.
* **Do Not Track** und **Global Privacy Control** werden respektiert.
* **Entwicklungsumgebungen melden nichts.** Aufrufe über `localhost`,
  `127.0.0.1`, `*.test`, `*.local` oder den eingebauten PHP-Entwicklungsserver
  werden übersprungen – sonst landen Testläufe in der Statistik der echten
  Seite, zumal Live und Lokal sich dieselbe Konfiguration teilen.

Der Aufruf an Matomo passiert erst **nach** dem Senden der Antwort: Unter
php-fpm schließt `fastcgi_finish_request()` vorher die Verbindung, der
Besucher wartet also nie auf die Statistik. Fällt Matomo aus, landet eine
Zeile im Error-Log und sonst passiert nichts.

---

## Aufbau

```
config.inc.php          MySQL-Zugangsdaten          ← außerhalb des Webroots, nicht im Git
config.inc.sample.php   Vorlage dafür
config.app.inc.php      Anwendungs-Konfiguration
bootstrap.php           lädt Config + Autoloader, liefert den Container
schema.sql              Datenbankschema (Ausgangsstand, wird nicht mehr geändert)
migrations/             nummerierte Migrationen (0001_…php)
bin/install.php         Einrichtung
bin/migrate.php         Migrationen ausführen (CLI; im Browser: /admin)
bin/fetch.php           Cronjob
src/
  App.php               Service-Container + Routing
  Support/              Database, HttpClient, FileCache, Token, Lock
  Model/                Channel, Video, Feed, FeedChannel
  Repository/           SQL – sonst nirgends
  Service/              ChannelResolver, YouTubeFeedFetcher, FeedBuilder,
                        FeedThrottle, FeedService, InputParser,
                        FetchRunner (der Abruflauf, geteilt von CLI und Web)
  Service/Tracking/     TrackerInterface, MatomoTracker, NullTracker,
                        TrackingEvent (entscheidet, was anonymisiert wird)
  Http/                 Request, Response, Router, Controller/
  View/Renderer.php     Template-Engine (schlichte PHP-Templates)
templates/              Darstellung – enthält keine Logik
www/                    Webroot: index.php, .htaccess, assets/
var/cache/              Datei-Cache (muss beschreibbar sein)
```

Die Schichten sind bewusst getrennt: Controller kennen nur Services,
Services nur Repositories, Repositories nur SQL. Templates bekommen fertige
Werte und rufen nichts ab.

---

## HTTP-Endpunkte

| Methode | Pfad | Zweck |
|---|---|---|
| `GET` | `/` | Feed zusammenstellen |
| `GET` | `/e/{edit_token}` | Editor eines bestehenden Feeds (geheim) |
| `GET` | `/f/{feed_token}` | der generierte RSS-Feed (öffentlich, `.xml` optional) |
| `POST` | `/api/resolve` | `{"input": "…"}` → aufgelöste Kanäle + Fehlerliste |
| `POST` | `/api/feeds` | Feed anlegen |
| `POST` | `/api/feeds/{edit_token}` | Feed ändern |
| `GET` | `/api/feeds/{edit_token}/preview` | aktuelle Einträge als JSON |
| `POST` | `/api/feeds/{edit_token}/rotate` | neue öffentliche Adresse erzeugen |
| `GET` | `/cron/{CRON_TOKEN}` | Abruflauf anstoßen (Ersatz für den Konsolen-Cron) |
| `GET` | `/admin` | Wartung: Formular für den `ADMIN_TOKEN` |
| `POST` | `/admin` | Migrationsstatus (`action=status`) oder Migrationen ausführen (`action=migrate`); Token nur im Body |

---

## Sicherheit

### Zwei Tokens, nicht einer

Ein Feed hat zwei unabhängig gewürfelte Tokens. Der Grund: Die RSS-Adresse
wandert naturgemäß herum – in Reader-Dienste, OPML-Exporte, Screenshots.
Wäre sie identisch mit dem Bearbeiten-Link, käme jeder, der den Feed
abonniert, durch einen einzigen Zeichentausch (`/f/` → `/e/`) an die
Einstellungen.

| | Länge | Zeichenvorrat | steht in | Risiko bei Kenntnis |
|---|---|---|---|---|
| `edit_token` | 32 | base62 (~190 Bit) | nur im Bearbeiten-Link; in der DB nur als SHA-256 | voller Zugriff |
| `feed_token` | 10 | 56 Zeichen ohne `0/O`, `1/l/I` (3 · 10¹⁷) | in der RSS-Adresse | man sieht die Kanalliste |

**Der Bearbeiten-Token wird nie gespeichert**, nur sein SHA-256-Hash
(`feeds.edit_token_hash`). Der Server zeigt ihn genau einmal – in der Antwort
auf das Anlegen – und kennt ihn danach nur, wenn er in einer Anfrage-URL
ankommt. Ein Datenbank-Leak verrät also keine Bearbeiten-Links. Die Kehrseite:
**Ein verlorener Bearbeiten-Link lässt sich nicht wiederherstellen**, auch
nicht vom Betreiber.

Aus dem einen folgt nicht der andere. Der öffentliche Token darf deshalb kurz
sein – er schützt nur sich selbst. Der Zeichenvorrat lässt die Paare weg, die
man beim Abtippen oder Vorlesen verwechselt.

Zusätzlich: Wer reihenweise Feed-Adressen durchprobiert, bekommt nach
`FEED_UNKNOWN_LIMIT_PER_HOUR` Fehlversuchen `429`. Rechnerisch nötig ist das
bei 3 · 10¹⁷ Möglichkeiten nicht, es gibt nur keinen Grund, beliebig viele
Fehlversuche zu erlauben.

Über `POST /api/feeds/{edit_token}/rotate` (im Editor: „Neue Adresse
erzeugen") lässt sich die öffentliche Adresse jederzeit austauschen, ohne den
eigenen Zugang zu verlieren. Die alte Adresse ist danach tot – das ist der
zweite Grund für die Trennung.

`robots.txt` sperrt `/f/`, `/e/`, `/api/` und `/cron`, ausgelieferte Feeds
tragen zusätzlich `X-Robots-Tag: noindex`.

Vorbereitet, aber noch nicht aktiv: die Tabelle `users` und `feeds.user_id`.
Ein späteres Login braucht damit keinen Schema-Umbau – nur eine `Auth`-Klasse,
zwei Routen und eine Prüfung in `PageController::edit()`.

### HTTP-Header und HTTPS

Jede Antwort trägt eine strikte Content Security Policy (nur eigene Skripte
und Styles, Bilder zusätzlich von den YouTube-CDNs, `object-src 'none'`,
`frame-ancestors 'none'`), `X-Frame-Options: DENY`,
`X-Content-Type-Options: nosniff` und
`Referrer-Policy: strict-origin-when-cross-origin` – fremde Server sehen
also nie den Pfad mit dem Editor-Token. Über HTTPS kommt HSTS dazu.

Ist `APP_BASE_URL` eine `https://`-Adresse, leitet die App unverschlüsselte
Aufrufe dorthin um (GET: 301, sonst 308). `www/.htaccess` macht dasselbe auf
Webserver-Ebene, mit fest eingetragener Domain `watchrss.de` – wer watchrss
selbst betreibt, passt sie dort an.

`X-Forwarded-For` und `X-Forwarded-Proto` zählen nur, wenn der Request von
einer Adresse aus `TRUSTED_PROXIES` kommt. Sonst könnte jeder Client sich per
Header eine andere IP geben und die Abrufbremsen umgehen.

### Verzeichnisschutz

Neben `www/` liegt alles andere außerhalb des Document-Roots. Als zweite
Sicherung tragen `bin/`, `src/`, `templates/`, `var/` und `vendor/` (beim
Deploy erzeugt) je eine `.htaccess` mit `Require all denied` – das greift auch,
wenn das Document-Root versehentlich auf den Projektstamm zeigt. Die
`.htaccess` im Projektstamm sperrt zusätzlich einzelne Dateien.

### Weitere Maßnahmen

PDO mit echten Prepared Statements, `htmlspecialchars` in
allen Templates (`$this->e()`), XML-Erzeugung über `XMLWriter` statt
String-Konkatenation, `LIBXML_NONET` beim Parsen (kein XXE), Host-Whitelist
beim Abrufen fremder URLs und IP-Adressen nur gehasht im Cache.

### Fair Use gegenüber YouTube

Alle Abrufe laufen über den Server, werden zwischengespeichert
(`CHANNEL_REFRESH_SECONDS`, Conditional GET mit ETag/Last-Modified) und
gedrosselt (`FETCH_BATCH_SIZE`, `FETCH_SLEEP_MICROSECONDS`).

**Bewusste Ausnahme beim User-Agent:** Statt eines eigenen Kennzeichens mit
Version und Kontaktadresse (`watchrss/1.0 (+…)`) sendet watchrss den
User-Agent eines gängigen Browsers (`HTTP_USER_AGENT`). YouTube liefert
Kanalseiten an unbekannte Clients nicht zuverlässig aus. Eine Kontaktadresse
ist für dieses Projekt nicht vorgesehen. Matomo-Aufrufe tragen dagegen den
ehrlichen User-Agent `watchrss/1.0`.

### Ausgehende Anfragen

Ausgehende Anfragen laufen ausschließlich über HTTPS, auch Weiterleitungen
(höchstens drei). `http://`-Links zu YouTube werden hochgestuft. Das
Auflösen einer Eingabe hat ein Gesamt-Zeitbudget (`RESOLVE_BUDGET_SECONDS`).

API-Eingaben werden explizit auf Typ, gültiges UTF-8 und Grenzen geprüft
(`Support\Input`). Fehler kommen als 422 mit `fields`: Feldname, erwarteter
und erhaltener Typ – nie der Inhalt.
