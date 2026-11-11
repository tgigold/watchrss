<?php
/**
 * watchrss - Anwendungs-Konfiguration.
 *
 * Liegt bewusst ausserhalb von /www. Die MySQL-Zugangsdaten stehen weiterhin
 * in config.inc.php; diese Datei enthält nur Anwendungs-Schalter. Alle Werte
 * sind mit defined()-Guards versehen, können also in config.inc.php oder per
 * Environment vorab überschrieben werden.
 */

declare(strict_types=1);

/* --------------------------------------------------------------------------
 * Allgemein
 * ----------------------------------------------------------------------- */

/** Anzeigename der Installation. */
defined('APP_NAME') || define('APP_NAME', 'watchrss');

/**
 * Öffentliche Basis-URL ohne abschließenden Slash, z. B. "https://watchrss.de".
 * PFLICHT im Betrieb - gehört in die config.inc.php. Alle Links und der
 * HTTPS-Redirect nutzen diese Adresse, nie den Host-Header des Requests.
 * Leer ist nur bei lokaler Entwicklung erlaubt (localhost, *.test, ...).
 */
defined('APP_BASE_URL') || define('APP_BASE_URL', '');

/**
 * Vertrauenswürdige Reverse-Proxys, kommagetrennt (IPs oder CIDR-Netze),
 * z. B. "10.0.0.0/8, 2001:db8::/32". Nur von diesen Adressen werden
 * X-Forwarded-For und X-Forwarded-Proto übernommen. Leer = keine Proxys,
 * die IP kommt dann immer aus REMOTE_ADDR (Normalfall bei Shared Hosting).
 */
defined('TRUSTED_PROXIES') || define('TRUSTED_PROXIES', '');

/** true = Fehler im Klartext ausgeben (nur lokal!). */
defined('APP_DEBUG') || define('APP_DEBUG', false);

/** Zeitzone für die Ausgabe im Frontend. Gespeichert wird immer in UTC. */
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Europe/Berlin');

/* --------------------------------------------------------------------------
 * HTTP-Client (YouTube-Abrufe)
 * ----------------------------------------------------------------------- */

/**
 * User-Agent für die YouTube-Abrufe. Bewusst ein gängiger Browser statt
 * "watchrss/<version> (+Kontakt)": YouTube liefert Kanalseiten an
 * unbekannte Clients nicht zuverlässig aus. Siehe README, "Fair Use".
 */
defined('HTTP_USER_AGENT') || define(
    'HTTP_USER_AGENT',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'
);
defined('HTTP_TIMEOUT') || define('HTTP_TIMEOUT', 15);
defined('HTTP_CONNECT_TIMEOUT') || define('HTTP_CONNECT_TIMEOUT', 8);

/**
 * Gesamt-Zeitbudget (Sekunden), um eine Eingabe im Editor zu Kanälen
 * aufzulösen. Danach startet kein neuer Abruf mehr; offene Einträge werden
 * als Fehler gemeldet. Ein laufender Abruf kann bis zu HTTP_TIMEOUT länger
 * dauern.
 */
defined('RESOLVE_BUDGET_SECONDS') || define('RESOLVE_BUDGET_SECONDS', 25);

/* --------------------------------------------------------------------------
 * Abruf der Kanal-Feeds (bin/fetch.php)
 * ----------------------------------------------------------------------- */

/** Mindestabstand zwischen zwei Abrufen desselben Kanals (Sekunden). */
defined('CHANNEL_REFRESH_SECONDS') || define('CHANNEL_REFRESH_SECONDS', 1800);

/** Maximale Anzahl Kanäle pro Cron-Lauf (schont Laufzeit-Limits). */
defined('FETCH_BATCH_SIZE') || define('FETCH_BATCH_SIZE', 40);

/** Pause zwischen zwei Kanal-Abrufen in Mikrosekunden (höflich bleiben). */
defined('FETCH_SLEEP_MICROSECONDS') || define('FETCH_SLEEP_MICROSECONDS', 250000);

/** So viele Videos werden je Kanal maximal vorgehalten, Rest wird gelöscht. */
defined('VIDEO_RETENTION_PER_CHANNEL') || define('VIDEO_RETENTION_PER_CHANNEL', 200);

/* --------------------------------------------------------------------------
 * Ausliefern der generierten Feeds
 * ----------------------------------------------------------------------- */

/** Standard-TTL eines neuen Feeds in Minuten (vom Nutzer aenderbar). */
defined('FEED_DEFAULT_TTL_MINUTES') || define('FEED_DEFAULT_TTL_MINUTES', 120);

/** Erlaubte TTL-Werte im Editor (Minuten). */
defined('FEED_TTL_CHOICES') || define('FEED_TTL_CHOICES', '30,60,120,360,720');

/** Standard- bzw. Maximalzahl an Items im kombinierten Feed. */
defined('FEED_DEFAULT_MAX_ITEMS') || define('FEED_DEFAULT_MAX_ITEMS', 100);
defined('FEED_MAX_ITEMS_LIMIT') || define('FEED_MAX_ITEMS_LIMIT', 300);

/** Maximale Anzahl Kanäle je Feed. */
defined('FEED_MAX_CHANNELS') || define('FEED_MAX_CHANNELS', 60);

/**
 * Anteil der TTL, den ein Client zwischen zwei *vollen* Antworten mindestens
 * warten muss. 0.5 = wer oefter als alle TTL/2 pollt, bekommt 429 statt Body.
 * 0 schaltet die Bremse ab.
 */
defined('FEED_THROTTLE_RATIO') || define('FEED_THROTTLE_RATIO', 0.5);

/**
 * So viele volle Antworten sind je Zeitfenster frei, bevor 429 kommt.
 * Damit sperrt man sich beim Anschauen im Browser nicht selbst aus.
 */
defined('FEED_THROTTLE_BURST') || define('FEED_THROTTLE_BURST', 3);

/**
 * So viele unbekannte Feed-Adressen darf eine IP pro Stunde anfragen, bevor
 * sie 429 bekommt. Bremst das Durchprobieren kurzer Feed-Tokens.
 */
defined('FEED_UNKNOWN_LIMIT_PER_HOUR') || define('FEED_UNKNOWN_LIMIT_PER_HOUR', 20);

/** Harte Obergrenze: Requests pro Token und IP innerhalb einer Stunde. */
defined('FEED_ABUSE_LIMIT_PER_HOUR') || define('FEED_ABUSE_LIMIT_PER_HOUR', 60);

/* --------------------------------------------------------------------------
 * Web-Trigger für den Abruf (/cron/<token>)
 * ----------------------------------------------------------------------- */

/**
 * Geheimnis für den Web-Trigger. Leer = Endpunkt ist abgeschaltet.
 *
 * Gehört in die config.inc.php (die liegt außerhalb der Versionsverwaltung),
 * nicht hierher. Erzeugen mit:
 *     php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */
defined('CRON_TOKEN') || define('CRON_TOKEN', '');

/**
 * Zeitbudget eines Web-Laufs in Sekunden. Sollte deutlich unter dem
 * Timeout des Webservers liegen (oft 60s). Was nicht mehr in den Lauf passt,
 * kommt beim nächsten Aufruf dran.
 */
defined('CRON_MAX_SECONDS') || define('CRON_MAX_SECONDS', 45);

/* --------------------------------------------------------------------------
 * Statistik (serverseitiges Matomo-Tracking)
 *
 * Gemeldet werden nur drei Dinge: Startseite, Editor, RSS-Abruf - jeweils
 * ohne Token in der Adresse. Siehe Service\Tracking\TrackingEvent.
 * ----------------------------------------------------------------------- */

/** Schalter für das gesamte Tracking. */
defined('MATOMO_ENABLED') || define('MATOMO_ENABLED', false);

/** Endpunkt. Ohne Dateinamen wird "/matomo.php" ergänzt. */
defined('MATOMO_URL') || define('MATOMO_URL', 'https://analytics.example.org/matomo.php');

/** Site-ID in Matomo. */
defined('MATOMO_SITE_ID') || define('MATOMO_SITE_ID', 1);

/** Zeitlimit für den Tracking-Aufruf in Sekunden. */
defined('MATOMO_TIMEOUT') || define('MATOMO_TIMEOUT', 2);

/** Do Not Track / Global Privacy Control respektieren. */
defined('TRACKING_HONOR_DNT') || define('TRACKING_HONOR_DNT', true);

/**
 * Salz für die tageweise wechselnde Besucherkennung. Gehört als Geheimnis
 * in die config.inc.php; fehlt es, wird ersatzweise eines abgeleitet.
 * Erzeugen mit: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */
defined('TRACKING_SALT') || define('TRACKING_SALT', '');

/* --------------------------------------------------------------------------
 * Pfade
 * ----------------------------------------------------------------------- */

/**
 * Fehler-Log der Web-Anwendung. Liegt im Datenverzeichnis: per FTP lesbar,
 * über das Web gesperrt (var/.htaccess). CLI-Skripte schreiben weiter nach
 * STDERR, damit Cron-Mails die Fehler zeigen. Leer = Server-Standard.
 */
defined('ERROR_LOG_FILE') || define('ERROR_LOG_FILE', dirname(__FILE__) . '/var/log/error.log');

/* --------------------------------------------------------------------------
 * Wartung: Migrationen und Backups
 * ----------------------------------------------------------------------- */

/**
 * Geheimnis für die Wartungsseite /admin (Migrationen im Browser).
 * Gehört in die config.inc.php. Leer = Seite abgeschaltet.
 * Erzeugen mit: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */
defined('ADMIN_TOKEN') || define('ADMIN_TOKEN', '');

/** Fehlversuche je IP, bevor /admin für ADMIN_LOCK_MINUTES gesperrt ist. */
defined('ADMIN_MAX_FAILURES') || define('ADMIN_MAX_FAILURES', 5);
defined('ADMIN_LOCK_MINUTES') || define('ADMIN_LOCK_MINUTES', 15);

/** Ziel der automatischen Backups vor Migrationen (per FTP lesbar, Web gesperrt). */
defined('BACKUP_DIR') || define('BACKUP_DIR', dirname(__FILE__) . '/var/backups');

/** So viele Backups bleiben liegen, ältere werden gelöscht. */
defined('BACKUP_KEEP') || define('BACKUP_KEEP', 10);

/** Verzeichnis für den Datei-Cache (muss beschreibbar sein). */
defined('CACHE_DIR') || define('CACHE_DIR', dirname(__FILE__) . '/var/cache');
