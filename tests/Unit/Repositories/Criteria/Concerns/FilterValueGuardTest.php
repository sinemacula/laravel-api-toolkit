<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterValueGuard;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Fixtures\Enums\CastablePlatform;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;
use Tests\TestCase;

/**
 * Tests for the FilterValueGuard concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(FilterValueGuard::class)]
final class FilterValueGuardTest extends TestCase
{
    use LocalisesToolkitMessages;

    /** @var string The position every rejection in these tests is keyed by */
    private const string POSITION = 'filters.$or.0.value.$op';

    /** @var array<string, string> The catalogue type of each column the guarded model has */
    private const array COLUMNS = [
        'count'    => 'integer',
        'label'    => 'varchar',
        'day'      => 'date',
        'at'       => 'timestamp',
        'token'    => 'uuid',
        'platform' => 'varchar',
        'status'   => 'varchar',
        'level'    => 'int4',
        'castable' => 'varchar',
        'amount'   => 'integer',
    ];

    /**
     * Provide values that fit their shape, with the value each resolves to.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\FilterValueShape, string, mixed, mixed}>
     */
    public static function fittingShapeProvider(): iterable
    {
        yield 'scalar' => [FilterValueShape::SCALAR, 'label', 'Alice', 'Alice'];
        yield 'falsy scalar' => [FilterValueShape::SCALAR, 'label', '0', '0'];
        yield 'list' => [FilterValueShape::LIST, 'count', [1, '2'], [1, '2']];
        yield 'empty list' => [FilterValueShape::LIST, 'count', [], []];
        yield 'bounds' => [FilterValueShape::BOUNDS, 'count', [1, 10], [1, 10]];
        yield 'inverted bounds' => [FilterValueShape::BOUNDS, 'count', [10, 1], [10, 1]];
        yield 'presence' => [FilterValueShape::PRESENCE, 'count', true, true];
        yield 'presence on a date' => [FilterValueShape::PRESENCE, 'day', true, true];
        yield 'presence on a uuid' => [FilterValueShape::PRESENCE, 'token', true, true];
        yield 'presence on an enum' => [FilterValueShape::PRESENCE, 'platform', true, true];
        yield 'scalar date-time' => [FilterValueShape::SCALAR, 'at', '2024-01-01T10:00:00+02:00', '2024-01-01 08:00:00'];
        yield 'list of date-times' => [FilterValueShape::LIST, 'at', ['2024-01-01', '2024-01-01T10:00Z'], ['2024-01-01 00:00:00', '2024-01-01 10:00:00']];
        yield 'bounds of date-times' => [FilterValueShape::BOUNDS, 'at', ['2024-01-01', '2024-01-02'], ['2024-01-01 00:00:00', '2024-01-02 00:00:00']];
        yield 'undescribed column' => [FilterValueShape::SCALAR, 'missing', true, true];
        yield 'element of any type on a typed column' => [FilterValueShape::ELEMENTS, 'count', 'abc', 'abc'];
        yield 'boolean element' => [FilterValueShape::ELEMENTS, 'label', false, false];
        yield 'float element on an integer column' => [FilterValueShape::ELEMENTS, 'count', 1.5, 1.5];
        yield 'list of elements' => [FilterValueShape::ELEMENTS, 'label', ['php', 2, true], ['php', 2, true]];
    }

    /**
     * Test that a value fitting its shape resolves to the value it binds.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FilterValueShape  $shape
     * @param  string  $column
     * @param  mixed  $value
     * @param  mixed  $expected
     * @return void
     */
    #[DataProvider('fittingShapeProvider')]
    public function testValueFittingItsShapeResolvesToTheValueItBinds(FilterValueShape $shape, string $column, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, $this->resolve($shape, $column, $value));
    }

    /**
     * Provide values outside their shape, with the message each is refused
     * with.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\FilterValueShape, string, mixed, string}>
     */
    public static function refusedValueProvider(): iterable
    {
        $scalar   = 'a single value other than null';
        $nullable = 'cannot be null; $null matches a missing value and $notNull a present one.';
        $null     = 'The $op condition on "label" ' . $nullable;
        $bounds   = 'The $op condition on "count" must be a list of two values, [low, high].';
        $presence = 'The $op condition on "count" takes only true; $null matches a missing value and $notNull a present one.';
        $elements = 'The $op condition on "label" must be a single value or a non-empty list of values.';

        yield 'null scalar' => [FilterValueShape::SCALAR, 'label', null, $null];
        yield 'null on an undescribed column' => [FilterValueShape::SCALAR, 'missing', null, 'The $op condition on "missing" ' . $nullable];
        yield 'list scalar' => [FilterValueShape::SCALAR, 'label', ['a', 'b'], 'The $op condition on "label" must be a single value.'];
        yield 'object scalar' => [FilterValueShape::SCALAR, 'label', ['x' => 1], 'The $op condition on "label" must be a single value.'];
        yield 'scalar list' => [FilterValueShape::LIST, 'count', 1, 'The $op condition on "count" must be a list of values.'];
        yield 'object list' => [FilterValueShape::LIST, 'count', ['x' => 1], 'The $op condition on "count" must be a list of values.'];
        yield 'nested list' => [FilterValueShape::LIST, 'count', [[1, 2], [3]], 'Item 0 of the $op condition on "count" must be ' . $scalar . '.'];
        yield 'null item' => [FilterValueShape::LIST, 'count', [1, null], 'Item 1 of the $op condition on "count" must be ' . $scalar . '.'];
        yield 'one bound' => [FilterValueShape::BOUNDS, 'count', [1], $bounds];
        yield 'three bounds' => [FilterValueShape::BOUNDS, 'count', [1, 2, 3], $bounds];
        yield 'scalar bounds' => [FilterValueShape::BOUNDS, 'count', 1, $bounds];
        yield 'keyed bounds' => [FilterValueShape::BOUNDS, 'count', ['low' => 1, 'high' => 2], $bounds];
        yield 'nested bound' => [FilterValueShape::BOUNDS, 'count', [[1, 2], 3], 'Item 0 of the $op condition on "count" must be ' . $scalar . '.'];
        yield 'null bound' => [FilterValueShape::BOUNDS, 'count', [1, null], 'Item 1 of the $op condition on "count" must be ' . $scalar . '.'];
        yield 'false presence' => [FilterValueShape::PRESENCE, 'count', false, $presence];
        yield 'one presence' => [FilterValueShape::PRESENCE, 'count', 1, $presence];
        yield 'null presence' => [FilterValueShape::PRESENCE, 'count', null, $presence];
        yield 'mistyped scalar' => [FilterValueShape::SCALAR, 'count', 'abc', 'The $op condition on "count" must be an integer.'];
        yield 'mistyped item' => [FilterValueShape::LIST, 'count', [1, 'abc'], 'Item 1 of the $op condition on "count" must be an integer.'];
        yield 'mistyped bound' => [FilterValueShape::BOUNDS, 'day', ['2024-01-01', '2024-02-30'], 'Item 1 of the $op condition on "day" must be a date as YYYY-MM-DD.'];
        yield 'bool on a varchar' => [FilterValueShape::SCALAR, 'label', true, 'The $op condition on "label" must be a string or a number.'];
        yield 'NUL on a varchar' => [FilterValueShape::SCALAR, 'label', "a\0", 'The $op condition on "label" must be free of NUL characters.'];
        yield 'null elements' => [FilterValueShape::ELEMENTS, 'label', null, $null];
        yield 'empty elements' => [FilterValueShape::ELEMENTS, 'label', [], $elements];
        yield 'object elements' => [FilterValueShape::ELEMENTS, 'label', ['x' => 1], $elements];
        yield 'nested element' => [FilterValueShape::ELEMENTS, 'label', ['a', ['b']], 'Item 1 of the $op condition on "label" must be ' . $scalar . '.'];
        yield 'null element' => [FilterValueShape::ELEMENTS, 'label', [null], 'Item 0 of the $op condition on "label" must be ' . $scalar . '.'];
        yield 'NUL element' => [FilterValueShape::ELEMENTS, 'label', "a\0", 'The $op condition on "label" must be free of NUL characters.'];
        yield 'NUL item' => [FilterValueShape::ELEMENTS, 'label', ['a', "b\0"], 'Item 1 of the $op condition on "label" must be free of NUL characters.'];
        yield 'infinite element' => [FilterValueShape::ELEMENTS, 'label', INF, 'The $op condition on "label" must be a finite number.'];
        yield 'NaN item' => [FilterValueShape::ELEMENTS, 'label', [1, NAN], 'Item 1 of the $op condition on "label" must be a finite number.'];
    }

    /**
     * Test that a value outside its shape or its column's type is refused at
     * its position.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FilterValueShape  $shape
     * @param  string  $column
     * @param  mixed  $value
     * @param  string  $message
     * @return void
     */
    #[DataProvider('refusedValueProvider')]
    public function testValueOutsideItsShapeOrTypeIsRefusedAtItsPosition(FilterValueShape $shape, string $column, mixed $value, string $message): void
    {
        $this->assertRefused($shape, $column, $value, $message);
    }

    /**
     * Provide values for enum-cast columns, with the value each resolves to.
     *
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function fittingEnumValueProvider(): iterable
    {
        yield 'pure wire name' => ['platform', 'mobile_app', 'mobile_app'];
        yield 'string-backed value' => ['status', 'inactive', 'inactive'];
        yield 'int-backed int' => ['level', 2, 2];
        yield 'int-backed digit string' => ['level', '2', '2'];
        yield 'castable enum read by its caster' => ['castable', 'anything', 'anything'];
        yield 'cast other than an enum' => ['amount', '5', '5'];
    }

    /**
     * Test that a value naming a case of the column's enum is accepted.
     *
     * @param  string  $column
     * @param  mixed  $value
     * @param  mixed  $expected
     * @return void
     */
    #[DataProvider('fittingEnumValueProvider')]
    public function testValueNamingACaseOfTheColumnsEnumIsAccepted(string $column, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, $this->resolve(FilterValueShape::SCALAR, $column, $value));
    }

    /**
     * Provide values for enum-cast columns that name no case, with the message
     * each is refused with.
     *
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function refusedEnumValueProvider(): iterable
    {
        $pure   = 'The $op condition on "platform" must be one of web, mobile_app, desktop.';
        $string = 'The $op condition on "status" must be one of active, inactive, banned.';
        $int    = 'The $op condition on "level" must be one of 1, 2, 3.';

        yield 'pure case name' => ['platform', 'MOBILE_APP', $pure];
        yield 'pure unknown name' => ['platform', 'console', $pure];
        yield 'pure int' => ['platform', 1, $pure];
        yield 'string-backed case name' => ['status', 'ACTIVE', $string];
        yield 'string-backed int' => ['status', 1, $string];
        yield 'int-backed unknown int' => ['level', 4, $int];
        yield 'int-backed word' => ['level', 'two', $int];
        yield 'int-backed padded digit string' => ['level', '02', $int];
        yield 'int-backed signed digit string' => ['level', '+2', $int];
        yield 'int-backed digit string past int64' => ['level', '9223372036854775808', $int];
        yield 'int-backed float' => ['level', 2.0, $int];
        yield 'int-backed bool' => ['level', true, $int];
    }

    /**
     * Test that a value naming no case of the column's enum is refused with the
     * values the column accepts.
     *
     * @param  string  $column
     * @param  mixed  $value
     * @param  string  $message
     * @return void
     */
    #[DataProvider('refusedEnumValueProvider')]
    public function testValueNamingNoCaseOfTheColumnsEnumIsRefused(string $column, mixed $value, string $message): void
    {
        $this->assertRefused(FilterValueShape::SCALAR, $column, $value, $message);
    }

    /**
     * Test that a refusal is worded in the current locale.
     *
     * @return void
     */
    public function testRefusalIsWordedInTheCurrentLocale(): void
    {
        $this->localiseToolkitMessages([
            'filters.item_type'        => 'Element :index von :operator auf ":column" muss :expected sein.',
            'filters.expected.integer' => 'eine ganze Zahl',
        ]);

        $this->assertRefused(FilterValueShape::LIST, 'count', [1, 'x'], 'Element 1 von $op auf "count" muss eine ganze Zahl sein.');
    }

    /**
     * Resolve the value through a guard over the guarded model's catalogue.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FilterValueShape  $shape
     * @param  string  $column
     * @param  mixed  $value
     * @return mixed
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function resolve(FilterValueShape $shape, string $column, mixed $value): mixed
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn(array_map(
            static fn (string $type): ColumnDefinition => new ColumnDefinition('value', $type, false),
            self::COLUMNS,
        ));

        $model = new class extends Model {
            /**
             * Get the attributes that should be cast.
             *
             * @return array<string, string>
             */
            #[\Override]
            protected function casts(): array
            {
                return [
                    'platform' => Platform::class,
                    'status'   => UserStatus::class,
                    'level'    => UserLevel::class,
                    'castable' => CastablePlatform::class,
                    'amount'   => 'integer',
                ];
            }
        };

        return (new FilterValueGuard(new ColumnLiteral($introspector)))->resolve($model, $column, '$op', $shape, $value, self::POSITION);
    }

    /**
     * Assert that the value is refused at the position with exactly the
     * message.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FilterValueShape  $shape
     * @param  string  $column
     * @param  mixed  $value
     * @param  string  $message
     * @return void
     */
    private function assertRefused(FilterValueShape $shape, string $column, mixed $value, string $message): void
    {
        try {
            $this->resolve($shape, $column, $value);

            self::fail('The value must be refused.');
        } catch (ValidationException $exception) {
            self::assertSame([self::POSITION => [$message]], $exception->errors());
        }
    }
}
