<?php

declare(strict_types=1);

namespace WatchRss\Migration;

use PDO;

/**
 * Eine Datenbank-Migration.
 *
 * Jede Migration liegt als eigene Datei in migrations/ und heißt
 * "NNNN_beschreibung.php" (vierstellige, fortlaufende Nummer). Die Datei
 * gibt eine Instanz dieses Interfaces zurück.
 *
 * Regeln:
 *  - Ausgerollte Migrationen werden nie geändert - es gibt immer eine neue.
 *  - Eine Migration läuft in einer eigenen Transaktion. MySQL beendet
 *    Transaktionen bei DDL (ALTER/CREATE/DROP TABLE) allerdings implizit;
 *    solche Schritte lassen sich nicht zurückrollen. Dafür gibt es das
 *    automatische Backup vor jedem Lauf.
 */
interface Migration
{
    /** Kurzbeschreibung für Status-Ausgaben. */
    public function description(): string;

    /**
     * Führt die Migration aus. Rückgabe: knappes Ergebnis für das Protokoll.
     */
    public function up(PDO $pdo, Schema $schema): string;
}
