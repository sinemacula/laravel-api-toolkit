<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Fixture service that records the context it reads through context().
 *
 * The queue bridge builds the service itself, so the context handle() read is
 * held in a static property for ServiceJob tests to assert.
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

    /**
     * Reset the recorded context between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$seen = null;
    }

    /**
     * Record the context of the run.
     *
     * @return null
     */
    #[\Override]
    protected function handle(): null
    {
        self::$seen = $this->context();

        return null;
    }
}
