<?php

namespace Tests\Fixtures\Listeners;

use SineMacula\ApiToolkit\Listeners\Traits\ProvidesExclusiveLock;

/**
 * Fixture base listener exposing the exclusive lock trait to subclasses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @inheritable
 */
class ExclusiveLockListenerFixture
{
    use ProvidesExclusiveLock;
}
