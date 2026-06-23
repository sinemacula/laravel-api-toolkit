<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Logging\CloudWatchLogger;
use Aws\CloudWatchLogs\CloudWatchLogsClient;

/**
 * Registers the toolkit logging functionality.
 *
 * Extends the log manager with the CloudWatch driver when the AWS SDK is
 * available, and wires the notification logging listeners when enabled.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LoggingRegistrar
{
    /**
     * Create a new logging registrar instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(

        /** The service container for resolving the log manager. */
        private readonly Container $container,

    ) {}

    /**
     * Register the toolkit logging functionality.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerCloudwatchLogger();
        $this->registerNotificationLogging();
    }

    /**
     * Register the Cloudwatch logger driver.
     *
     * @return void
     */
    private function registerCloudwatchLogger(): void
    {
        if (!class_exists(CloudWatchLogsClient::class)) {
            return;
        }

        $this->container->make(LogManager::class)->extend('cloudwatch', fn ($app, array $config) => (new CloudWatchLogger)->__invoke($config));
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
