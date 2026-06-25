<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notification event listener.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NotificationListener
{
    /**
     * Handle the notification 'sending' event.
     *
     * @param  \Illuminate\Notifications\Events\NotificationSending  $event
     * @return void
     */
    public function sending(NotificationSending $event): void
    {
        $this->log('debug', 'Notification Sending', $event->notification, $event->notifiable, $event->channel);
    }

    /**
     * Handle the notification 'sent' event.
     *
     * @param  \Illuminate\Notifications\Events\NotificationSent  $event
     * @return void
     */
    public function sent(NotificationSent $event): void
    {
        $this->log('info', 'Notification Sent', $event->notification, $event->notifiable, $event->channel);
    }

    /**
     * Log the notification event.
     *
     * @param  string  $level
     * @param  string  $message
     * @param  \Illuminate\Notifications\Notification  $notification
     * @param  object  $notifiable
     * @param  string  $channel
     * @return void
     */
    private function log(string $level, string $message, Notification $notification, object $notifiable, string $channel): void
    {
        $excludedClasses = config('api-toolkit.notifications.excluded_classes', []);

        if (in_array($notification::class, $excludedClasses, true)) {
            return;
        }

        $payload = array_filter([
            'notification'    => $notification::class,
            'notifiable_type' => $notifiable::class,
            'notifiable_id'   => $notifiable instanceof Model ? $notifiable->getKey() : null,
            'channel'         => $channel,
        ], static fn (mixed $value): bool => $value !== null);

        Log::channel('notifications')->log($level, $message, $payload);

        if (!config('api-toolkit.logging.cloudwatch.enabled', false)) {
            return;
        }

        Log::channel('cloudwatch-notifications')->log($level, $message, $payload);
    }
}
