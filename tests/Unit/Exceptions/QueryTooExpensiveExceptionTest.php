<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use Tests\TestCase;

/**
 * Tests for the QueryTooExpensiveException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryTooExpensiveException::class)]
final class QueryTooExpensiveExceptionTest extends TestCase
{
    /**
     * Test that the exception reports the cost error code and a 422 status.
     *
     * @return void
     */
    public function testReportsTheCostErrorCodeAndStatus(): void
    {
        $exception = QueryTooExpensiveException::exceeded('filters', '', 'max_nodes', 100, 101);

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame(ErrorCode::QUERY_TOO_EXPENSIVE->getCode(), QueryTooExpensiveException::getInternalErrorCode());
        self::assertSame(422, $exception->getStatusCode());
    }

    /**
     * Test that the exception renders the whole catalogue title and detail.
     *
     * @return void
     */
    public function testRendersTheWholeCatalogueTitleAndDetail(): void
    {
        $exception = QueryTooExpensiveException::exceeded('filters', '', 'max_nodes', 100, 101);

        self::assertSame('Query Too Expensive', $exception->getCustomTitle());
        self::assertSame(
            'The query exceeds a limit on how much work a single request may ask for, please narrow it and try again',
            $exception->getCustomDetail(),
        );
        self::assertSame($exception->getCustomDetail(), $exception->getMessage());
    }

    /**
     * Test that the meta carries every field a client needs to correct the
     * query without server-side diagnosis.
     *
     * @return void
     */
    public function testMetaCarriesTheParameterPointerReasonLimitAndActual(): void
    {
        $exception = QueryTooExpensiveException::exceeded('filters', '/$or/posts', 'max_depth', 3, 4);

        self::assertSame([
            'parameter' => 'filters',
            'pointer'   => '/$or/posts',
            'reason'    => 'max_depth',
            'limit'     => 3,
            'actual'    => 4,
        ], $exception->getCustomMeta());
    }
}
