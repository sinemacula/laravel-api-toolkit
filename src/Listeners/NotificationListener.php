<?php

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
class NotificationListener
{
    /**
     * Handle the notification 'sending' event.
     *
     * @param  \Illuminate\Notifications\Events\NotificationSending  $event
     * @return void
     */
    public function sending(NotificationSending $event): void
    {
        $this->log('Notification Sending', $event->notification, $event->notifiable, $event->channel);
    }

    /**
     * Handle the notification 'sent' event.
     *
     * @param  \Illuminate\Notifications\Events\NotificationSent  $event
     * @return void
     */
    public function sent(NotificationSent $event): void
    {
        $this->log('Notification Sent', $event->notification, $event->notifiable, $event->channel);
    }

    /**
     * Log the notification event.
     *
     * @param  string  $message
     * @param  \Illuminate\Notifications\Notification  $notification
     * @param  mixed  $notifiable
     * @param  string  $channel
     * @return void
     */
    private function log(string $message, Notification $notification, mixed $notifiable, string $channel): void
    {
        $payload = array_filter([
            'notification'    => $notification::class,
            'notifiable_type' => $notifiable::class,
            'notifiable_id'   => $notifiable instanceof Model ? $notifiable->getKey() : null,
            'channel'         => $channel,
        ]);

        Log::channel('notifications')->info($message, $payload);

        if (config('api-toolkit.logging.cloudwatch.enabled', false)) {
            Log::channel('cloudwatch-notifications')->info($message, $payload);
        }
    }
}
