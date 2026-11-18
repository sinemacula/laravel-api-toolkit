<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Foundation\Application;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use Tests\TestCase;

/**
 * Integration tests for the LoggingRegistrar.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LoggingRegistrar::class)]
final class LoggingRegistrarTest extends TestCase
{
    /**
     * Test that notification logging listeners are registered when enabled.
     *
     * @return void
     */
    public function testRegisterBindsNotificationLoggingListeners(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.notifications.enable_logging', true);

        (new LoggingRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        $raw = $events->getRawListeners();

        self::assertContains([NotificationListener::class, 'sending'], self::listenersFor($raw, NotificationSending::class));
        self::assertContains([NotificationListener::class, 'sent'], self::listenersFor($raw, NotificationSent::class));
    }

    /**
     * Test that no notification logging listeners are registered when disabled.
     *
     * @return void
     */
    public function testRegisterSkipsNotificationLoggingListenersWhenDisabled(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.notifications.enable_logging', false);

        (new LoggingRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        $raw = $events->getRawListeners();

        self::assertNotContains([NotificationListener::class, 'sending'], self::listenersFor($raw, NotificationSending::class));
        self::assertNotContains([NotificationListener::class, 'sent'], self::listenersFor($raw, NotificationSent::class));
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }

    /**
     * Read the raw listeners registered for the given event.
     *
     * @param  array<array-key, mixed>  $raw
     * @param  string  $event
     * @return array<array-key, mixed>
     */
    private static function listenersFor(array $raw, string $event): array
    {
        $listeners = $raw[$event] ?? [];

        self::assertIsArray($listeners);

        return $listeners;
    }
}
