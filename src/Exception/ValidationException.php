<?php

declare(strict_types=1);

namespace WatchRss\Exception;

use RuntimeException;

/**
 * Ungültige Eingabedaten aus der API (führt zu HTTP 422).
 */
final class ValidationException extends RuntimeException
{
}
