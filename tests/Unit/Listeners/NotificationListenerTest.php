<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
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
final class NotificationListenerTest extends TestCase
{
    /**
     * Test that sending logs notification sending event at debug level.
     *
     * @return void
     */
    public function testSendingLogsAtDebugLevel(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', []);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(
                fn (string $level, string $message, array $context) => $level === 'debug'
                    && $message                                               === 'Notification Sending'
                    && isset($context['notification'], $context['notifiable_type'], $context['channel']),
            );

        $listener = new NotificationListener;
        $event    = $this->createSendingEvent();

        $listener->sending($event);
    }

    /**
     * Test that sent logs notification sent event at info level.
     *
     * @return void
     */
    public function testSentLogsAtInfoLevel(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', []);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(
                fn (string $level, string $message, array $context) => $level === 'info'
                    && $message                                               === 'Notification Sent'
                    && isset($context['notification'], $context['notifiable_type'], $context['channel']),
            );

        $listener = new NotificationListener;
        $event    = $this->createSentEvent();

        $listener->sent($event);
    }

    /**
     * Test that the log includes notification class, notifiable class, and
     * channel.
     *
     * @return void
     */
    public function testLogIncludesCorrectContext(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', []);

        $notification = new class extends Notification {};
        $notifiable   = new \stdClass;
        $channel      = 'mail';

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['notification'] === $notification::class
                    && $context['notifiable_type']                                                     === $notifiable::class
                    && $context['channel']                                                             === 'mail');

        $listener = new NotificationListener;

        $event = new NotificationSending($notifiable, $notification, $channel);

        $listener->sending($event);
    }

    /**
     * Test that excluded notification class produces no log for sending.
     *
     * @return void
     */
    public function testExcludedClassSkipsLoggingForSending(): void
    {
        $notification = new class extends Notification {};

        Config::set('api-toolkit.notifications.excluded_classes', [$notification::class]);

        Log::shouldReceive('channel')->never();
        Log::shouldReceive('log')->never();

        $listener = new NotificationListener;
        $event    = new NotificationSending(new \stdClass, $notification, 'mail');

        $listener->sending($event);
    }

    /**
     * Test that excluded notification class produces no log for sent.
     *
     * @return void
     */
    public function testExcludedClassSkipsLoggingForSent(): void
    {
        $notification = new class extends Notification {};

        Config::set('api-toolkit.notifications.excluded_classes', [$notification::class]);

        Log::shouldReceive('channel')->never();
        Log::shouldReceive('log')->never();

        $listener = new NotificationListener;
        $event    = new NotificationSent(new \stdClass, $notification, 'mail');

        $listener->sent($event);
    }

    /**
     * Test that non-excluded notification is logged when exclusion list is
     * non-empty.
     *
     * @return void
     */
    public function testNonExcludedClassIsLoggedWhenExclusionListIsNonEmpty(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', ['App\Notifications\SomeOtherNotification']);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once();

        $listener = new NotificationListener;
        $event    = $this->createSendingEvent();

        $listener->sending($event);
    }

    /**
     * Test that empty exclusion list logs all notifications.
     *
     * @return void
     */
    public function testEmptyExclusionListLogsAllNotifications(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', []);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once();

        $listener = new NotificationListener;
        $event    = $this->createSendingEvent();

        $listener->sending($event);
    }

    /**
     * Test that the payload omits the notifiable id when the notifiable is
     * not an Eloquent model.
     *
     * @return void
     */
    public function testPayloadOmitsNotifiableIdForNonModelNotifiable(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', []);

        Log::shouldReceive('channel')
            ->with('notifications')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(
                fn (string $level, string $message, array $context) => !array_key_exists('notifiable_id', $context)
                    && isset($context['notification'], $context['notifiable_type'], $context['channel']),
            );

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
