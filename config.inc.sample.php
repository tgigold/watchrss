<?php
/**
 * watchrss - lokale Zugangsdaten und Geheimnisse.
 *
 * Diese Datei nach config.inc.php kopieren und ausfüllen:
 *     cp config.inc.sample.php config.inc.php
 *
 * config.inc.php ist per .gitignore von der Versionsverwaltung ausgeschlossen
 * und darf nie committet werden. Hier können auch Werte aus
 * config.app.inc.php überschrieben werden (z. B. MATOMO_URL, APP_BASE_URL).
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'watchrss');
define('DB_USER', 'watchrss');
define('DB_PASS', 'change-me');
define('DB_CHARSET', 'utf8mb4');

/**
 * Geheimnis für den Web-Trigger des Abrufs: https://example.org/cron/<token>
 * Leer = Endpunkt abgeschaltet.
 * Erzeugen mit: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */
define('CRON_TOKEN', '');

/**
 * Salz für die anonyme, täglich wechselnde Besucherkennung im Tracking.
 * Erzeugen mit: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */
define('TRACKING_SALT', '');

/**
 * Öffentliche Basis-URL ohne abschließenden Slash. Pflicht im Betrieb:
 * Links und HTTPS-Redirect nutzen nur diese Adresse.
 */
define('APP_BASE_URL', 'https://example.org');

/**
 * Geheimnis für die Wartungsseite /admin (Migrationen im Browser).
 * Leer = Seite abgeschaltet.
 * Erzeugen mit: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */
define('ADMIN_TOKEN', '');

/* Optionale Overrides für config.app.inc.php, z. B.: */
// define('TRUSTED_PROXIES', '10.0.0.0/8');
// define('MATOMO_ENABLED', true);
// define('MATOMO_URL', 'https://analytics.example.org/matomo.php');
// define('MATOMO_SITE_ID', 1);
