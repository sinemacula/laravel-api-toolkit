<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Validation\RawSchemaValidator;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Tests for the RawSchemaValidator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(RawSchemaValidator::class)]
final class RawSchemaValidatorTest extends TestCase
{
    /** @var string The resource class the validated schemas are reported against */
    private const string RESOURCE = 'App\Http\Resources\UserResource';

    /**
     * Test that a schema declaring Closure constraints and callable guards and
     * transformers passes.
     *
     * @return void
     */
    public function testAcceptsClosureConstraintsAndCallableLists(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid([
            'name'           => ['accessor' => 'name', 'guards' => [static fn (): bool => true], 'transformers' => ['trim']],
            'comments_count' => ['metric' => 'count', 'relation' => 'comments', 'constraint' => static function (): void {}],
            'email'          => ['accessor' => 'email', 'constraint' => null],
        ], self::RESOURCE);
    }

    /**
     * Test that every non-callable guard or transformer and every non-Closure
     * constraint is reported, each entry by its position in its list.
     *
     * @return void
     */
    public function testReportsEveryNonCallableEntryAndNonClosureConstraint(): void
    {
        $errors = $this->collectErrors([
            'name' => [
                'accessor' => 'name',
                'guards'   => ['first' => static fn (): bool => true, 'second' => 'not_a_function', 'third' => 'also_not_a_function'],
            ],
            'comments_count' => [
                'metric'       => 'count',
                'relation'     => 'comments',
                'transformers' => ['not_a_function'],
            ],
            'reviews_rating' => [
                'metric'     => 'sum',
                'relation'   => 'reviews',
                'column'     => 'rating',
                'constraint' => 'not-a-closure',
                'guards'     => ['not_a_function'],
            ],
        ]);

        self::assertSame([
            [self::RESOURCE, 'name', 'Guard at index 1 is not callable'],
            [self::RESOURCE, 'name', 'Guard at index 2 is not callable'],
            [self::RESOURCE, 'comments_count', 'Transformer at index 0 is not callable'],
            [self::RESOURCE, 'reviews_rating', 'Constraint must be a Closure'],
            [self::RESOURCE, 'reviews_rating', 'Guard at index 0 is not callable'],
        ], $errors);
    }

    /**
     * Test that guards or transformers declared as anything other than a list
     * are reported, while the other list is still checked.
     *
     * @return void
     */
    public function testReportsGuardsAndTransformersThatAreNotLists(): void
    {
        $errors = $this->collectErrors([
            'name' => [
                'accessor'     => 'name',
                'guards'       => static fn (): bool => true,
                'transformers' => ['not_a_function'],
            ],
            'email' => [
                'accessor'     => 'email',
                'transformers' => 'trim',
            ],
        ]);

        self::assertSame([
            [self::RESOURCE, 'name', 'Guards must be a list of callables'],
            [self::RESOURCE, 'name', 'Transformer at index 0 is not callable'],
            [self::RESOURCE, 'email', 'Transformers must be a list of callables'],
        ], $errors);
    }

    /**
     * Validate the given raw schema and return the reported errors as resource
     * class, field key and defect triples.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return list<array{string, string, string}>
     */
    private function collectErrors(array $rawSchema): array
    {
        try {
            RawSchemaValidator::assertValid($rawSchema, self::RESOURCE);
        } catch (InvalidSchemaException $exception) {
            return array_values(array_map(
                static fn (SchemaValidationError $error): array => [$error->resourceClass, $error->fieldKey, $error->defect],
                $exception->getErrors(),
            ));
        }

        self::fail('Expected InvalidSchemaException to be thrown.');
    }
}
