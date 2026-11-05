<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Exceptions;

/**
 * Exception thrown when an action declares a body for a no-content status.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ResponseStatusConflictException extends \RuntimeException {}
