<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;

/**
 * Tests for the AggregateFunction enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AggregateFunction::class)]
final class AggregateFunctionTest extends TestCase
{
    /**
     * Test that the cases carry the SQL function names Laravel aggregates by.
     *
     * @return void
     */
    public function testCasesCarryTheSqlFunctionNames(): void
    {
        self::assertSame(
            ['count', 'exists', 'sum', 'avg', 'min', 'max'],
            array_column(AggregateFunction::cases(), 'value'),
        );
    }

    /**
     * Test that only count and exists aggregate without a named column.
     *
     * @return void
     */
    public function testOnlyCountAndExistsTakeNoColumn(): void
    {
        $columnless = array_filter(
            AggregateFunction::cases(),
            static fn (AggregateFunction $function): bool => !$function->requiresColumn(),
        );

        self::assertSame([AggregateFunction::COUNT, AggregateFunction::EXISTS], array_values($columnless));
    }

    /**
     * Provide aggregates mapped to the key Laravel aliases them under.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\AggregateFunction, string, string|null, string}>
     */
    public static function defaultKeyProvider(): iterable
    {
        yield 'count' => [AggregateFunction::COUNT, 'posts', null, 'posts_count'];
        yield 'exists' => [AggregateFunction::EXISTS, 'messages', null, 'messages_exists'];
        yield 'sum' => [AggregateFunction::SUM, 'orders', 'total', 'orders_sum_total'];
        yield 'average' => [AggregateFunction::AVG, 'orders', 'total', 'orders_avg_total'];
        yield 'minimum' => [AggregateFunction::MIN, 'variants', 'price', 'variants_min_price'];
        yield 'camel-case relation' => [AggregateFunction::MAX, 'orderItems', 'created_at', 'order_items_max_created_at'];
        yield 'camel-case column lowered first' => [AggregateFunction::SUM, 'orders', 'unitPrice', 'orders_sum_unitprice'];
        yield 'punctuation stripped' => [AggregateFunction::SUM, 'orders', 'line.total', 'orders_sum_linetotal'];
    }

    /**
     * Test that the default key applies Laravel's own alias rule.
     *
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string  $relation
     * @param  string|null  $column
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('defaultKeyProvider')]
    public function testDefaultKeyAppliesLaravelsAliasRule(AggregateFunction $function, string $relation, ?string $column, string $expected): void
    {
        self::assertSame($expected, $function->defaultKey($relation, $column));
    }
}
