<?php

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Logging\CloudWatchLogger;

/**
 * Notification event listener.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
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

        if (config('api-toolkit.logging.cloudwatch.enabled', false)) {
            Log::build([
                'driver'     => 'custom',
                'via'        => CloudWatchLogger::class,
                'aws'        => [
                    'region'      => config('api-toolkit.logging.cloudwatch.region'),
                    'credentials' => config('api-toolkit.logging.cloudwatch.credentials')
                ],
                'log_group'  => config('api-toolkit.logging.cloudwatch.log_group'),
                'log_stream' => 'notifications',
                'batch_size' => config('api-toolkit.logging.cloudwatch.batch_size'),
                'retention'  => config('api-toolkit.logging.cloudwatch.retention')
            ])->info($message, [
                'notification' => get_class($notification),
                'notifiable'   => get_class($notifiable),
                'channel'      => $channel
            ]);
        }
    }
}
