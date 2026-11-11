<?php

declare(strict_types=1);

namespace WatchRss\Migration;

/**
 * Sichert die Datenbank vor einem Migrationslauf.
 */
interface Backup
{
    /**
     * Legt ein Backup an und liefert den Dateipfad.
     *
     * @throws \RuntimeException wenn das Backup nicht geschrieben werden kann -
     *                           dann darf keine Migration laufen.
     */
    public function create(): string;
}
