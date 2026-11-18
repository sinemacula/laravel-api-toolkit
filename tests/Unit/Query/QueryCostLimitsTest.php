<?php

declare(strict_types = 1);

namespace Tests\Unit\Query;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use Tests\TestCase;

/**
 * Tests for the QueryCostLimits value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryCostLimits::class)]
final class QueryCostLimitsTest extends TestCase
{
    /** @var string The detail the error catalogue renders for a cost rejection */
    private const string EXPECTED_DETAIL = 'The query exceeds a limit on how much work a single request may ask for, please narrow it and try again';

    /** @var string The configuration prefix every cap is read from */
    private const string CONFIG_PREFIX = 'api-toolkit.query_cost.';

    /**
     * Provide every cap the toolkit enforces.
     *
     * @return iterable<string, array{string}>
     */
    public static function capProvider(): iterable
    {
        foreach (QueryCostLimits::NAMES as $cap) {
            yield $cap => [$cap];
        }
    }

    /**
     * Test that a measurement of exactly the cap is accepted.
     *
     * @param  string  $cap
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('capProvider')]
    public function testMeasurementAtTheCapIsAccepted(string $cap): void
    {
        Config::set(self::CONFIG_PREFIX . $cap, 5);

        $limits = QueryCostLimits::fromConfig();

        $limits->enforce($cap, 5, 'filters');

        self::assertSame(5, $limits->limit($cap));
    }

    /**
     * Test that a measurement one above the cap is rejected with the full
     * rejection detail and meta.
     *
     * @param  string  $cap
     * @return void
     */
    #[DataProvider('capProvider')]
    public function testMeasurementOneAboveTheCapIsRejected(string $cap): void
    {
        Config::set(self::CONFIG_PREFIX . $cap, 5);

        try {
            QueryCostLimits::fromConfig()->enforce($cap, 6, 'filters', '/posts/title');

            self::fail('Expected a rejection for a measurement above the "' . $cap . '" cap.');
        } catch (QueryTooExpensiveException $exception) {

            self::assertSame(self::EXPECTED_DETAIL, $exception->getMessage());
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => '/posts/title',
                'reason'    => $cap,
                'limit'     => 5,
                'actual'    => 6,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Test that a cap of zero disables enforcement entirely.
     *
     * @param  string  $cap
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('capProvider')]
    public function testCapOfZeroDisablesEnforcement(string $cap): void
    {
        Config::set(self::CONFIG_PREFIX . $cap, 0);

        $limits = QueryCostLimits::fromConfig();

        $limits->enforce($cap, 1000000, 'filters');

        self::assertSame(0, $limits->limit($cap));
    }

    /**
     * Test that a cap set to null disables enforcement rather than restoring
     * the shipped default.
     *
     * @param  string  $cap
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('capProvider')]
    public function testCapOfNullDisablesEnforcement(string $cap): void
    {
        Config::set(self::CONFIG_PREFIX . $cap, null);

        $limits = QueryCostLimits::fromConfig();

        $limits->enforce($cap, 1000000, 'filters');

        self::assertSame(0, $limits->limit($cap));
    }

    /**
     * Provide caps that are neither null nor a whole number of at least 0, with
     * how the message names each.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidCapProvider(): iterable
    {
        yield 'negative' => [-1, '-1'];
        yield 'negative string, as the environment gives' => ['-1', '\'-1\''];
        yield 'non-numeric' => ['unbounded', '\'unbounded\''];
        yield 'fractional' => ['10.0', '\'10.0\''];
        yield 'exponent' => ['1e3', '\'1e3\''];
        yield 'suffixed' => ['8k', '\'8k\''];
        yield 'empty string' => ['', '\'\''];
    }

    /**
     * Test that an invalid cap fails rather than disabling the guard or
     * restoring a default.
     *
     * @param  mixed  $value
     * @param  string  $given
     * @return void
     */
    #[DataProvider('invalidCapProvider')]
    public function testInvalidCapFails(mixed $value, string $given): void
    {
        Config::set(self::CONFIG_PREFIX . QueryCostLimits::MAX_DEPTH, $value);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('The api-toolkit.query_cost.max_depth setting must be an integer of at least 0, or null; [%s] given.', $given));

        QueryCostLimits::fromConfig();
    }

    /**
     * Test that an unrecognised cap reports no limit and enforces nothing.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUnknownCapIsUnlimited(): void
    {
        $limits = QueryCostLimits::fromConfig();

        $limits->enforce('max_unknown', 1000000, 'filters');

        self::assertSame(0, $limits->limit('max_unknown'));
    }

    /**
     * Test that a numeric string, as an environment override supplies, is read
     * as an integer.
     *
     * @return void
     */
    public function testNumericStringCapIsReadAsAnInteger(): void
    {
        Config::set(self::CONFIG_PREFIX . QueryCostLimits::MAX_NODES, '7');

        self::assertSame(7, QueryCostLimits::fromConfig()->limit(QueryCostLimits::MAX_NODES));
    }

    /**
     * Test that a missing cap fails rather than reading as disabled.
     *
     * @return void
     */
    public function testMissingCapFails(): void
    {
        Config::set('api-toolkit.query_cost', []);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.query_cost.max_bytes setting is missing; add it from the published config.');

        QueryCostLimits::fromConfig();
    }

    /**
     * Test that the shipped config file sets every cap, in order, and nothing
     * else.
     *
     * @return void
     */
    public function testShippedConfigSetsEveryCap(): void
    {
        $shipped = Config::get('api-toolkit.query_cost');

        self::assertIsArray($shipped);
        self::assertSame(QueryCostLimits::NAMES, array_keys($shipped));
    }
}
