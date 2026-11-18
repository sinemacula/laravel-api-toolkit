<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;

/**
 * Asserts that reading or validating a setting fails with a given message.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait AssertsRefusedSettings
{
    /**
     * Assert that the read fails with the given message.
     *
     * @param  \Closure(): mixed  $read
     * @param  string  $message
     * @return void
     */
    protected function assertRefused(\Closure $read, string $message): void
    {
        try {
            $read();
        } catch (InvalidConfigurationException $exception) {
            static::assertSame($message, $exception->getMessage());

            return;
        }

        static::fail('The setting was read.');
    }
}
