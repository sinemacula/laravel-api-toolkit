<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * Exception thrown when a cache lock cannot be acquired due to contention.
 *
 * Contention is an expected outcome, observable as 429 responses, and is never
 * reported.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LockUnavailableException extends \RuntimeException implements ShouldntReport {}
