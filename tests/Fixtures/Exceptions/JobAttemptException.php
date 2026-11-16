<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Exceptions;

/**
 * Exception thrown when a fixture queue job attempt fails.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class JobAttemptException extends \RuntimeException {}
