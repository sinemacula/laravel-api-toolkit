<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Monolog\Level;
use Monolog\LogRecord;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Logging\CloudWatchLogger;
use SineMacula\ApiToolkit\Logging\DatabaseHandler;
use SineMacula\ApiToolkit\Logging\DatabaseLogger;
use SineMacula\ApiToolkit\Models\LogMessage;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(NotificationListener::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(DatabaseLogger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(CloudWatchLogger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(DatabaseHandler::class)]
class LoggingAndListenerTest extends TestCase
{
    use InteractsWithNonPublicMembers;
    use MockeryPHPUnitIntegration;

    public function testNotificationListenerLogsSendingAndSentEvents(): void
    {
        config()->set('api-toolkit.logging.cloudwatch.enabled', false);

        Log::shouldReceive('channel')->with('notifications')->twice()->andReturnSelf();
        Log::shouldReceive('info')->twice();

        $listener     = new NotificationListener;
        $notification = new class extends Notification {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }
        };

        $notifiable = new User;

        $listener->sending(new NotificationSending($notifiable, $notification, 'mail'));
        $listener->sent(new NotificationSent($notifiable, $notification, 'mail', null));
    }

    public function testNotificationListenerAlsoLogsToCloudwatchWhenEnabled(): void
    {
        config()->set('api-toolkit.logging.cloudwatch.enabled', true);

        Log::shouldReceive('channel')->with('notifications')->andReturnSelf();
        Log::shouldReceive('channel')->with('cloudwatch-notifications')->andReturnSelf();
        Log::shouldReceive('info')->twice();

        $listener = new NotificationListener;

        $listener->sending(new NotificationSending(new User, new class extends Notification {
            public function via(object $notifiable): array
            {
                return ['sms'];
            }
        }, 'sms'));
    }

    public function testDatabaseLoggerFactoryReturnsMonologLogger(): void
    {
        $logger = (new DatabaseLogger)->__invoke([]);

        static::assertSame('database', $logger->getName());
        static::assertNotEmpty($logger->getHandlers());
    }

    public function testCloudWatchLoggerFactoryBuildsMonologLogger(): void
    {
        $logger = (new CloudWatchLogger)->__invoke([
            'aws' => [
                'region'      => 'us-east-1',
                'credentials' => [
                    'key'    => 'x',
                    'secret' => 'y',
                ],
            ],
            'log_group'  => 'group',
            'log_stream' => 'stream',
            'retention'  => 3,
            'batch_size' => 10,
            'level'      => 'info',
        ]);

        static::assertSame('cloudwatch', $logger->getName());
    }

    public function testDatabaseHandlerSkipsRecordsBelowConfiguredLevel(): void
    {
        config()->set('logging.channels.database.level', 'emergency');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2025-01-01 00:00:00'),
            channel: 'test',
            level: Level::Debug,
            message: 'debug',
            context: [],
        );

        $this->invokeNonPublic($handler, 'write', $record);

        static::assertSame(0, LogMessage::query()->count());
    }

    public function testDatabaseHandlerStoresMessagesAndSerializesThrowableContext(): void
    {
        config()->set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2025-01-01 00:00:00'),
            channel: 'test',
            level: Level::Info,
            message: 'hello',
            context: ['exception' => new \RuntimeException('boom')],
        );

        $this->invokeNonPublic($handler, 'write', $record);

        $log = LogMessage::query()->firstOrFail();

        static::assertSame('INFO', $log->level);
        static::assertStringContainsString('hello', $log->message);
        static::assertNotNull($log->getRawOriginal('context'));
        static::assertStringContainsString(\RuntimeException::class, (string) $log->getRawOriginal('context'));
    }

    public function testDatabaseHandlerFallsBackWhenDatabaseWriteFails(): void
    {
        Schema::drop('logs');

        Log::shouldReceive('stack')->twice()->andReturnSelf();
        Log::shouldReceive('debug')->twice();

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2025-01-01 00:00:00'),
            channel: 'test',
            level: Level::Error,
            message: 'error',
            context: [],
        );

        $this->invokeNonPublic($handler, 'write', $record);
    }
}
