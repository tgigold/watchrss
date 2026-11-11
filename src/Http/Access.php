<?php

declare(strict_types=1);

namespace WatchRss\Http;

/**
 * Wer eine Route aufrufen darf. Jede Route muss das ausdrücklich angeben.
 *
 * watchrss hat bewusst kein Login; die Rolle des Logins übernehmen geheime
 * Tokens. Kommt später ein Login dazu, wird es hier ein weiterer Fall.
 */
enum Access: string
{
    /** Für alle - Startseite, Kanal-Auflösung, Feed anlegen. */
    case Public = 'öffentlich';

    /** Nur wer den geheimen Token in der URL kennt (Editor, Web-Trigger). */
    case SecretUrl = 'geheimer Token in der URL';

    /** Nur mit ADMIN_TOKEN im POST-Body (Wartung). */
    case AdminToken = 'ADMIN_TOKEN im POST-Body';

    /** Nur vor der Einrichtung, mit Setup-Code aus var/ (per FTP lesbar). */
    case SetupCode = 'Setup-Code im POST-Body';
}
