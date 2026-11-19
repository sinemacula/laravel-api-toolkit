<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Fixture notification that declines every send.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeclinedNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, class-string>
     */
    public function via(): array
    {
        return [RecordingChannel::class];
    }

    /**
     * Determine whether the notification should be sent.
     *
     * @return bool
     */
    public function shouldSend(): bool
    {
        return false;
    }
}
