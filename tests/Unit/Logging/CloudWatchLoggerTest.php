<?php

namespace Tests\Unit\Logging;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Level;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch as CloudWatchHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Logging\CloudWatchLogger;
use Tests\TestCase;

/**
 * Tests for the CloudWatchLogger.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CloudWatchLogger::class)]
class CloudWatchLoggerTest extends TestCase
{
    /**
     * Test that __invoke returns a Monolog Logger instance.
     *
     * @return void
     */
    public function testInvokeReturnsLoggerInstance(): void
    {
        $loggerFactory = new CloudWatchLogger;

        $config = $this->buildConfig();

        $mockClient = static::createStub(CloudWatchLogsClient::class);

        assert($this->app !== null);

        $this->app->bind(CloudWatchLogsClient::class, fn () => $mockClient);

        $logger = $loggerFactory($config);

        static::assertInstanceOf(Logger::class, $logger);
    }

    /**
     * Test that the logger uses the 'cloudwatch' channel name.
     *
     * @return void
     */
    public function testLoggerHasCloudwatchChannelName(): void
    {
        $loggerFactory = new CloudWatchLogger;

        $config = $this->buildConfig();

        $logger = $loggerFactory($config);

        static::assertSame('cloudwatch', $logger->getName());
    }

    /**
     * Test that the logger has exactly one handler configured.
     *
     * @return void
     */
    public function testLoggerHasOneHandler(): void
    {
        $loggerFactory = new CloudWatchLogger;

        $config = $this->buildConfig();

        $logger = $loggerFactory($config);

        static::assertCount(1, $logger->getHandlers());
    }

    /**
     * Test that the handler receives the configured retention and batch
     * size.
     *
     * @return void
     */
    public function testHandlerReceivesConfiguredRetentionAndBatchSize(): void
    {
        $config = $this->buildConfig();

        $config['retention']  = 14;
        $config['batch_size'] = 500;

        $handler = $this->resolveHandler($config);

        static::assertSame(14, $this->getHandlerProperty($handler, 'retention'));
        static::assertSame(500, $this->getHandlerProperty($handler, 'batchSize'));
    }

    /**
     * Test that the handler falls back to the default retention and batch
     * size when the configuration keys are absent.
     *
     * @return void
     */
    public function testHandlerDefaultsRetentionAndBatchSizeWhenAbsent(): void
    {
        $config = $this->buildConfig();

        unset($config['retention'], $config['batch_size']);

        $handler = $this->resolveHandler($config);

        static::assertSame(7, $this->getHandlerProperty($handler, 'retention'));
        static::assertSame(1000, $this->getHandlerProperty($handler, 'batchSize'));
    }

    /**
     * Test that the handler falls back to the default retention and batch
     * size when the configured values are not numeric.
     *
     * @return void
     */
    public function testHandlerDefaultsRetentionAndBatchSizeWhenNotNumeric(): void
    {
        $config = $this->buildConfig();

        $config['retention']  = 'not-a-number';
        $config['batch_size'] = 'not-a-number';

        $handler = $this->resolveHandler($config);

        static::assertSame(7, $this->getHandlerProperty($handler, 'retention'));
        static::assertSame(1000, $this->getHandlerProperty($handler, 'batchSize'));
    }

    /**
     * Test that the handler honours the configured logging level.
     *
     * @return void
     */
    public function testHandlerHonoursConfiguredLevel(): void
    {
        $config = $this->buildConfig();

        $config['level'] = 'error';

        $handler = $this->resolveHandler($config);

        static::assertSame(Level::Error, $handler->getLevel());
    }

    /**
     * Test that the handler defaults to the debug logging level when the
     * level key is absent.
     *
     * @return void
     */
    public function testHandlerDefaultsToDebugLevelWhenAbsent(): void
    {
        $config = $this->buildConfig();

        unset($config['level']);

        $handler = $this->resolveHandler($config);

        static::assertSame(Level::Debug, $handler->getLevel());
    }

    /**
     * Build a configuration array for the CloudWatch logger.
     *
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        return [
            'aws' => [
                'region'      => 'us-east-1',
                'credentials' => [
                    'key'    => 'test-key',
                    'secret' => 'test-secret',
                ],
            ],
            'log_group'  => 'test-log-group',
            'log_stream' => 'test-log-stream',
            'retention'  => 7,
            'batch_size' => 1000,
            'level'      => 'debug',
        ];
    }

    /**
     * Resolve the CloudWatch handler created for the given configuration.
     *
     * @param  array<string, mixed>  $config
     * @return \PhpNexus\Cwh\Handler\CloudWatch
     */
    private function resolveHandler(array $config): CloudWatchHandler
    {
        $loggerFactory = new CloudWatchLogger;

        $handler = $loggerFactory($config)->getHandlers()[0];

        static::assertInstanceOf(CloudWatchHandler::class, $handler);

        return $handler;
    }

    /**
     * Read a non-public property from the CloudWatch handler.
     *
     * @param  \PhpNexus\Cwh\Handler\CloudWatch  $handler
     * @param  string  $property
     * @return mixed
     */
    private function getHandlerProperty(CloudWatchHandler $handler, string $property): mixed
    {
        $reflection = new \ReflectionProperty(CloudWatchHandler::class, $property);

        return $reflection->getValue($handler);
    }
}
