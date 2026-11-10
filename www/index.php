<?php
/**
 * watchrss - Front-Controller.
 *
 * Einziger PHP-Einstiegspunkt im Webroot. Alles andere (Konfiguration,
 * Klassen, Templates, Cache) liegt eine Ebene darueber und ist damit
 * ueber die Domain nicht erreichbar.
 */

declare(strict_types=1);

// Eingebauter PHP-Entwicklungsserver (php -S ... www/index.php): vorhandene
// Dateien wie CSS/JS direkt ausliefern. Unter Apache/nginx erledigt das die
// Server-Konfiguration (siehe .htaccess), dieser Block greift dort nie.
if (PHP_SAPI === 'cli-server') {
    $requested = __DIR__ . (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    if (is_file($requested)) {
        return false;
    }
}

/** @var WatchRss\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

$request  = WatchRss\Http\Request::fromGlobals();
$response = $app->handle($request);
$response->send();

// Ab hier interessiert den Besucher nichts mehr: Unter php-fpm schließt
// fastcgi_finish_request() die Verbindung, das Skript läuft aber weiter.
// Das Tracking kostet damit null Wartezeit. Fehlt die Funktion (mod_php),
// läuft es danach ganz normal - mit knappem Zeitlimit im Tracker.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

$app->track($request, $response);
