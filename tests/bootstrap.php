<?php
/**
 * PHPUnit-Bootstrap: Test-Konstanten statt config.inc.php, dann Autoloader.
 * Es wird nie die echte Konfiguration geladen und nie eine echte Datenbank
 * oder ein externer Dienst angesprochen.
 */

declare(strict_types=1);

require __DIR__ . '/constants.php';
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Erwartete Fehler aus Tests nicht in die Ausgabe schreiben.
ini_set('error_log', sys_get_temp_dir() . '/watchrss-test-error.log');
