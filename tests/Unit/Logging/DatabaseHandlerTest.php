<?php

namespace Tests\Unit\Logging;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Logging\DatabaseHandler;
use SineMacula\ApiToolkit\Models\LogMessage;
use Tests\TestCase;

/**
 * Tests for the DatabaseHandler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DatabaseHandler::class)]
class DatabaseHandlerTest extends TestCase
{
    /**
     * Test that write creates a LogMessage record in the database.
     *
     * @return void
     */
    public function testWriteCreatesLogMessageRecord(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'Test log message',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'level'   => 'INFO',
            'message' => 'Test log message',
        ]);
    }

    /**
     * Test that write stores level, message, and context.
     *
     * @return void
     */
    public function testWriteStoresLevelMessageAndContext(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Warning,
            message: 'Warning message',
            context: ['key' => 'value'],
        );

        $handler->handle($record);

        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::first();

        static::assertNotNull($log);
        static::assertSame('WARNING', $log->level);
        static::assertSame('Warning message', $log->message);
        static::assertStringContainsString('key', $log->getRawOriginal('context'));
    }

    /**
     * Test that write converts Throwable context to string.
     *
     * @return void
     */
    public function testWriteConvertsThrowableContextToString(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler   = new DatabaseHandler;
        $exception = new \RuntimeException('Something went wrong');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Error,
            message: 'Error occurred',
            context: ['exception' => $exception],
        );

        $handler->handle($record);

        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::first();

        static::assertNotNull($log);
        static::assertStringContainsString('Something went wrong', $log->getRawOriginal('context'));
        static::assertStringNotContainsString('RuntimeException Object', $log->getRawOriginal('context'));
    }

    /**
     * Test that write respects minimum log level.
     *
     * @return void
     */
    public function testWriteRespectsMinimumLogLevel(): void
    {
        Config::set('logging.channels.database.level', 'error');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Debug,
            message: 'Debug message that should be skipped',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseMissing('logs', [
            'message' => 'Debug message that should be skipped',
        ]);
    }

    /**
     * Test that write falls back to file logging on database failure.
     *
     * @return void
     */
    public function testWriteFallsBackToFileLoggingOnDatabaseFailure(): void
    {
        Config::set('logging.channels.database.level', 'debug');
        Config::set('logging.channels.fallback.channels', ['single']);

        Log::shouldReceive('stack')
            ->andReturnSelf();
        Log::shouldReceive('debug')
            ->twice();

        // Drop the logs table so the insert fails
        \Illuminate\Support\Facades\Schema::drop('logs');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Info,
            message: 'Should fall back',
            context: [],
        );

        $handler->handle($record);

        // No assertion needed beyond the mock expectations being met
    }

    /**
     * Test that high-level records pass shouldLog when minimum is debug.
     *
     * @return void
     */
    public function testHighLevelRecordPassesShouldLogAtDebugMinimum(): void
    {
        Config::set('logging.channels.database.level', 'debug');

        $handler = new DatabaseHandler;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'database',
            level: Level::Emergency,
            message: 'Emergency log',
            context: [],
        );

        $handler->handle($record);

        $this->assertDatabaseHas('logs', [
            'level'   => 'EMERGENCY',
            'message' => 'Emergency log',
        ]);
    }
}
