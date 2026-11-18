<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use Illuminate\Queue\Attributes\Queue;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that declares the queue it runs on.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Queue(self::QUEUE)]
final class ReportQueueService extends Service
{
    /** @var string The queue the service declares */
    public const string QUEUE = 'reports';

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
