<?php

declare(strict_types = 1);

namespace Tests\Unit\Query;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use Tests\TestCase;

/**
 * Tests for the QueryCostLimits value object.
 *
 * Every cap is exercised at exactly its limit and one above it, so the
 * comparison that separates an accepted request from a rejected one is pinned
 * from both sides.
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
        foreach (array_keys(QueryCostLimits::DEFAULTS) as $cap) {
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
     * Test that a measurement one above the cap is rejected, carrying the whole
     * rejection detail and the meta a client needs to correct the query.
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
     * Test that a cap set to null disables enforcement, matching the other
     * documented way of turning a dimension off rather than quietly restoring
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
     * Test that a negative cap disables enforcement rather than rejecting every
     * request.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNegativeCapDisablesEnforcement(): void
    {
        Config::set(self::CONFIG_PREFIX . QueryCostLimits::MAX_NODES, -1);

        $limits = QueryCostLimits::fromConfig();

        $limits->enforce(QueryCostLimits::MAX_NODES, 1, 'filters');

        self::assertSame(-1, $limits->limit(QueryCostLimits::MAX_NODES));
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
     * as its integer value.
     *
     * @return void
     */
    public function testNumericStringCapIsReadAsAnInteger(): void
    {
        Config::set(self::CONFIG_PREFIX . QueryCostLimits::MAX_NODES, '7');

        self::assertSame(7, QueryCostLimits::fromConfig()->limit(QueryCostLimits::MAX_NODES));
    }

    /**
     * Test that a non-numeric cap falls back to the shipped default rather than
     * silently disabling the cap.
     *
     * @return void
     */
    public function testNonNumericCapFallsBackToTheShippedDefault(): void
    {
        Config::set(self::CONFIG_PREFIX . QueryCostLimits::MAX_NODES, 'unbounded');

        self::assertSame(
            QueryCostLimits::DEFAULTS[QueryCostLimits::MAX_NODES],
            QueryCostLimits::fromConfig()->limit(QueryCostLimits::MAX_NODES),
        );
    }

    /**
     * Test that a config file predating the caps still yields every default.
     *
     * @return void
     */
    public function testAbsentConfigYieldsEveryShippedDefault(): void
    {
        Config::set('api-toolkit.query_cost', []);

        $limits = QueryCostLimits::fromConfig();

        foreach (QueryCostLimits::DEFAULTS as $cap => $default) {
            self::assertSame($default, $limits->limit($cap));
        }
    }

    /**
     * Test that the shipped config file publishes exactly the defaults the
     * class falls back to, so the two cannot drift.
     *
     * @return void
     */
    public function testShippedConfigMatchesTheFallbackDefaults(): void
    {
        self::assertSame(QueryCostLimits::DEFAULTS, Config::get('api-toolkit.query_cost'));
    }
}
