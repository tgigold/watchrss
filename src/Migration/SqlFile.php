<?php

declare(strict_types=1);

namespace WatchRss\Migration;

/**
 * Zerlegt eine SQL-Datei (schema.sql) in einzelne Statements.
 *
 * Bewusst schlicht: Statements enden mit ";" am Zeilenende, Kommentarzeilen
 * beginnen mit "--" am Zeilenanfang. Für schema.sql reicht das; Backups
 * werden per phpMyAdmin oder mysql-Client eingespielt, nicht hiermit.
 */
final class SqlFile
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;

        $statements = [];
        foreach (explode(";\n", str_replace("\r\n", "\n", $sql) . "\n") as $chunk) {
            $statement = rtrim(trim($chunk), ';');
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
