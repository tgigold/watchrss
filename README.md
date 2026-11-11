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
php bin/install.php       # legt die Tabellen an, prüft die Umgebung
```

`bin/install.php` ist beliebig oft ausführbar (`CREATE TABLE IF NOT EXISTS`).

Für **bestehende** Installationen gibt es `bin/migrate.php`. Jeder Schritt
prüft selbst, ob er schon erledigt ist. Schema-Änderungen, die eine laufende
Seite betreffen, sind zweistufig angelegt (expand/contract):

```bash
php bin/migrate.php            # Stufe 1: additiv, alte und neue Codefassung laufen
#   ... neue Codefassung hochladen ...
php bin/migrate.php --finish   # Stufe 2: Altlasten entfernen
php bin/migrate.php --status   # nur anzeigen, nichts ändern
```

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

## Deployment

Jeder Push auf `main` wird per GitHub Actions (`.github/workflows/deploy.yml`)
über FTPS auf den Server geladen; manuell auslösbar unter *Actions → Deploy →
Run workflow*. Der Workflow erzeugt vorher den Autoloader (`composer install`).

Einmalig einrichten: *Settings → Environments → `production`* anlegen
(Deployment branches: nur `main`) und dort die Secrets `FTP_SERVER`,
`FTP_USERNAME`, `FTP_PASSWORD` und `FTP_SERVER_DIR` (Projektstamm mit
abschließendem `/`) hinterlegen.

`config.inc.php` und `var/cache/` werden nie hochgeladen oder gelöscht; die
Konfiguration auf dem Server wird einmalig von Hand angelegt.

---

## Konfiguration

| Datei | Inhalt |
|---|---|
| `config.inc.php` | MySQL-Zugangsdaten und Geheimnisse – nicht versioniert, Vorlage: `config.inc.sample.php` |
| `config.app.inc.php` | Anwendungs-Schalter, alle mit `defined()`-Guard |

Die wichtigsten Werte:

| Konstante | Standard | Bedeutung |
|---|---|---|
| `APP_BASE_URL` | `''` | Öffentliche Basis-URL. Leer = aus dem Request ableiten. **Für korrekte Links im Feed setzen.** |
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
| `MATOMO_ENABLED` | `true` | Schalter für das Tracking |
| `MATOMO_URL` | – | Endpunkt; ohne Dateinamen wird `/matomo.php` ergänzt |
| `MATOMO_SITE_ID` | – | Site-ID in Matomo |
| `TRACKING_HONOR_DNT` | `true` | Do Not Track / Global Privacy Control respektieren |
| `TRACKING_SALT` | `''` | Salz für die Besucherkennung (gehört in `config.inc.php`) |

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
schema.sql              Datenbankschema
bin/install.php         Einrichtung
bin/migrate.php         Schema-Migrationen (zweistufig, ohne Ausfallzeit)
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
| `edit_token` | 32 | base62 (~190 Bit) | nur im Bearbeiten-Link | voller Zugriff |
| `feed_token` | 10 | 56 Zeichen ohne `0/O`, `1/l/I` (3 · 10¹⁷) | in der RSS-Adresse | man sieht die Kanalliste |

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

Weitere Maßnahmen: PDO mit echten Prepared Statements, `htmlspecialchars` in
allen Templates (`$this->e()`), XML-Erzeugung über `XMLWriter` statt
String-Konkatenation, `LIBXML_NONET` beim Parsen (kein XXE), Host-Whitelist
beim Abrufen fremder URLs und IP-Adressen nur gehasht im Cache.
