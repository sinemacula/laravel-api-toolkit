<?php

declare(strict_types = 1);

namespace Tests\Integration\Notifications;

use Illuminate\Log\Logger;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Notifications\AuditedNotification;
use Tests\Fixtures\Notifications\DeclinedNotification;
use Tests\Fixtures\Notifications\QueuedNotification;
use Tests\Fixtures\Notifications\RecordingChannel;
use Tests\TestCase;

/**
 * End-to-end tests that real notification sends write the audit log.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LoggingRegistrar::class)]
#[CoversClass(NotificationListener::class)]
final class NotificationAuditLogTest extends TestCase
{
    use UsesDatabaseQueue;

    /** @var string The log channel the audit entries are written to. */
    private const string CHANNEL = 'notification-audit';

    /**
     * Forget the notifications delivered by an earlier test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        RecordingChannel::$delivered = [];
    }

    /**
     * Test that a notification sent to a model logs its sending and its sent
     * entries with the model's key.
     *
     * @return void
     */
    public function testModelNotifiableLogsSendingAndSent(): void
    {
        $user = $this->user();

        Notification::send($user, new AuditedNotification);

        self::assertSame($this->entries(AuditedNotification::class, User::class, $user->id), $this->records());
    }

    /**
     * Test that an on-demand notification logs no notifiable id.
     *
     * @return void
     */
    public function testOnDemandNotifiableLogsNoId(): void
    {
        Notification::route(RecordingChannel::class, 'ops')->notify(new AuditedNotification);

        self::assertSame($this->entries(AuditedNotification::class, AnonymousNotifiable::class), $this->records());
    }

    /**
     * Test that an excluded notification is delivered without being logged.
     *
     * @return void
     */
    public function testExcludedNotificationIsDeliveredUnlogged(): void
    {
        Config::set('api-toolkit.notifications.excluded_classes', [AuditedNotification::class]);

        Notification::send($this->user(), new AuditedNotification);

        self::assertSame([AuditedNotification::class], RecordingChannel::$delivered);
        self::assertSame([], $this->records());
    }

    /**
     * Test that a notification declining to send logs nothing.
     *
     * @return void
     */
    public function testDeclinedNotificationLogsNothing(): void
    {
        Notification::send($this->user(), new DeclinedNotification);

        self::assertSame([], RecordingChannel::$delivered);
        self::assertSame([], $this->records());
    }

    /**
     * Test that a queued notification is logged by the worker that sends it.
     *
     * @return void
     */
    public function testQueuedNotificationIsLoggedByTheWorker(): void
    {
        $this->useDatabaseQueue();

        $user = $this->user();

        Notification::send($user, new QueuedNotification);

        self::assertSame([], $this->records());

        $this->workOnce();

        self::assertSame($this->entries(QueuedNotification::class, User::class, $user->id), $this->records());
    }

    /**
     * Enable the notification audit log on a capturing channel before the
     * application boots.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('logging.channels.' . self::CHANNEL, ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $config->set('api-toolkit.notifications.enable_logging', true);
        $config->set('api-toolkit.notifications.log_channel', self::CHANNEL);
    }

    /**
     * Create a user to notify.
     *
     * @return \Tests\Fixtures\Models\User
     */
    private function user(): User
    {
        return User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
    }

    /**
     * Build the sending and sent entries expected for one delivery.
     *
     * @param  class-string  $notification
     * @param  class-string  $notifiable
     * @param  int|null  $id
     * @return list<array{string, string, array<array-key, mixed>}>
     */
    private function entries(string $notification, string $notifiable, ?int $id = null): array
    {
        $context = array_filter([
            'notification'    => $notification,
            'notifiable_type' => $notifiable,
            'notifiable_id'   => $id,
            'channel'         => RecordingChannel::class,
        ], static fn (mixed $value): bool => $value !== null);

        return [
            ['debug', 'Notification Sending', $context],
            ['info', 'Notification Sent', $context],
        ];
    }

    /**
     * Read the level, message and context of every captured audit entry.
     *
     * @return list<array{string, string, array<array-key, mixed>}>
     */
    private function records(): array
    {
        $channel = Log::channel(self::CHANNEL);

        assert($channel instanceof Logger);

        $monolog = $channel->getLogger();

        assert($monolog instanceof MonologLogger);

        $handler = $monolog->getHandlers()[0];

        assert($handler instanceof TestHandler);

        return array_values(array_map(
            static fn (LogRecord $record): array => [$record->level->toPsrLogLevel(), $record->message, $record->context],
            $handler->getRecords(),
        ));
    }
}
