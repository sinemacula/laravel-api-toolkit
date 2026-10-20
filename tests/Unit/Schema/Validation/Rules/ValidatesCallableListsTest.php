<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\CallableList;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatesCallableLists;

/**
 * Tests for the ValidatesCallableLists base validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidatesCallableLists::class)]
final class ValidatesCallableListsTest extends TestCase
{
    /**
     * Test that a field whose callable list is fully callable produces no
     * errors.
     *
     * @return void
     */
    public function testNoErrorsWhenEveryEntryIsCallable(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [fn ($value, $model) => true],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema));
    }

    /**
     * Test that a non-callable entry is reported under the selected list's
     * label.
     *
     * @return void
     */
    public function testReportsNonCallableEntry(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test that a null field definition present under a declared key is skipped
     * without error.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testSkipsNullFieldDefinition(): void
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null]);
        $reflection->getProperty('counts')->setValue($schema, []);
        $reflection->getProperty('aggregates')->setValue($schema, []);

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema));
    }

    /**
     * Test that a null field definition does not halt iteration, so a later
     * field's non-callable entry is still reported.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testContinuesPastNullFieldDefinition(): void
    {
        $bad = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null, 'name' => $bad]);
        $reflection->getProperty('counts')->setValue($schema, []);
        $reflection->getProperty('aggregates')->setValue($schema, []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
    }

    /**
     * Test that errors from every field are accumulated rather than only the
     * final field's errors surviving.
     *
     * @return void
     */
    public function testAccumulatesErrorsAcrossFields(): void
    {
        $first = new CompiledFieldDefinition(
            accessor: 'first',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $second = new CompiledFieldDefinition(
            accessor: 'second',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['also_not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['first' => $first, 'second' => $second], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('first', $errors[0]->fieldKey);
        self::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Test that every non-callable entry in a single field's list is reported,
     * so the collected errors accumulate rather than only the first surviving.
     *
     * @return void
     */
    public function testReportsEveryNonCallableEntryInAList(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function', 'also_not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
        self::assertSame('Guard at index 1 is not callable', $errors[1]->defect);
    }

    /**
     * Test that a callable entry does not halt the list scan, so a following
     * non-callable entry is still reported at its true index.
     *
     * @return void
     */
    public function testContinuesPastCallableEntryInList(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [fn ($value, $model) => true, 'not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Guard at index 1 is not callable', $errors[0]->defect);
    }

    /**
     * Test that count and aggregate definitions are validated after the fields,
     * accumulating every error in field, count, then aggregate order.
     *
     * @return void
     */
    public function testAccumulatesCountAndAggregateErrorsAfterFieldErrors(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [
                'comments_count' => $this->makeCount('comments_count', ['not_a_function']),
                'likes_count'    => $this->makeCount('likes_count', [fn ($value, $model) => true, 'not_a_function']),
            ],
            aggregates: [
                'sum:reviews_rating' => $this->makeAggregate('reviews_rating', ['not_a_function']),
                'sum:orders_total'   => $this->makeAggregate('orders_total', ['not_a_function']),
            ],
        );

        $errors = $this->makeRule()->validate('App\Http\Resources\PostResource', null, $schema);

        self::assertSame(
            ['name', 'comments_count', 'likes_count', 'reviews_rating', 'orders_total'],
            array_map(static fn ($error) => $error->fieldKey, $errors),
        );
        self::assertSame(
            [
                'Guard at index 0 is not callable',
                'Guard at index 0 is not callable',
                'Guard at index 1 is not callable',
                'Guard at index 0 is not callable',
                'Guard at index 0 is not callable',
            ],
            array_map(static fn ($error) => $error->defect, $errors),
        );
        self::assertSame(['App\Http\Resources\PostResource'], array_values(array_unique(array_map(static fn ($error) => $error->resourceClass, $errors))));
    }

    /**
     * Test that count and aggregate definitions are validated against the list
     * the concrete rule selects, so the other list is never reported.
     *
     * @return void
     */
    public function testValidatesCountAndAggregateListsSelectedByHook(): void
    {
        $count = new CompiledCountDefinition(
            presentKey: 'comments_count',
            relation: 'comments',
            constraint: null,
            isDefault: false,
            guards: [],
            transformers: ['not_a_function'],
        );

        $aggregate = new CompiledAggregateDefinition(
            presentKey: 'reviews_rating',
            relation: 'reviews',
            column: 'rating',
            metric: 'sum',
            constraint: null,
            isDefault: false,
            guards: [],
            transformers: ['not_a_function'],
        );

        $schema = new CompiledSchema(
            fields: [],
            counts: ['comments_count' => $count],
            aggregates: ['sum:reviews_rating' => $aggregate],
        );

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\PostResource', null, $schema));
    }

    /**
     * Test that a rule selecting the transformer list reports every
     * non-callable transformer on fields, counts and aggregates under the
     * transformer label.
     *
     * @return void
     */
    public function testReportsTransformerDefectsAcrossDefinitionsWhenTransformersSelected(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: ['not_a_function'],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [
                'comments_count' => $this->makeCount('comments_count', [], [fn ($value, $model) => $value, 'not_a_function']),
            ],
            aggregates: [
                'sum:reviews_rating' => $this->makeAggregate('reviews_rating', [], ['not_a_function']),
            ],
        );

        $errors = $this->makeRule(CallableList::TRANSFORMERS)->validate('App\Http\Resources\PostResource', null, $schema);

        self::assertSame(
            ['name', 'comments_count', 'reviews_rating'],
            array_map(static fn ($error) => $error->fieldKey, $errors),
        );
        self::assertSame(
            [
                'Transformer at index 0 is not callable',
                'Transformer at index 1 is not callable',
                'Transformer at index 0 is not callable',
            ],
            array_map(static fn ($error) => $error->defect, $errors),
        );
    }

    /**
     * Build a compiled count definition with the given callable lists.
     *
     * @param  string  $presentKey
     * @param  array<int, mixed>  $guards
     * @param  array<int, mixed>  $transformers
     * @return \SineMacula\ApiToolkit\Schema\CompiledCountDefinition
     */
    private function makeCount(string $presentKey, array $guards, array $transformers = []): CompiledCountDefinition
    {
        return new CompiledCountDefinition(presentKey: $presentKey, relation: 'comments', constraint: null, isDefault: false, guards: $guards, transformers: $transformers);
    }

    /**
     * Build a compiled sum aggregate definition with the given callable lists.
     *
     * @param  string  $presentKey
     * @param  array<int, mixed>  $guards
     * @param  array<int, mixed>  $transformers
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private function makeAggregate(string $presentKey, array $guards, array $transformers = []): CompiledAggregateDefinition
    {
        return new CompiledAggregateDefinition(
            presentKey: $presentKey,
            relation: 'reviews',
            column: 'rating',
            metric: 'sum',
            constraint: null,
            isDefault: false,
            guards: $guards,
            transformers: $transformers,
        );
    }

    /**
     * Build a concrete rule that validates the given callable list on each
     * definition.
     *
     * @param  \SineMacula\ApiToolkit\Enums\CallableList  $list
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatesCallableLists
     */
    private function makeRule(CallableList $list = CallableList::GUARDS): ValidatesCallableLists
    {
        return new class ($list) extends ValidatesCallableLists {
            /**
             * Create a new rule validating the given callable list.
             *
             * @param  \SineMacula\ApiToolkit\Enums\CallableList  $list
             */
            public function __construct(

                /** The callable list this rule validates */
                private readonly CallableList $list,
            ) {}

            /**
             * Return the callable list this rule validates.
             *
             * @return \SineMacula\ApiToolkit\Enums\CallableList
             */
            #[\Override]
            protected function getCallableList(): CallableList
            {
                return $this->list;
            }
        };
    }
}
