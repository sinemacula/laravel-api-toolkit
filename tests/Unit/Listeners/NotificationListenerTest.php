<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Log\Logger;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /** @var string The capturing channel made the application's default. */
    private const string DEFAULT_CHANNEL = 'notification-default';

    /** @var string The capturing channel configured as the audit destination. */
    private const string AUDIT_CHANNEL = 'notification-audit';

    /**
     * Test that sending logs notification sending event at debug level.
     *
     * @return void
     */
    public function testSendingLogsAtDebugLevel(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', []);
        Config::set('api-toolkit.notifications.log_channel', null);

        Log::shouldReceive('channel')
            ->with(null)
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
        Config::set('api-toolkit.notifications.log_channel', null);

        Log::shouldReceive('channel')
            ->with(null)
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
        Config::set('api-toolkit.notifications.log_channel', null);

        $notification = new class extends Notification {};
        $notifiable   = new \stdClass;
        $channel      = 'mail';

        Log::shouldReceive('channel')
            ->with(null)
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
        Config::set('api-toolkit.notifications.log_channel', null);

        Log::shouldReceive('channel')
            ->with(null)
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
        Config::set('api-toolkit.notifications.log_channel', null);

        Log::shouldReceive('channel')
            ->with(null)
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once();

        $listener = new NotificationListener;
        $event    = $this->createSendingEvent();

        $listener->sending($event);
    }

    /**
     * Test that the payload omits the notifiable id when the notifiable is not
     * an Eloquent model.
     *
     * @return void
     */
    public function testPayloadOmitsNotifiableIdForNonModelNotifiable(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', []);
        Config::set('api-toolkit.notifications.log_channel', null);

        Log::shouldReceive('channel')
            ->with(null)
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
     * Test that the audit log is written to the application's default channel
     * when no channel is configured.
     *
     * @return void
     */
    public function testAuditLogIsWrittenToTheDefaultChannelWhenNoChannelIsConfigured(): void
    {
        $this->useCapturingChannels(null);

        $event = $this->createSendingEvent();

        (new NotificationListener)->sending($event);

        $records = $this->capturedRecords(self::DEFAULT_CHANNEL);

        self::assertCount(1, $records);
        self::assertSame(Level::Debug, $records[0]->level);
        self::assertSame('Notification Sending', $records[0]->message);
        self::assertSame([
            'notification'    => $event->notification::class,
            'notifiable_type' => \stdClass::class,
            'channel'         => 'mail',
        ], $records[0]->context);
    }

    /**
     * Test that the audit log is written only to the configured channel.
     *
     * @return void
     */
    public function testAuditLogIsWrittenToTheConfiguredChannel(): void
    {
        $this->useCapturingChannels(self::AUDIT_CHANNEL);

        (new NotificationListener)->sent($this->createSentEvent());

        $records = $this->capturedRecords(self::AUDIT_CHANNEL);

        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame('Notification Sent', $records[0]->message);
        self::assertSame([], $this->capturedRecords(self::DEFAULT_CHANNEL));
    }

    /**
     * Provide configured channel values that do not name a channel.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unusableChannelProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'false' => [false];
    }

    /**
     * Test that a blank or non-string configured channel falls back to the
     * application's default channel.
     *
     * @param  mixed  $channel
     * @return void
     */
    #[DataProvider('unusableChannelProvider')]
    public function testBlankOrNonStringChannelFallsBackToTheDefaultChannel(mixed $channel): void
    {
        $this->useCapturingChannels($channel);

        (new NotificationListener)->sending($this->createSendingEvent());

        $records = $this->capturedRecords(self::DEFAULT_CHANNEL);

        self::assertCount(1, $records);
        self::assertSame('Notification Sending', $records[0]->message);
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

    /**
     * Register the capturing channels, make one the application's default and
     * configure the audit log channel.
     *
     * @param  mixed  $auditChannel
     * @return void
     */
    private function useCapturingChannels(mixed $auditChannel): void
    {
        foreach ([self::DEFAULT_CHANNEL, self::AUDIT_CHANNEL] as $name) {
            Config::set("logging.channels.{$name}", [
                'driver'  => 'monolog',
                'handler' => TestHandler::class,
            ]);
        }

        Config::set('logging.default', self::DEFAULT_CHANNEL);
        Config::set('api-toolkit.notifications.excluded_classes', []);
        Config::set('api-toolkit.notifications.log_channel', $auditChannel);
    }

    /**
     * Read the records captured on the given channel.
     *
     * @param  string  $name
     * @return array<int, \Monolog\LogRecord>
     */
    private function capturedRecords(string $name): array
    {
        $channel = Log::channel($name);

        assert($channel instanceof Logger);

        $monolog = $channel->getLogger();

        assert($monolog instanceof MonologLogger);

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler->getRecords();
            }
        }

        self::fail("No capturing handler is registered on the {$name} channel.");
    }
}
