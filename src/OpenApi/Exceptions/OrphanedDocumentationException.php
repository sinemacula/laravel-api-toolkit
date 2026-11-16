<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Exceptions;

/**
 * Exception thrown when a documentation file describes nothing exported.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OrphanedDocumentationException extends \RuntimeException {}
