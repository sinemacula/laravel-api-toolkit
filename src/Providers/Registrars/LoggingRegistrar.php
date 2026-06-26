<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use SineMacula\ApiToolkit\Listeners\NotificationListener;

/**
 * Registers the toolkit notification logging functionality.
 *
 * Wires the notification logging listeners when enabled.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LoggingRegistrar
{
    /**
     * Register the toolkit logging functionality.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerNotificationLogging();
    }

    /**
     * Register the notification logging functionality.
     *
     * @return void
     */
    private function registerNotificationLogging(): void
    {
        if (!Config::get('api-toolkit.notifications.enable_logging', true)) {
            return;
        }

        Event::listen(NotificationSending::class, [NotificationListener::class, 'sending']);
        Event::listen(NotificationSent::class, [NotificationListener::class, 'sent']);
    }
}
