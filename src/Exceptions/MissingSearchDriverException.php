<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

/**
 * Thrown when no search driver is registered for a database connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MissingSearchDriverException extends \RuntimeException {}
