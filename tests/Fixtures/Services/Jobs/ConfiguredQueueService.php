<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that declares every queue setting a service can declare.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Backoff(5, 10)]
#[Connection('redis')]
#[CountCrashesAsExceptions]
#[Delay(30)]
#[FailOnTimeout]
#[MaxExceptions(2)]
#[Queue('reports')]
#[Timeout(120)]
#[Tries(3)]
final class ConfiguredQueueService extends Service
{
    /**
     * Complete the run.
     *
     * @return null
     */
    #[\Override]
    protected function handle(): null
    {
        return null;
    }
}
