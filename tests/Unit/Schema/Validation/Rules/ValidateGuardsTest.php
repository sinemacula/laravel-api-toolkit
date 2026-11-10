<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateGuards;
use Tests\Concerns\CompilesRawSchemas;

/**
 * Tests for the ValidateGuards validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateGuards::class)]
final class ValidateGuardsTest extends TestCase
{
    use CompilesRawSchemas;

    /**
     * Test no errors for schema with callable guards.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testNoErrorsForSchemaWithCallableGuards(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'guards' => [fn ($value, $model) => true]],
        ]);

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test reports non-callable guard on field.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsNonCallableGuardOnField(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'guards' => ['not_a_function']],
        ]);

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('App\Http\Resources\UserResource', $errors[0]->resourceClass);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports non-callable guard on count definition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsNonCallableGuardOnCountDefinition(): void
    {
        $schema = $this->compileRawSchema([
            'comments_count' => ['metric' => 'count', 'relation' => 'comments', 'guards' => ['not_a_function']],
        ]);

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\PostResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('App\Http\Resources\PostResource', $errors[0]->resourceClass);
        self::assertSame('comments_count', $errors[0]->fieldKey);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports non-callable guard on aggregate definition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsNonCallableGuardOnAggregateDefinition(): void
    {
        $schema = $this->compileRawSchema([
            'reviews_rating' => ['metric' => 'sum', 'relation' => 'reviews', 'column' => 'rating', 'guards' => ['not_a_function']],
        ]);

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\ProductResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('App\Http\Resources\ProductResource', $errors[0]->resourceClass);
        self::assertSame('reviews_rating', $errors[0]->fieldKey);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports multiple non-callable guards.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsMultipleNonCallableGuards(): void
    {
        $schema = $this->compileRawSchema([
            'email' => ['accessor' => 'email', 'guards' => ['invalid_one', 'invalid_two']],
        ]);

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
        self::assertSame('Guard at index 1 is not callable', $errors[1]->defect);
    }

    /**
     * Test that field guard errors and count definition guard errors are both
     * accumulated rather than the count loop discarding earlier errors.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testAccumulatesFieldAndCountGuardErrors(): void
    {
        $schema = $this->compileRawSchema([
            'name'           => ['accessor' => 'name', 'guards' => ['not_a_function']],
            'comments_count' => ['metric' => 'count', 'relation' => 'comments', 'guards' => ['not_a_function']],
        ]);

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\PostResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('comments_count', $errors[1]->fieldKey);
    }

    /**
     * Test no errors for schema with no guards.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testNoErrorsForSchemaWithNoGuards(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name'],
        ]);

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }
}
