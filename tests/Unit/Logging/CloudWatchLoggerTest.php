<?php

namespace Tests\Unit\Logging;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
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

        $mockClient = $this->createMock(CloudWatchLogsClient::class);

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
}
