<?php

namespace SineMacula\ApiToolkit\Logging;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch as CloudWatchHandler;

/**
 * CloudWatch Logger.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class CloudWatchLogger
{
    /**
     * Create a custom Monolog instance.
     *
     * @param  array  $config
     * @return \Monolog\Logger
     */
    public function __invoke(array $config): Logger
    {
        $client = new CloudWatchLogsClient([
            'version'     => 'latest',
            'region'      => $config['aws']['region'],
            'credentials' => $config['aws']['credentials'],
        ]);

        $handler = new CloudWatchHandler(
            $client,
            $config['log_group'],
            $config['log_stream'],
            (int) ($config['retention'] ?? 7),
            (int) ($config['batch_size'] ?? 1000),
            [],
            Logger::toMonologLevel($config['level'] ?? 'debug'),
        );

        return new Logger('cloudwatch', [$handler]);
    }
}
