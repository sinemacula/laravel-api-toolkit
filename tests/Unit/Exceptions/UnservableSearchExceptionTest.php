<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\UnservableSearchException;

/**
 * Tests for the UnservableSearchException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(UnservableSearchException::class)]
final class UnservableSearchExceptionTest extends TestCase
{
    /**
     * Test that the exception extends RuntimeException, so a driver that cannot
     * serve a declared strategy is a deployment failure rather than a client
     * error rendered as a rejection.
     *
     * @return void
     */
    public function testExtendsRuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, UnservableSearchException::unsupportedStrategy('mysql', SearchStrategy::EXACT));
    }

    /**
     * Test that an unsupported strategy names the connection and the strategy
     * the resource declared.
     *
     * @return void
     */
    public function testUnsupportedStrategyNamesTheConnectionAndStrategy(): void
    {
        $exception = UnservableSearchException::unsupportedStrategy('pgsql', SearchStrategy::SUBSTRING);

        self::assertSame(
            'The search driver registered for the "pgsql" connection does not implement the "substring" match strategy this resource declares.',
            $exception->getMessage(),
        );
    }

    /**
     * Test that unproven index backing names the connection, the strategy, and
     * the configuration key that waives the proof.
     *
     * @return void
     */
    public function testUnprovenIndexBackingNamesTheWaiverItNeeds(): void
    {
        $exception = UnservableSearchException::unprovenIndexBacking('sqlite', SearchStrategy::PREFIX);

        self::assertSame(
            'The search driver registered for the "sqlite" connection cannot prove an index serves the "prefix" match strategy, so the search would scan the table. '
            . 'List the connection under api-toolkit.search.unverified_connections to serve it regardless.',
            $exception->getMessage(),
        );
    }
}
