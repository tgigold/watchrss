<?php
/**
 * watchrss - gemeinsamer Bootstrap für Web (www/index.php) und CLI (bin/*.php).
 *
 * Lädt Konfiguration + Autoloader und liefert den Service-Container zurück.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    exit('watchrss benötigt PHP 8.2 oder neuer.');
}

$root = __DIR__;

// Zugangsdaten und Anwendungs-Konfiguration (beide ausserhalb des Webroots).
if (!is_file($root . '/config.inc.php')) {
    exit('config.inc.php fehlt - bitte config.inc.sample.php kopieren und ausfüllen.');
}
require_once $root . '/config.inc.php';
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

return new WatchRss\App($root);
