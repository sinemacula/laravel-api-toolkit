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

    /** @var string */
    private const string TEST_EMAIL = 'test@example.com';

    /** @var string */
    private const string DATE_JAN_01 = '2026-01-01';

    /** @var string */
    private const string DATE_JAN_02 = '2026-01-02';

    /**
     * Provide test cases for default field ordering.
     *
     * @return iterable<string, array{array<string, mixed>, array<int, string>}>
     */
    public static function defaultOrderingProvider(): iterable
    {
        yield 'type first, id second, timestamps last' => [
            [
                'email'      => self::TEST_EMAIL,
                'created_at' => self::DATE_JAN_01,
                'id'         => 1,
                '_type'      => 'users',
                'name'       => 'Alice',
                'updated_at' => self::DATE_JAN_02,
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
                'updated_at' => self::DATE_JAN_02,
                'deleted_at' => '2026-01-03',
                'created_at' => self::DATE_JAN_01,
            ],
            ['created_at', 'deleted_at', 'updated_at'],
        ];

        yield 'mixed fields with all categories' => [
            [
                'updated_at' => self::DATE_JAN_02,
                'name'       => 'Alice',
                '_type'      => 'users',
                'created_at' => self::DATE_JAN_01,
                'id'         => 1,
                'age'        => 25,
            ],
            ['_type', 'id', 'age', 'name', 'created_at', 'updated_at'],
        ];
    }

    /**
     * Test that orderByDefault puts _type first, id second, timestamps last,
     * and everything else alphabetized.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $expected_key_order
     * @return void
     */
    #[DataProvider('defaultOrderingProvider')]
    public function testOrderByDefaultOrdersFieldsCorrectly(array $input, array $expected_key_order): void
    {
        $consumer = $this->createConsumer(FieldOrderingStrategy::DEFAULT);

        $result = $this->invokeMethod($consumer, 'orderByDefault', $input);

        static::assertSame($expected_key_order, array_keys($result));
    }

    /**
     * Provide test cases for requested fields ordering.
     *
     * @return iterable<string, array{array<int, string>, array<string, mixed>, array<int, string>}>
     */
    public static function requestedFieldsOrderingProvider(): iterable
    {
        yield 'maintains requested order' => [
            ['name', 'email', 'id'],
            [
                'id'    => 1,
                'name'  => 'Alice',
                'email' => self::TEST_EMAIL,
            ],
            ['name', 'email', 'id'],
        ];

        yield 'unrequested fields appended at end' => [
            ['name'],
            [
                'id'    => 1,
                'name'  => 'Alice',
                'email' => self::TEST_EMAIL,
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
     * Test that orderByRequestedFields maintains the requested order.
     *
     * @param  array<int, string>  $requested_fields
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $expected_key_order
     * @return void
     */
    #[DataProvider('requestedFieldsOrderingProvider')]
    public function testOrderByRequestedFieldsMaintainsRequestedOrder(
        array $requested_fields,
        array $data,
        array $expected_key_order,
    ): void {
        $consumer = $this->createConsumer(FieldOrderingStrategy::BY_REQUESTED_FIELDS, $requested_fields);

        $result = $this->invokeMethod($consumer, 'orderByRequestedFields', $data);

        static::assertSame($expected_key_order, array_keys($result));
    }

    /**
     * Test that orderResolvedFields delegates based on strategy.
     *
     * @return void
     */
    public function testOrderResolvedFieldsDelegatesBasedOnStrategy(): void
    {
        $data = [
            'email'      => self::TEST_EMAIL,
            'id'         => 1,
            '_type'      => 'users',
            'name'       => 'Alice',
            'created_at' => self::DATE_JAN_01,
        ];

        $default_consumer = $this->createConsumer(FieldOrderingStrategy::DEFAULT);
        $default_result   = $this->invokeMethod($default_consumer, 'orderResolvedFields', $data);

        static::assertSame('_type', array_key_first($default_result));

        $requested_consumer = $this->createConsumer(
            FieldOrderingStrategy::BY_REQUESTED_FIELDS,
            ['name', 'email', 'id'],
        );
        $requested_result = $this->invokeMethod($requested_consumer, 'orderResolvedFields', $data);

        static::assertSame('name', array_key_first($requested_result));
    }

    /**
     * Create a test consumer class that uses the OrdersFields trait.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy  $strategy
     * @param  array<int, string>  $requested_fields
     * @return object
     */
    private function createConsumer(FieldOrderingStrategy $strategy, array $requested_fields = []): object
    {
        return new class ($strategy, $requested_fields) {
            use OrdersFields;

            /** @var array<int, string> */
            private static array $staticFields = [];

            /**
             * Create a new instance.
             *
             * @param  \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy  $strategy
             * @param  array<int, string>  $requested_fields
             */
            public function __construct(FieldOrderingStrategy $strategy, array $requested_fields)
            {
                $this->fieldOrderingStrategy = $strategy;
                self::$staticFields          = $requested_fields;
            }

            /**
             * Resolve the fields for ordering.
             *
             * @return array<int, string>
             */
            public static function resolveFields(): array
            {
                return self::$staticFields;
            }
        };
    }
}
