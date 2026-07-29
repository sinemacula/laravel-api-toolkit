<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Exceptions;

/**
 * Exception thrown when two distinct resources derive the same component name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SchemaNameCollisionException extends \RuntimeException {}
