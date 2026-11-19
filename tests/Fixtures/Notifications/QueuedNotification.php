<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fixture notification delivered through the recording channel by a queue
 * worker.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, class-string>
     */
    public function via(): array
    {
        return [RecordingChannel::class];
    }
}
