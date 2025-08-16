<?php

namespace SineMacula\ApiToolkit\Logging;

use Exception;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use SineMacula\ApiToolkit\Models\LogMessage;
use Throwable;

/**
 * Custom Monolog handler for database logging.
 *
 * This handler stores log records in the database using the LogMessage model.
 * It supports exception logging and falls back to file logging if database
 * logging fails.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class DatabaseHandler extends AbstractProcessingHandler
{
    /**
     * Write a log record to the database.
     *
     * @param  \Monolog\LogRecord  $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        if (!$this->shouldLog($record)) {
            return;
        }

        $context = $record->context;

        // Convert exception objects to string before storing
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $context['exception'] = (string) $context['exception'];
        }

        try {
            LogMessage::create([
                'level'      => $record->level->getName(),
                'message'    => $record->message,
                'context'    => empty($context) ? null : json_encode($context, JSON_THROW_ON_ERROR),
                'created_at' => $record->datetime,
            ]);
        } catch (Exception $e) {
            $this->logToFallback($record, $e);
        }
    }

    /**
     * Check if the log record meets the minimum level threshold.
     *
     * @param  \Monolog\LogRecord  $record
     * @return bool
     */
    private function shouldLog(LogRecord $record): bool
    {
        $minimum_level = Level::fromName(config('logging.channels.database.level', 'debug'));

        return $record->level->value >= $minimum_level->value;
    }

    /**
     * Log to fallback channels in case of database logging failure.
     *
     * @param  \Monolog\LogRecord  $record
     * @param  Exception  $exception
     * @return void
     */
    private function logToFallback(LogRecord $record, Exception $exception): void
    {
        $fallback_channels = config('logging.channels.fallback.channels', ['single']);

        Log::stack($fallback_channels)->debug($record->formatted ?? $record->message);
        Log::stack($fallback_channels)->debug('Could not log to the database.', [
            'exception' => $exception->getMessage(),
        ]);
    }
}
