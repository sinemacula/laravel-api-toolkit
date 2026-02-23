<?php

namespace Tests\Unit\Logging;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Logging\DatabaseHandler;
use SineMacula\ApiToolkit\Logging\DatabaseLogger;
use Tests\TestCase;

/**
 * Tests for the DatabaseLogger.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DatabaseLogger::class)]
class DatabaseLoggerTest extends TestCase
{
    /**
     * Test that __invoke returns a Monolog Logger instance.
     *
     * @return void
     */
    public function testInvokeReturnsLoggerInstance(): void
    {
        $logger_factory = new DatabaseLogger;

        $logger = $logger_factory([]);

        static::assertInstanceOf(Logger::class, $logger);
    }

    /**
     * Test that the logger uses the 'database' channel name.
     *
     * @return void
     */
    public function testLoggerHasDatabaseChannelName(): void
    {
        $logger_factory = new DatabaseLogger;

        $logger = $logger_factory([]);

        static::assertSame('database', $logger->getName());
    }

    /**
     * Test that the logger has a DatabaseHandler.
     *
     * @return void
     */
    public function testLoggerHasDatabaseHandler(): void
    {
        $logger_factory = new DatabaseLogger;

        $logger = $logger_factory([]);

        $handlers = $logger->getHandlers();

        static::assertCount(1, $handlers);
        static::assertInstanceOf(DatabaseHandler::class, $handlers[0]);
    }
}
