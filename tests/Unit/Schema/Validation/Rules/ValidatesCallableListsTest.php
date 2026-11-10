<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\CallableList;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatesCallableLists;
use Tests\Concerns\CompilesRawSchemas;

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
    use CompilesRawSchemas;

    /**
     * Test that a field whose callable list is fully callable produces no
     * errors.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testNoErrorsWhenEveryEntryIsCallable(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'guards' => [fn ($value, $model) => true]],
        ]);

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema));
    }

    /**
     * Test that a non-callable entry is reported under the selected list's
     * label.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsNonCallableEntry(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'guards' => ['not_a_function']],
        ]);

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
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testContinuesPastNullFieldDefinition(): void
    {
        $bad = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'guards' => ['not_a_function']],
        ])->getField('name');

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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testAccumulatesErrorsAcrossFields(): void
    {
        $schema = $this->compileRawSchema([
            'first'  => ['accessor' => 'first', 'guards' => ['not_a_function']],
            'second' => ['accessor' => 'second', 'guards' => ['also_not_a_function']],
        ]);

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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsEveryNonCallableEntryInAList(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'guards' => ['not_a_function', 'also_not_a_function']],
        ]);

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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testContinuesPastCallableEntryInList(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'guards' => [fn ($value, $model) => true, 'not_a_function']],
        ]);

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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testAccumulatesCountAndAggregateErrorsAfterFieldErrors(): void
    {
        $schema = $this->compileRawSchema([
            'comments_count' => $this->countDefinition(['not_a_function']),
            'reviews_rating' => $this->aggregateDefinition(['not_a_function']),
            'likes_count'    => $this->countDefinition([fn ($value, $model) => true, 'not_a_function']),
            'orders_total'   => $this->aggregateDefinition(['not_a_function']),
            'name'           => ['accessor' => 'name', 'guards' => ['not_a_function']],
        ]);

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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testValidatesCountAndAggregateListsSelectedByHook(): void
    {
        $schema = $this->compileRawSchema([
            'comments_count' => $this->countDefinition([], ['not_a_function']),
            'reviews_rating' => $this->aggregateDefinition([], ['not_a_function']),
        ]);

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\PostResource', null, $schema));
    }

    /**
     * Test that a rule selecting the transformer list reports every
     * non-callable transformer on fields, counts and aggregates under the
     * transformer label.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsTransformerDefectsAcrossDefinitionsWhenTransformersSelected(): void
    {
        $schema = $this->compileRawSchema([
            'name'           => ['accessor' => 'name', 'transformers' => ['not_a_function']],
            'comments_count' => $this->countDefinition([], [fn ($value, $model) => $value, 'not_a_function']),
            'reviews_rating' => $this->aggregateDefinition([], ['not_a_function']),
        ]);

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
     * Build a raw count definition declaring the given callable lists.
     *
     * @param  array<int, mixed>  $guards
     * @param  array<int, mixed>  $transformers
     * @return array<string, mixed>
     */
    private function countDefinition(array $guards, array $transformers = []): array
    {
        return ['metric' => 'count', 'relation' => 'comments', 'guards' => $guards, 'transformers' => $transformers];
    }

    /**
     * Build a raw sum aggregate definition declaring the given callable lists.
     *
     * @param  array<int, mixed>  $guards
     * @param  array<int, mixed>  $transformers
     * @return array<string, mixed>
     */
    private function aggregateDefinition(array $guards, array $transformers = []): array
    {
        return ['metric' => 'sum', 'relation' => 'reviews', 'column' => 'rating', 'guards' => $guards, 'transformers' => $transformers];
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
