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
 * The logging configuration permutations are pinned by the ApiServiceProvider
 * integration suite; this test proves the registrar registers its surface when
 * invoked directly.
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
     * Test that the notification logging listeners are registered when the
     * registrar is invoked with logging enabled and illuminate/notifications is
     * installed (class_exists guard traversed successfully).
     *
     * @return void
     */
    public function testRegisterBindsNotificationLoggingListeners(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.notifications.enable_logging', true);

        // illuminate/notifications is present via testbench, so class_exists
        // returns true and the listeners must be wired.
        self::assertTrue(class_exists(NotificationSending::class), 'Prerequisite: illuminate/notifications must be present for this assertion to hold');

        (new LoggingRegistrar)->register();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        $raw = $events->getRawListeners();

        self::assertContains([NotificationListener::class, 'sending'], $raw[NotificationSending::class] ?? []);
        self::assertContains([NotificationListener::class, 'sent'], $raw[NotificationSent::class] ?? []);
    }

    /**
     * Test that no notification logging listeners are registered when logging
     * is explicitly disabled in configuration.
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

        self::assertNotContains([NotificationListener::class, 'sending'], $raw[NotificationSending::class] ?? []);
        self::assertNotContains([NotificationListener::class, 'sent'], $raw[NotificationSent::class] ?? []);
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
}
