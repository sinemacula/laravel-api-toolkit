<?php

namespace Tests\Fixtures\Traits;

/**
 * Fixture trait for exercising Service trait lifecycle callbacks.
 *
 * Declares both an initialize* and a *Success callback so that tests can
 * verify that Service::initializeTraits() and
 * Service::callTraitsSuccessCallbacks() invoke them correctly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait HasTrackableCallbacks
{
    /** @var bool Whether the trait initializer was called */
    public static bool $traitInitialized = false;

    /** @var bool Whether the trait success callback was called */
    public bool $traitSuccessRan = false;

    /**
     * Trait initializer, called by Service::initializeTraits().
     *
     * @return void
     */
    public static function initializeHasTrackableCallbacks(): void
    {
        static::$traitInitialized = true;
    }

    /**
     * Trait success callback, called by Service::callTraitsSuccessCallbacks().
     *
     * @return void
     */
    public function hasTrackableCallbacksSuccess(): void
    {
        $this->traitSuccessRan = true;
    }
}
