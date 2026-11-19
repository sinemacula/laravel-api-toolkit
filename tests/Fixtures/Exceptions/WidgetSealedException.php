<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Exceptions;

/**
 * Fixture application exception inheriting its parent's code and meta.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WidgetSealedException extends WidgetLockedException {}
