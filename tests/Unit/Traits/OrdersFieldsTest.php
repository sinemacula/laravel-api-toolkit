<?php

declare(strict_types = 1);

namespace Tests\Unit\Traits;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\OrdersFields;
use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
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
#[CoversTrait(OrdersFields::class)]
final class OrdersFieldsTest extends TestCase
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

        yield 'id keeps priority when a field name collides on its weight' => [
            [
                ''      => 'x',
                'id'    => 1,
                '_type' => 'users',
                'name'  => 'Alice',
            ],
            ['_type', 'id', '', 'name'],
        ];
    }

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

        self::assertSame($expectedKeyOrder, array_keys($result));
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

        yield 'requested field absent from data is skipped' => [
            ['name', 'missing', 'id'],
            [
                'id'   => 1,
                'name' => 'Alice',
            ],
            ['name', 'id'],
        ];

        yield 'absent field mid-list keeps later requested fields in order' => [
            ['name', 'missing', 'email'],
            [
                'id'    => 1,
                'email' => self::TEST_EMAIL,
                'name'  => 'Alice',
            ],
            ['name', 'email', 'id'],
        ];
    }

    /**
     * Test that orderByRequestedFields maintains the requested order.
     *
     * @param  array<int, string>  $requestedFields
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $expectedKeyOrder
     * @return void
     */
    #[DataProvider('requestedFieldsOrderingProvider')]
    public function testOrderByRequestedFieldsMaintainsRequestedOrder(array $requestedFields, array $data, array $expectedKeyOrder): void
    {
        $consumer = $this->createConsumer(FieldOrderingStrategy::BY_REQUESTED_FIELDS, $requestedFields);

        $result = $this->invokeMethod($consumer, 'orderByRequestedFields', $data);

        self::assertSame($expectedKeyOrder, array_keys($result));
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

        $defaultConsumer = $this->createConsumer(FieldOrderingStrategy::DEFAULT);
        $defaultResult   = $this->invokeMethod($defaultConsumer, 'orderResolvedFields', $data);

        self::assertSame('_type', array_key_first($defaultResult));

        $requestedConsumer = $this->createConsumer(
            FieldOrderingStrategy::BY_REQUESTED_FIELDS,
            ['name', 'email', 'id'],
        );
        $requestedResult = $this->invokeMethod($requestedConsumer, 'orderResolvedFields', $data);

        self::assertSame('name', array_key_first($requestedResult));
    }

    /**
     * Test that the ordering helpers remain callable from subclasses of a trait
     * consumer (protected visibility contract).
     *
     * @return void
     */
    public function testOrderingHelpersRemainProtectedForSubclasses(): void
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'visibility_probe';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [];
            }

            /**
             * Call orderResolvedFields from the subclass scope.
             *
             * @param  array<string, mixed>  $data
             * @return array<string, mixed>
             */
            public function exposeOrderResolvedFields(array $data): array
            {
                return $this->orderResolvedFields($data);
            }

            /**
             * Call orderByDefault from the subclass scope.
             *
             * @param  array<string, mixed>  $data
             * @return array<string, mixed>
             */
            public function exposeOrderByDefault(array $data): array
            {
                return $this->orderByDefault($data);
            }

            /**
             * Call orderByRequestedFields from the subclass scope.
             *
             * @param  array<string, mixed>  $data
             * @return array<string, mixed>
             */
            public function exposeOrderByRequestedFields(array $data): array
            {
                return $this->orderByRequestedFields($data);
            }
        };

        $data = [
            'id'    => 1,
            '_type' => 'visibility_probe',
            'name'  => 'Alice',
        ];

        self::assertSame(['_type', 'id', 'name'], array_keys($resource->exposeOrderResolvedFields($data)));
        self::assertSame(['_type', 'id', 'name'], array_keys($resource->exposeOrderByDefault($data)));
        self::assertSame($data, $resource->exposeOrderByRequestedFields($data));
    }

    /**
     * Create a test consumer class that uses the OrdersFields trait.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy  $strategy
     * @param  array<int, string>  $requestedFields
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
             * @param  \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy  $strategy
             * @param  array<int, string>  $requestedFields
             */
            public function __construct(FieldOrderingStrategy $strategy, array $requestedFields)
            {
                $this->fieldOrderingStrategy = $strategy;
                self::$staticFields          = $requestedFields;
            }

            /**
             * Resolve the fields for ordering.
             *
             * @return array<int, string>
             */
            #[\Override]
            public static function resolveFields(): array
            {
                return self::$staticFields;
            }
        };
    }
}
