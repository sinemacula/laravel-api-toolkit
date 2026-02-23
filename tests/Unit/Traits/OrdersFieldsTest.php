<?php

namespace Tests\Unit\Traits;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;
use SineMacula\ApiToolkit\Traits\OrdersFields;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * Tests for the OrdersFields trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OrdersFields::class)]
class OrdersFieldsTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that orderByDefault puts _type first, id second, timestamps last,
     * and everything else alphabetized.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $expectedKeyOrder
     * @return void
     */
    #[DataProvider('defaultOrderingProvider')]
    public function testOrderByDefaultOrdersFieldsCorrectly(array $input, array $expectedKeyOrder): void
    {
        $consumer = $this->createConsumer(FieldOrderingStrategy::DEFAULT);

        $result = $this->invokeMethod($consumer, 'orderByDefault', $input);

        static::assertSame($expectedKeyOrder, array_keys($result));
    }

    /**
     * Provide test cases for default field ordering.
     *
     * @return iterable<string, array{array, array}>
     */
    public static function defaultOrderingProvider(): iterable
    {
        yield 'type first, id second, timestamps last' => [
            [
                'email'      => 'test@example.com',
                'created_at' => '2026-01-01',
                'id'         => 1,
                '_type'      => 'users',
                'name'       => 'Alice',
                'updated_at' => '2026-01-02',
            ],
            ['_type', 'id', 'email', 'name', 'created_at', 'updated_at'],
        ];

        yield 'only regular fields alphabetized' => [
            [
                'zebra' => 'z',
                'alpha' => 'a',
                'beta'  => 'b',
            ],
            ['alpha', 'beta', 'zebra'],
        ];

        yield 'type and id without other fields' => [
            [
                'id'    => 1,
                '_type' => 'items',
            ],
            ['_type', 'id'],
        ];

        yield 'timestamps sorted among themselves' => [
            [
                'updated_at' => '2026-01-02',
                'deleted_at' => '2026-01-03',
                'created_at' => '2026-01-01',
            ],
            ['created_at', 'deleted_at', 'updated_at'],
        ];

        yield 'mixed fields with all categories' => [
            [
                'updated_at' => '2026-01-02',
                'name'       => 'Alice',
                '_type'      => 'users',
                'created_at' => '2026-01-01',
                'id'         => 1,
                'age'        => 25,
            ],
            ['_type', 'id', 'age', 'name', 'created_at', 'updated_at'],
        ];
    }

    /**
     * Test that orderByRequestedFields maintains the requested order.
     *
     * @param  array  $requestedFields
     * @param  array  $data
     * @param  array  $expectedKeyOrder
     * @return void
     */
    #[DataProvider('requestedFieldsOrderingProvider')]
    public function testOrderByRequestedFieldsMaintainsRequestedOrder(array $requestedFields, array $data, array $expectedKeyOrder): void
    {
        $consumer = $this->createConsumer(FieldOrderingStrategy::BY_REQUESTED_FIELDS, $requestedFields);

        $result = $this->invokeMethod($consumer, 'orderByRequestedFields', $data);

        static::assertSame($expectedKeyOrder, array_keys($result));
    }

    /**
     * Provide test cases for requested fields ordering.
     *
     * @return iterable<string, array{array, array, array}>
     */
    public static function requestedFieldsOrderingProvider(): iterable
    {
        yield 'maintains requested order' => [
            ['name', 'email', 'id'],
            [
                'id'    => 1,
                'name'  => 'Alice',
                'email' => 'test@example.com',
            ],
            ['name', 'email', 'id'],
        ];

        yield 'unrequested fields appended at end' => [
            ['name'],
            [
                'id'    => 1,
                'name'  => 'Alice',
                'email' => 'test@example.com',
            ],
            ['name', 'id', 'email'],
        ];

        yield 'empty requested fields returns data unchanged' => [
            [],
            [
                'id'   => 1,
                'name' => 'Alice',
            ],
            ['id', 'name'],
        ];
    }

    /**
     * Test that orderResolvedFields delegates based on strategy.
     *
     * @return void
     */
    public function testOrderResolvedFieldsDelegatesBasedOnStrategy(): void
    {
        $data = [
            'email'      => 'test@example.com',
            'id'         => 1,
            '_type'      => 'users',
            'name'       => 'Alice',
            'created_at' => '2026-01-01',
        ];

        $defaultConsumer = $this->createConsumer(FieldOrderingStrategy::DEFAULT);
        $defaultResult   = $this->invokeMethod($defaultConsumer, 'orderResolvedFields', $data);

        static::assertSame('_type', array_key_first($defaultResult));

        $requestedConsumer = $this->createConsumer(
            FieldOrderingStrategy::BY_REQUESTED_FIELDS,
            ['name', 'email', 'id'],
        );
        $requestedResult = $this->invokeMethod($requestedConsumer, 'orderResolvedFields', $data);

        static::assertSame('name', array_key_first($requestedResult));
    }

    /**
     * Create a test consumer class that uses the OrdersFields trait.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy  $strategy
     * @param  array  $requestedFields
     * @return object
     */
    private function createConsumer(FieldOrderingStrategy $strategy, array $requestedFields = []): object
    {
        return new class ($strategy, $requestedFields) {
            use OrdersFields;

            /** @var array<int, string> */
            private static array $staticFields = [];

            /**
             * Create a new instance.
             *
             * @param  FieldOrderingStrategy  $strategy
             * @param  array  $requestedFields
             */
            public function __construct(
                FieldOrderingStrategy $strategy,
                array $requestedFields,
            ) {
                $this->fieldOrderingStrategy = $strategy;
                self::$staticFields          = $requestedFields;
            }

            /**
             * Resolve the fields for ordering.
             *
             * @return array
             */
            public static function resolveFields(): array
            {
                return self::$staticFields;
            }
        };
    }
}
