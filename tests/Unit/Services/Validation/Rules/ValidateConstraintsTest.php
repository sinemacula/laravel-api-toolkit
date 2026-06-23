<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateConstraints;

/**
 * Tests for the ValidateConstraints validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 * @SuppressWarnings("php:S3011")
 *
 * @internal
 */
#[CoversClass(ValidateConstraints::class)]
final class ValidateConstraintsTest extends TestCase
{
    /**
     * Test that no errors are returned for Closure constraints on fields and
     * count definitions.
     *
     * @return void
     */
    public function testNoErrorsForClosureConstraints(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: 'App\Http\Resources\OrganizationResource',
                    fields: null,
                    constraint: fn () => true,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [
                'posts_count' => new CompiledCountDefinition(
                    presentKey: 'posts_count',
                    relation: 'posts',
                    constraint: fn () => true,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that an error is reported for a non-Closure constraint on a count
     * definition.
     *
     * @return void
     */
    public function testReportsNonClosureConstraintOnCountDefinition(): void
    {
        $schema = $this->createSchemaWithUntypedCount('posts_count', 'not-a-closure');

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('posts_count', $errors[0]->fieldKey);
        static::assertSame('Constraint must be a Closure', $errors[0]->defect);
    }

    /**
     * Test that fields and counts without constraints produce no errors.
     *
     * @return void
     */
    public function testSkipsNullConstraints(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'name' => new CompiledFieldDefinition(
                    accessor: 'name',
                    compute: null,
                    relation: null,
                    resource: null,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [
                'posts_count' => new CompiledCountDefinition(
                    presentKey: 'posts_count',
                    relation: 'posts',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that null field definitions are skipped without errors.
     *
     * @return void
     */
    public function testSkipsNullFieldDefinitions(): void
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null]);
        $reflection->getProperty('counts')->setValue($schema, []);

        $rule   = new ValidateConstraints;
        $errors = [];

        $warnings = $this->captureWarnings(fn () => $rule->validate('App\Http\Resources\UserResource', null, $schema), $errors);

        static::assertSame([], $warnings);
        static::assertSame([], $errors);
    }

    /**
     * Test that validation continues past counts without constraints and still
     * reports later defects.
     *
     * @return void
     */
    public function testContinuesPastCountsWithoutConstraints(): void
    {
        $schema = $this->createSchemaWithUntypedCounts([
            'clean_count' => new CompiledCountDefinition(
                presentKey: 'clean_count',
                relation: 'posts',
                constraint: null,
                isDefault: false,
                guards: [],
            ),
            'bad_count' => $this->makeUntypedCount('bad_count', 'not-a-closure'),
        ]);

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('bad_count', $errors[0]->fieldKey);
    }

    /**
     * Test that every non-Closure count constraint is reported.
     *
     * @return void
     */
    public function testReportsAllNonClosureCountConstraints(): void
    {
        $schema = $this->createSchemaWithUntypedCounts([
            'first_count'  => $this->makeUntypedCount('first_count', 'not-a-closure'),
            'second_count' => $this->makeUntypedCount('second_count', 'also-not-a-closure'),
        ]);

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(2, $errors);
        static::assertSame('first_count', $errors[0]->fieldKey);
        static::assertSame('second_count', $errors[1]->fieldKey);
    }

    /**
     * Run the given callback while capturing any raised PHP warnings.
     *
     * @param  callable(): array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>  $callback
     * @param  array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>  $errors
     * @return array<int, string>
     */
    private function captureWarnings(callable $callback, array &$errors): array
    {
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $errors = $callback();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    /**
     * Create a CompiledSchema containing a single count definition with an
     * untyped constraint value, bypassing the ?\Closure type enforcement via
     * reflection.
     *
     * @param  string  $presentKey
     * @param  mixed  $constraint
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function createSchemaWithUntypedCount(string $presentKey, mixed $constraint): CompiledSchema
    {
        return $this->createSchemaWithUntypedCounts([
            $presentKey => $this->makeUntypedCount($presentKey, $constraint),
        ]);
    }

    /**
     * Create a CompiledSchema containing the given raw count definitions,
     * bypassing the element type enforcement via reflection.
     *
     * @param  array<string, mixed>  $counts
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function createSchemaWithUntypedCounts(array $counts): CompiledSchema
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, []);
        $reflection->getProperty('counts')->setValue($schema, $counts);

        return $schema;
    }

    /**
     * Create a fake count definition carrying an untyped constraint value.
     *
     * @param  string  $presentKey
     * @param  mixed  $constraint
     * @return \stdClass
     */
    private function makeUntypedCount(string $presentKey, mixed $constraint): \stdClass
    {
        $fakeCount             = new \stdClass;
        $fakeCount->presentKey = $presentKey;
        $fakeCount->constraint = $constraint;

        return $fakeCount;
    }
}
