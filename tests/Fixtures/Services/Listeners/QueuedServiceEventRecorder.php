<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use SineMacula\ApiToolkit\Services\Events\ServiceEvent;

/**
 * Fixture queued listener that records each service event a worker hands it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueuedServiceEventRecorder implements ShouldQueue
{
    /** @var array<int, \SineMacula\ApiToolkit\Services\Events\ServiceEvent> The events handled so far */
    public static array $events = [];

    /**
     * Record the event.
     *
     * @param  \SineMacula\ApiToolkit\Services\Events\ServiceEvent  $event
     * @return void
     */
    public function handle(ServiceEvent $event): void
    {
        self::$events[] = $event;
    }
}
