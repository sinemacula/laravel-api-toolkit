<?php

declare(strict_types = 1);

namespace Tests\Unit;

use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;
use SineMacula\ApiToolkit\Traits\OrdersFields;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class OrdersFieldsTest extends TestCase
{
    public function testDefaultOrderingPlacesTypeAndIdFirstAndTimestampsLast(): void
    {
        OrderedFieldsHarness::$resolved = ['name', 'id'];

        $harness = new OrderedFieldsHarness;

        $ordered = $harness->order([
            'updated_at' => 'later',
            '_type'      => 'user',
            'name'       => 'Alice',
            'id'         => 5,
            'created_at' => 'earlier',
            'email'      => 'test@example.com',
        ]);

        static::assertSame(['_type', 'id', 'email', 'name', 'created_at', 'updated_at'], array_keys($ordered));
    }

    public function testRequestedFieldOrderingUsesResolvedFieldsAndFallsBackToOriginalData(): void
    {
        OrderedFieldsHarness::$resolved = ['email', 'name'];

        $harness = new OrderedFieldsHarness;
        $harness->setStrategy(FieldOrderingStrategy::BY_REQUESTED_FIELDS);

        $ordered = $harness->order([
            '_type'      => 'user',
            'id'         => 1,
            'name'       => 'Alice',
            'email'      => 'alice@example.com',
            'created_at' => 'x',
        ]);

        static::assertSame(['email', 'name', '_type', 'id', 'created_at'], array_keys($ordered));
    }

    public function testRequestedFieldOrderingReturnsOriginalDataWhenNoRequestedFieldsResolved(): void
    {
        OrderedFieldsHarness::$resolved = [];

        $harness = new OrderedFieldsHarness;
        $harness->setStrategy(FieldOrderingStrategy::BY_REQUESTED_FIELDS);

        $data = ['name' => 'Alice', 'id' => 1];

        static::assertSame($data, $harness->order($data));
    }
}

class OrderedFieldsHarness
{
    use OrdersFields;

    /** @var array<int, string> */
    public static array $resolved = [];

    public static function resolveFields(): array
    {
        return self::$resolved;
    }

    public function setStrategy(FieldOrderingStrategy $strategy): void
    {
        $this->fieldOrderingStrategy = $strategy;
    }

    public function order(array $data): array
    {
        return $this->orderResolvedFields($data);
    }
}
