<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Fixture service that records the context and input of its run.
 *
 * The queue bridge builds the service itself, so what handle() read is held in
 * static properties for ServiceJob tests to assert.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContextReadingService extends Service
{
    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context the last run read */
    public static ?ServiceContext $seen = null;

    /** @var \SineMacula\ApiToolkit\Services\Contracts\ServiceInput|null The input the last run read */
    public static ?ServiceInput $seenInput = null;

    /**
     * Reset the recorded context and input between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$seen      = null;
        self::$seenInput = null;
    }

    /**
     * Record the context and input of the run.
     *
     * @return null
     */
    #[\Override]
    protected function handle(): null
    {
        self::$seen      = $this->context();
        self::$seenInput = $this->input;

        return null;
    }
}
