<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateAggregateColumns;
use Tests\Fixtures\Models\AggregateLine;
use Tests\Fixtures\Models\AggregateOwner;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateAggregateColumns validation rule.
 *
 * The column listings are stubbed so every possible answer runs on any engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateAggregateColumns::class)]
final class ValidateAggregateColumnsTest extends TestCase
{
    /** @var array<string, string> The related line columns mapped to their driver type */
    private const array LINE_COLUMNS = [
        'id'         => 'bigint',
        'total'      => 'decimal',
        'name'       => 'varchar',
        'placed_at'  => 'datetime',
        'price'      => 'numeric',
        'quantity'   => 'integer',
        'shipped_on' => 'date',
        'status'     => 'varchar',
        'platform'   => 'varchar',
        'secret'     => 'text',
        'payload'    => 'json',
        'flagged'    => 'boolean',
        'checksum'   => 'bytea',
        'code'       => 'varchar',
        'label'      => 'varchar',
        'reference'  => 'uuid',
        'opaque'     => 'geometry',
        'weight'     => 'double',
    ];

    /**
     * Test that a resource without aggregates never reads the introspector.
     *
     * @return void
     */
    public function testPassesOverAResourceWithoutAggregates(): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);

        $introspector->expects(self::never())->method('getColumns');
        $introspector->expects(self::never())->method('resolveRelation');

        self::assertSame([], (new ValidateAggregateColumns($introspector))->validate(UserResource::class, AggregateOwner::class, new CompiledSchema([])));
    }

    /**
     * Test that a resource bound to no model, or to a class that is not one,
     * proves nothing.
     *
     * @return void
     */
    public function testPassesOverAResourceWithoutAModel(): void
    {
        $schema = $this->schema($this->aggregate('lines_sum_totl', AggregateFunction::SUM, 'lines', 'totl'));

        self::assertSame([], $this->rule()->validate(UserResource::class, null, $schema));
        self::assertSame([], $this->rule()->validate(UserResource::class, \stdClass::class, $schema));
    }

    /**
     * Test that valid aggregates of every function pass.
     *
     * @return void
     */
    public function testAcceptsValidAggregates(): void
    {
        $schema = $this->schema(
            $this->aggregate('lines_count', AggregateFunction::COUNT, 'lines'),
            $this->aggregate('lines_exists', AggregateFunction::EXISTS, 'lines'),
            $this->aggregate('lines_sum_total', AggregateFunction::SUM, 'lines', 'total'),
            $this->aggregate('lines_avg_quantity', AggregateFunction::AVG, 'lines', 'quantity'),
            $this->aggregate('lines_min_price', AggregateFunction::MIN, 'lines', 'price'),
            $this->aggregate('lines_max_placed_at', AggregateFunction::MAX, 'lines', 'placed_at'),
            $this->aggregate('lines_max_shipped_on', AggregateFunction::MAX, 'lines', 'shipped_on'),
            $this->aggregate('lines_max_status', AggregateFunction::MAX, 'lines', 'status'),
            $this->aggregate('lines_min_name', AggregateFunction::MIN, 'lines', 'name'),
            $this->aggregate('lines_max_opaque', AggregateFunction::MAX, 'lines', 'opaque'),
            $this->aggregate('lines_max_weight', AggregateFunction::MAX, 'lines', 'weight'),
            $this->aggregate('post_lines_count', AggregateFunction::COUNT, 'postLines'),
            $this->aggregate('post_lines_max_total', AggregateFunction::MAX, 'postLines', 'total'),
        );

        self::assertSame([], $this->rule()->validate(UserResource::class, AggregateOwner::class, $schema));
    }

    /**
     * Test that a column missing from the related table is reported.
     *
     * @return void
     */
    public function testReportsAMisspeltColumn(): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('lines_sum_totl', AggregateFunction::SUM, 'lines', 'totl'),
        ));

        self::assertCount(1, $errors);
        self::assertSame(UserResource::class, $errors[0]->resourceClass);
        self::assertSame('lines_sum_totl', $errors[0]->fieldKey);
        self::assertSame('Aggregate column "totl" does not exist on table "aggregate_lines"', $errors[0]->defect);
    }

    /**
     * Test that an empty listing means the columns are unknown, so a column it
     * cannot confirm is not reported.
     *
     * @return void
     */
    public function testAnEmptyListingProvesNothing(): void
    {
        $errors = $this->rule([], [])->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('lines_sum_totl', AggregateFunction::SUM, 'lines', 'totl'),
        ));

        self::assertSame([], $errors);
    }

    /**
     * Provide aggregates whose column type the function cannot compute over.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\AggregateFunction, string, string}>
     */
    public static function deniedTypeProvider(): iterable
    {
        yield 'sum over a string' => [AggregateFunction::SUM, 'name', 'The sum aggregate cannot be computed over column "name" of type "varchar"'];
        yield 'average over a timestamp' => [AggregateFunction::AVG, 'placed_at', 'The avg aggregate cannot be computed over column "placed_at" of type "datetime"'];
        yield 'sum over a boolean' => [AggregateFunction::SUM, 'flagged', 'The sum aggregate cannot be computed over column "flagged" of type "boolean"'];
        yield 'sum over a uuid' => [AggregateFunction::SUM, 'reference', 'The sum aggregate cannot be computed over column "reference" of type "uuid"'];
        yield 'max over a boolean' => [
            AggregateFunction::MAX,
            'flagged',
            'The max aggregate cannot be computed over column "flagged" of type "boolean", so declare an Exists aggregate instead',
        ];
        yield 'min over binary' => [AggregateFunction::MIN, 'checksum', 'The min aggregate cannot be computed over column "checksum" of type "bytea"'];
    }

    /**
     * Test that a column type the function cannot compute over is reported.
     *
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string  $column
     * @param  string  $defect
     * @return void
     */
    #[DataProvider('deniedTypeProvider')]
    public function testReportsAColumnTypeTheFunctionCannotCompute(AggregateFunction $function, string $column, string $defect): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('metric', $function, 'lines', $column),
        ));

        self::assertSame([$defect], array_column($errors, 'defect'));
    }

    /**
     * Provide minimums and maximums the related model would not read back.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableExtremeProvider(): iterable
    {
        yield 'legacy accessor' => ['code', 'is read through an accessor'];
        yield 'attribute accessor' => ['label', 'is read through an accessor'];
        yield 'encrypted cast' => ['secret', 'is cast with "encrypted"'];
        yield 'pure enum cast' => ['platform', 'is cast with "Tests\Fixtures\Enums\Platform"'];
    }

    /**
     * Test that a maximum the related model would not read back is reported.
     *
     * @param  string  $column
     * @param  string  $cause
     * @return void
     */
    #[DataProvider('unreadableExtremeProvider')]
    public function testReportsAMaximumTheRelatedModelWouldNotReadBack(string $column, string $cause): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('metric', AggregateFunction::MAX, 'lines', $column),
        ));

        self::assertSame([sprintf(
            'The max aggregate over column "%s" %s on model "%s", which may not read the aggregated value back; transform() the aggregate instead',
            $column,
            $cause,
            AggregateLine::class,
        )], array_column($errors, 'defect'));
    }

    /**
     * Test that a sum is not read through the related model, so its accessors
     * and casts are not reported.
     *
     * @return void
     */
    public function testIgnoresTheRelatedReadPathOfASum(): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('lines_sum_code', AggregateFunction::SUM, 'lines', 'quantity'),
            $this->aggregate('lines_avg_price', AggregateFunction::AVG, 'lines', 'price'),
        ));

        self::assertSame([], $errors);
    }

    /**
     * Test that a minimum over a json column reports both its type and its
     * cast.
     *
     * @return void
     */
    public function testReportsTheTypeAndTheCastOfOneMinimum(): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('lines_min_payload', AggregateFunction::MIN, 'lines', 'payload'),
        ));

        self::assertSame([
            'The min aggregate cannot be computed over column "payload" of type "json"',
            sprintf(
                'The min aggregate over column "payload" is cast with "array" on model "%s", which may not read the aggregated value back; transform() the aggregate instead',
                AggregateLine::class,
            ),
        ], array_column($errors, 'defect'));
    }

    /**
     * Provide aggregate attributes the owner model claims.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function claimedAttributeProvider(): iterable
    {
        yield 'owner column' => ['column_claimed', 'a column'];
        yield 'owner cast' => ['cast_claimed', 'a cast'];
        yield 'owner setter' => ['setter_claimed', 'a setter'];
        yield 'owner attribute setter' => ['mutator_claimed', 'a setter'];
    }

    /**
     * Test that an attribute the owner claims is reported, even for a count.
     *
     * @param  string  $key
     * @param  string  $claim
     * @return void
     */
    #[DataProvider('claimedAttributeProvider')]
    public function testReportsAnAttributeTheOwnerClaims(string $key, string $claim): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate($key, AggregateFunction::COUNT, 'lines'),
        ));

        self::assertSame([sprintf(
            'Aggregate attribute "aggregate_%s" is also %s on model "%s", so the aggregate cannot be read back unchanged',
            $key,
            $claim,
            AggregateOwner::class,
        )], array_column($errors, 'defect'));
    }

    /**
     * Test that a MorphTo relation is reported and its column left unchecked.
     *
     * @return void
     */
    public function testReportsAMorphToRelation(): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('subject_max_missing', AggregateFunction::MAX, 'subject', 'missing'),
        ));

        self::assertSame(
            ['Aggregate relation "subject" is a MorphTo, whose related model is unknown until each row is read'],
            array_column($errors, 'defect'),
        );
    }

    /**
     * Test that an unresolvable relation is left to the relation-method rule,
     * while the owner's claim on its attribute is still reported.
     *
     * @return void
     */
    public function testLeavesAnUnresolvableRelationToTheRelationRule(): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('cast_claimed', AggregateFunction::SUM, 'missing', 'missing'),
            $this->aggregate('unclaimed', AggregateFunction::SUM, 'missing', 'missing'),
        ));

        self::assertSame(['cast_claimed'], array_column($errors, 'fieldKey'));
    }

    /**
     * Test that every defective aggregate is reported under its own key.
     *
     * @return void
     */
    public function testReportsEveryDefectiveAggregate(): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('first', AggregateFunction::SUM, 'lines', 'totl'),
            $this->aggregate('valid', AggregateFunction::SUM, 'lines', 'total'),
            $this->aggregate('second', AggregateFunction::MAX, 'lines', 'flagged'),
        ));

        self::assertSame(['first', 'second'], array_column($errors, 'fieldKey'));
    }

    /**
     * Test that the owner's claim on the attribute is reported alongside the
     * relation's own defect.
     *
     * @return void
     */
    public function testReportsTheAttributeAndRelationDefectsTogether(): void
    {
        $errors = $this->rule()->validate(UserResource::class, AggregateOwner::class, $this->schema(
            $this->aggregate('cast_claimed', AggregateFunction::MAX, 'subject', 'total'),
            $this->aggregate('column_claimed', AggregateFunction::SUM, 'lines', 'totl'),
        ));

        $claimed = 'Aggregate attribute "aggregate_%s" is also a %s on model "%s", so the aggregate cannot be read back unchanged';

        self::assertSame([
            ['cast_claimed', sprintf($claimed, 'cast_claimed', 'cast', AggregateOwner::class)],
            ['cast_claimed', 'Aggregate relation "subject" is a MorphTo, whose related model is unknown until each row is read'],
            ['column_claimed', sprintf($claimed, 'column_claimed', 'column', AggregateOwner::class)],
            ['column_claimed', 'Aggregate column "totl" does not exist on table "aggregate_lines"'],
        ], array_map(static fn ($error): array => [$error->fieldKey, $error->defect], $errors));
    }

    /**
     * Build the rule over stubbed listings for the owner and the related line.
     *
     * @param  array<int, string>|null  $lineColumns
     * @param  array<string, string>|null  $lineTypes
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateAggregateColumns
     */
    private function rule(?array $lineColumns = null, ?array $lineTypes = null): ValidateAggregateColumns
    {
        $lineTypes   ??= self::LINE_COLUMNS;
        $lineColumns ??= array_keys($lineTypes);

        $definitions = [];

        foreach ($lineTypes as $name => $type) {
            $definitions[$name] = new ColumnDefinition($name, $type, true);
        }

        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('getColumns')->willReturnCallback(
            static fn (Model $model): array => $model instanceof AggregateOwner ? ['id', 'aggregate_column_claimed'] : $lineColumns,
        );

        $introspector->method('getColumnDefinitions')->willReturnCallback(
            static fn (Model $model): array => $model instanceof AggregateLine ? $definitions : [],
        );

        $introspector->method('resolveRelation')->willReturnCallback(
            static function (string $key, Model $model): ?Relation {
                if (!method_exists($model, $key)) {
                    return null;
                }

                $relation = $model->{$key}(); // @phpstan-ignore method.dynamicName

                return $relation instanceof Relation ? $relation : null;
            },
        );

        return new ValidateAggregateColumns($introspector);
    }

    /**
     * Build a compiled schema holding the given aggregates by present key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  ...$aggregates
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function schema(CompiledAggregateDefinition ...$aggregates): CompiledSchema
    {
        $keyed = [];

        foreach ($aggregates as $aggregate) {
            $keyed[$aggregate->presentKey] = $aggregate;
        }

        return new CompiledSchema([], $keyed);
    }

    /**
     * Build a compiled aggregate definition.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string  $relation
     * @param  string|null  $column
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private function aggregate(string $key, AggregateFunction $function, string $relation, ?string $column = null): CompiledAggregateDefinition
    {
        return new CompiledAggregateDefinition(
            presentKey: $key,
            relation: $relation,
            function: $function,
            column: $column,
            constraint: null,
            isDefault: false,
            guards: [],
        );
    }
}
