<?php

declare(strict_types=1);

namespace WatchRss\Migration;

use RuntimeException;
use Throwable;

/**
 * Eine Migration ist gescheitert. Trägt mit, was vorher schon gelaufen ist
 * und wo das Backup liegt.
 */
final class MigrationFailed extends RuntimeException
{
    /**
     * @param list<array{version:int,name:string,result:string}> $applied
     */
    public function __construct(
        public readonly int $version,
        public readonly string $name,
        public readonly array $applied,
        public readonly ?string $backupFile,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Migration %04d_%s fehlgeschlagen: %s', $version, $name, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
