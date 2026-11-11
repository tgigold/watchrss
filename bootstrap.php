<?php
/**
 * watchrss - gemeinsamer Bootstrap für Web (public/index.php) und CLI (bin/*.php).
 *
 * Lädt Konfiguration + Autoloader und liefert den Service-Container zurück.
 */

declare(strict_types=1);

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    exit('watchrss benötigt PHP 8.2 oder neuer.');
}

$root = __DIR__;

// Zugangsdaten und Anwendungs-Konfiguration (beide ausserhalb des Webroots).
// Fehlt config.inc.php, startet im Browser das geführte Setup (App::$setupMode).
if (is_file($root . '/config.inc.php')) {
    require_once $root . '/config.inc.php';
} elseif (PHP_SAPI === 'cli') {
    exit("config.inc.php fehlt - im Browser einrichten (/setup) oder config.inc.sample.php kopieren und ausfüllen.\n");
}
require_once $root . '/config.app.inc.php';

if (!is_file($root . '/vendor/autoload.php')) {
    exit('Autoloader fehlt - bitte "composer install" ausführen.');
}
require_once $root . '/vendor/autoload.php';

// Intern wird ausschließlich in UTC gerechnet; die Anzeige-Zeitzone
// (APP_TIMEZONE) wird erst im Template angewendet.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// Fehler der Web-Anwendung ins eigene Log im Datenverzeichnis.
if (PHP_SAPI !== 'cli' && ERROR_LOG_FILE !== '') {
    $logDir = dirname((string) ERROR_LOG_FILE);
    if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
        ini_set('error_log', (string) ERROR_LOG_FILE);
    }
    unset($logDir);
}

return new WatchRss\App($root);
