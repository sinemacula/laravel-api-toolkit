<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

/**
 * Exception thrown when the cache store rejects a new metadata generation, so
 * the cached metadata was not invalidated.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataInvalidationException extends \RuntimeException {}
