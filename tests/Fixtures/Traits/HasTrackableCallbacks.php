<?php

namespace Tests\Fixtures\Traits;

/**
 * Fixture trait for exercising Service trait lifecycle callbacks.
 *
 * Provides initializeTrait() and onTraitSuccess() implementations
 * so that tests can verify the Service base class correctly wires
 * lifecycle hooks via the Initializable and HasSuccessCallback
 * contracts.
 *
 * Classes using this trait should implement Initializable and
 * HasSuccessCallback.
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
     * Initialize the trait within the service context.
     *
     * @return void
     */
    public static function initializeTrait(): void
    {
        static::$traitInitialized = true;
    }

    /**
     * Handle post-success cleanup or notification for the trait.
     *
     * @return void
     */
    public function onTraitSuccess(): void
    {
        $this->traitSuccessRan = true;
    }
}
