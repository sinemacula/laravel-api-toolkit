<?php

declare(strict_types = 1);

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
final class DatabaseLoggerTest extends TestCase
{
    /**
     * Test that __invoke returns a Monolog Logger instance.
     *
     * @return void
     */
    public function testInvokeReturnsLoggerInstance(): void
    {
        $loggerFactory = new DatabaseLogger;

        $logger = $loggerFactory([]);

        static::assertInstanceOf(Logger::class, $logger);
    }

    /**
     * Test that the logger uses the 'database' channel name.
     *
     * @return void
     */
    public function testLoggerHasDatabaseChannelName(): void
    {
        $loggerFactory = new DatabaseLogger;

        $logger = $loggerFactory([]);

        static::assertSame('database', $logger->getName());
    }

    /**
     * Test that the logger has a DatabaseHandler.
     *
     * @return void
     */
    public function testLoggerHasDatabaseHandler(): void
    {
        $loggerFactory = new DatabaseLogger;

        $logger = $loggerFactory([]);

        $handlers = $logger->getHandlers();

        static::assertCount(1, $handlers);
        static::assertInstanceOf(DatabaseHandler::class, $handlers[0]);
    }
}
