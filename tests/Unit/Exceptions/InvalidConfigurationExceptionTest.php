<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use Tests\TestCase;

/**
 * Tests for the InvalidConfigurationException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InvalidConfigurationException::class)]
final class InvalidConfigurationExceptionTest extends TestCase
{
    /**
     * Provide given values with how the message names each.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function givenValueProvider(): iterable
    {
        yield 'a string' => ['maybe', '\'maybe\''];
        yield 'an empty string' => ['', '\'\''];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'an integer' => [-1, '-1'];
        yield 'a float' => [1.5, '1.5'];
        yield 'null' => [null, 'null'];
        yield 'an array' => [['a'], 'array'];
        yield 'an object' => [new \ArrayObject, \ArrayObject::class];
    }

    /**
     * Test that a refused value is named by its value when scalar, else by its
     * type.
     *
     * @param  mixed  $given
     * @param  string  $named
     * @return void
     */
    #[DataProvider('givenValueProvider')]
    public function testMustBeNamesTheGivenValue(mixed $given, string $named): void
    {
        $exception = InvalidConfigurationException::mustBe('api-toolkit.parser.alias', 'a non-empty string', $given);

        self::assertSame(sprintf('The api-toolkit.parser.alias setting must be a non-empty string; [%s] given.', $named), $exception->getMessage());
        self::assertSame($exception->getMessage(), $exception->problem);
    }

    /**
     * Test the messages for a missing and an unknown setting.
     *
     * @return void
     */
    public function testMissingAndUnknownMessages(): void
    {
        self::assertSame(
            'The api-toolkit.parser.alias setting is missing; add it from the published config.',
            InvalidConfigurationException::missing('api-toolkit.parser.alias')->getMessage(),
        );

        self::assertSame(
            'The api-toolkit.parser.aliass setting is not a toolkit setting; remove it or correct its name.',
            InvalidConfigurationException::unknown('api-toolkit.parser.aliass')->getMessage(),
        );
    }

    /**
     * Test that the problems are listed in one message, in order.
     *
     * @return void
     */
    public function testInvalidListsEveryProblem(): void
    {
        self::assertSame('First problem. Second problem.', InvalidConfigurationException::invalid(['First problem.', 'Second problem.'])->getMessage());
    }

    /**
     * Test that the exception keeps the error that caused it.
     *
     * @return void
     */
    public function testBecauseKeepsThePreviousError(): void
    {
        $previous = new \RuntimeException('Autoloading failed.');

        self::assertSame($previous, InvalidConfigurationException::because('Invalid.', $previous)->getPrevious());
        self::assertNull(InvalidConfigurationException::because('Invalid.')->getPrevious());
    }

    /**
     * Test that, under a cached configuration, the message tells how to rebuild
     * the cache while the problem stays the setting's alone.
     *
     * @return void
     */
    public function testCachedConfigurationMessageNamesTheCacheFile(): void
    {
        assert($this->app !== null);

        $this->app->instance('config_loaded_from_cache', true);

        $exception = InvalidConfigurationException::invalid(['First problem.', 'Second problem.']);

        self::assertSame('First problem. Second problem.', $exception->problem);
        self::assertSame(
            sprintf('First problem. Second problem. The configuration is cached; delete %s and rebuild it.', $this->app->getCachedConfigPath()),
            $exception->getMessage(),
        );
    }
}
