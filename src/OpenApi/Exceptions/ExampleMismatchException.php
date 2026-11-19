<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Exceptions;

/**
 * Exception thrown when an example does not match the schema it sits beside.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExampleMismatchException extends \RuntimeException {}
