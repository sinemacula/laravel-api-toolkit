<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

/**
 * Thrown when a polymorphic resource mapping is missing or invalid.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ResourceMappingException extends \LogicException {}
