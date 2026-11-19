<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Fixture notification channel that records each notification it delivers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RecordingChannel
{
    /** @var array<int, class-string<\Illuminate\Notifications\Notification>> The notifications delivered */
    public static array $delivered = [];

    /**
     * Deliver the notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        self::$delivered[] = $notification::class;
    }
}
