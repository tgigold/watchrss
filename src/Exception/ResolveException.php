<?php

declare(strict_types=1);

namespace WatchRss\Exception;

use RuntimeException;

/**
 * Ein Nutzer-Eingabewert konnte keinem YouTube-Kanal zugeordnet werden.
 * Die Message ist bewusst so formuliert, dass sie direkt im UI stehen kann.
 */
final class ResolveException extends RuntimeException
{
}
