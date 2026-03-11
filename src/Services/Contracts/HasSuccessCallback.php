<?php

namespace SineMacula\ApiToolkit\Services\Contracts;

/**
 * Contract for traits that need to run a callback after successful
 * service execution.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface HasSuccessCallback
{
    /**
     * Handle post-success cleanup or notification for the trait.
     *
     * Called after the service's own success() callback and after the
     * database transaction has committed. Runs outside the try/catch
     * block -- exceptions here will NOT trigger the failed() callback.
     *
     * @return void
     */
    public function onTraitSuccess(): void;
}
