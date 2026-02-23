<?php

namespace Tests\Unit\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use Tests\TestCase;

/**
 * Tests for the NotificationListener.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NotificationListener::class)]
class NotificationListenerTest extends TestCase
{
    /**
     * Test that sending logs notification sending event.
     *
     * @return void
     */
    public function testSendingLogsNotificationSendingEvent(): void
    {
        $this->app['config']->set('api-toolkit.logging.cloudwatch.enabled', false);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Notification Sending'
                    && isset($context['notification'], $context['notifiable'], $context['channel']),

            );

        $listener = new NotificationListener;
        $event    = $this->createSendingEvent();

        $listener->sending($event);
    }

    /**
     * Test that sent logs notification sent event.
     *
     * @return void
     */
    public function testSentLogsNotificationSentEvent(): void
    {
        $this->app['config']->set('api-toolkit.logging.cloudwatch.enabled', false);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Notification Sent'
                    && isset($context['notification'], $context['notifiable'], $context['channel']),

            );

        $listener = new NotificationListener;
        $event    = $this->createSentEvent();

        $listener->sent($event);
    }

    /**
     * Test that the log includes notification class, notifiable class, and channel.
     *
     * @return void
     */
    public function testLogIncludesCorrectContext(): void
    {
        $this->app['config']->set('api-toolkit.logging.cloudwatch.enabled', false);

        $notification = new class extends Notification {};
        $notifiable   = new \stdClass;
        $channel      = 'mail';

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['notification'] === $notification::class
                    && $context['notifiable']                                           === $notifiable::class
                    && $context['channel']                                              === 'mail');

        $listener = new NotificationListener;

        $event = new NotificationSending($notifiable, $notification, $channel);

        $listener->sending($event);
    }

    /**
     * Test that CloudWatch logging is called when enabled.
     *
     * @return void
     */
    public function testCloudWatchLoggingWhenEnabled(): void
    {
        $this->app['config']->set('api-toolkit.logging.cloudwatch.enabled', true);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('channel')
            ->with('cloudwatch-notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->twice();

        $listener = new NotificationListener;
        $event    = $this->createSendingEvent();

        $listener->sending($event);
    }

    /**
     * Create a NotificationSending event for testing.
     *
     * @return \Illuminate\Notifications\Events\NotificationSending
     */
    private function createSendingEvent(): NotificationSending
    {
        $notification = new class extends Notification {};
        $notifiable   = new \stdClass;

        return new NotificationSending($notifiable, $notification, 'mail');
    }

    /**
     * Create a NotificationSent event for testing.
     *
     * @return \Illuminate\Notifications\Events\NotificationSent
     */
    private function createSentEvent(): NotificationSent
    {
        $notification = new class extends Notification {};
        $notifiable   = new \stdClass;

        return new NotificationSent($notifiable, $notification, 'mail');
    }
}
