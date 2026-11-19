<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Fixture notification delivered through the recording channel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AuditedNotification extends Notification
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
}
