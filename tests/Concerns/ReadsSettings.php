<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Support\SettingCases;

/**
 * Tests that each setting a class reads accepts its valid spellings and refuses
 * an invalid value, naming the key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ReadsSettings
{
    use AssertsRefusedSettings;

    /**
     * Provide the cases of the settings the class under test reads.
     *
     * @return iterable<string, array{string, \Closure(): mixed, mixed, mixed, mixed, string}>
     */
    public static function settingProvider(): iterable
    {
        yield from SettingCases::of(static::settings());
    }

    /**
     * Test that each setting reads an accepted value and refuses an invalid
     * one, naming the key.
     *
     * @param  string  $key
     * @param  \Closure(): mixed  $read
     * @param  mixed  $accepted
     * @param  mixed  $expected
     * @param  mixed  $refused
     * @param  string  $message
     * @return void
     */
    #[DataProvider('settingProvider')]
    public function testSettingReadsAnAcceptedValueAndRefusesAnInvalidOne(string $key, \Closure $read, mixed $accepted, mixed $expected, mixed $refused, string $message): void
    {
        Config::set('api-toolkit.' . $key, $accepted);

        static::assertSame($expected, $read());

        Config::set('api-toolkit.' . $key, $refused);

        $this->assertRefused($read, $message);
    }

    /**
     * Return the settings class under test.
     *
     * @return class-string
     */
    abstract protected static function settings(): string;
}
