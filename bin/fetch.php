#!/usr/bin/env php
<?php
/**
 * watchrss - Kanal-Feeds abholen (Cronjob für die Konsole).
 *
 * Holt reihum die Kanäle ab, die länger als CHANNEL_REFRESH_SECONDS nicht
 * aktualisiert wurden, und schreibt neue Videos in die Datenbank. Nur Kanäle,
 * die in mindestens einem Nutzer-Feed vorkommen, werden überhaupt gepollt.
 *
 * Die Arbeit selbst steckt in WatchRss\Service\FetchRunner - diese Datei ist
 * nur die Konsolen-Hülle drumherum. Wer keinen Konsolen-Cron hat, ruft
 * stattdessen den Web-Trigger /cron/<CRON_TOKEN> auf (siehe README).
 *
 * Empfohlener Cron (alle 10 Minuten):
 *   *\/10 * * * * /usr/bin/php /pfad/zu/bin/fetch.php --quiet
 *
 * Optionen:
 *   --limit=N    maximal N Kanäle in diesem Lauf (Default: FETCH_BATCH_SIZE)
 *   --force      Intervall ignorieren und alle Kanäle holen
 *   --channel=UC nur diesen einen Kanal
 *   --prune      zusätzlich alte Videos, Cache und Waisen aufräumen
 *   --max-seconds=N  Zeitbudget für den Lauf (0 = unbegrenzt, Default)
 *   --quiet      nur Fehler ausgeben
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Nur über die Kommandozeile aufrufbar.');
}

/** @var WatchRss\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['limit::', 'force', 'channel::', 'prune', 'quiet', 'max-seconds::']);
$quiet   = isset($options['quiet']);

$log = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo '[', gmdate('H:i:s'), '] ', $line, "\n";
    }
};

$summary = $app->fetchRunner()->run(
    limit: isset($options['limit']) ? max(1, (int) $options['limit']) : (int) FETCH_BATCH_SIZE,
    force: isset($options['force']),
    maxSeconds: isset($options['max-seconds']) ? max(0, (int) $options['max-seconds']) : 0,
    prune: isset($options['prune']),
    // getopt liefert ein Array, wenn die Option mehrfach angegeben wurde.
    onlyChannelId: isset($options['channel']) && is_string($options['channel'])
        ? $options['channel']
        : null,
);

// null = eine Sperre ist aktiv, es läuft bereits ein Abruf.
if ($summary === null) {
    $log('Es läuft bereits ein Abruf - Lauf übersprungen.');
    exit(0);
}

$log(sprintf('%d Kanal/Kanäle fällig', $summary->due));
foreach ($summary->lines as $line) {
    $log('  ' . $line);
}

if ($summary->prunedVideos > 0 || $summary->removedChannels > 0 || $summary->removedCacheFiles > 0) {
    $log(sprintf(
        'Aufgeräumt: %d Videos, %d verwaiste Kanäle, %d Cache-Dateien',
        $summary->prunedVideos,
        $summary->removedChannels,
        $summary->removedCacheFiles,
    ));
}

if ($summary->hasErrors()) {
    // Fehler gehen nach STDERR, damit der Cron-Daemon sie auch bei --quiet mailt.
    fwrite(STDERR, '[watchrss] ' . $summary->headline() . "\n");
}

$log('Fertig: ' . $summary->headline());

exit($summary->hasErrors() ? 1 : 0);
