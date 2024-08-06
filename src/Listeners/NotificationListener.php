<?php

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notification event listener.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
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
        Log::build([
            'driver' => 'daily',
            'path'   => storage_path('logs/notifications.log')
        ])->info($message, [
            'notification' => get_class($notification),
            'notifiable'   => get_class($notifiable),
            'channel'      => $channel
        ]);
    }
}
