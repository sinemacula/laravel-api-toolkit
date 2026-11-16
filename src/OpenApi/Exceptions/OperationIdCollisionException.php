<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Exceptions;

/**
 * Exception thrown when two exported operations derive the same operationId.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OperationIdCollisionException extends \RuntimeException {}
