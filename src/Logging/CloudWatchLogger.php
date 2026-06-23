<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Logging;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch as CloudWatchHandler;

/**
 * CloudWatch Logger.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CloudWatchLogger
{
    /**
     * Create a custom Monolog instance.
     *
     * @param  array<string, mixed>  $config
     * @return \Monolog\Logger
     */
    public function __invoke(array $config): Logger
    {
        $client = new CloudWatchLogsClient([
            'version'     => 'latest',
            'region'      => $config['aws']['region'],
            'credentials' => $config['aws']['credentials'],
        ]);

        $retention = $config['retention']  ?? 7;
        $batchSize = $config['batch_size'] ?? 1000;

        $handler = new CloudWatchHandler(
            $client,
            $config['log_group'],
            $config['log_stream'],
            is_numeric($retention) ? (int) $retention : 7,
            is_numeric($batchSize) ? (int) $batchSize : 1000,
            [],
            Logger::toMonologLevel($config['level'] ?? 'debug'),
        );

        return new Logger('cloudwatch', [$handler]);
    }
}
