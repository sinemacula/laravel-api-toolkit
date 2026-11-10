<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateTransformers;
use Tests\Concerns\CompilesRawSchemas;

/**
 * Tests for the ValidateTransformers validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateTransformers::class)]
final class ValidateTransformersTest extends TestCase
{
    use CompilesRawSchemas;

    /**
     * Test no errors for schema with callable transformers.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testNoErrorsForSchemaWithCallableTransformers(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'transformers' => [fn ($value, $model) => is_string($value) ? strtoupper($value) : $value]],
        ]);

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test reports non-callable transformer on field.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsNonCallableTransformerOnField(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name', 'transformers' => ['not_a_function']],
        ]);

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('App\Http\Resources\UserResource', $errors[0]->resourceClass);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Transformer at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports multiple non-callable transformers.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsMultipleNonCallableTransformers(): void
    {
        $schema = $this->compileRawSchema([
            'email' => ['accessor' => 'email', 'transformers' => ['invalid_one', 'invalid_two']],
        ]);

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('Transformer at index 0 is not callable', $errors[0]->defect);
        self::assertSame('Transformer at index 1 is not callable', $errors[1]->defect);
    }

    /**
     * Test reports non-callable transformer on count definition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsNonCallableTransformerOnCountDefinition(): void
    {
        $schema = $this->compileRawSchema([
            'comments_count' => ['metric' => 'count', 'relation' => 'comments', 'transformers' => ['not_a_function']],
        ]);

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\PostResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('comments_count', $errors[0]->fieldKey);
        self::assertSame('Transformer at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports non-callable transformer on aggregate definition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testReportsNonCallableTransformerOnAggregateDefinition(): void
    {
        $schema = $this->compileRawSchema([
            'reviews_rating' => ['metric' => 'sum', 'relation' => 'reviews', 'column' => 'rating', 'transformers' => ['not_a_function']],
        ]);

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\ProductResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('reviews_rating', $errors[0]->fieldKey);
        self::assertSame('Transformer at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test no errors for schema with no transformers.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function testNoErrorsForSchemaWithNoTransformers(): void
    {
        $schema = $this->compileRawSchema([
            'name' => ['accessor' => 'name'],
        ]);

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }
}
