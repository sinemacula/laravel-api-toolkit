<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Resources\Concerns\SchemaCompiler;
use SineMacula\ApiToolkit\Services\SchemaValidator;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the SchemaValidator orchestrator service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaValidator::class)]
class SchemaValidatorTest extends TestCase
{
    /**
     * Clear the schema compiler cache before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();
    }

    /**
     * Clear the schema compiler cache after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that validate passes with no errors when all rules return
     * empty arrays.
     *
     * @return void
     */
    public function testValidatePassesWithNoErrors(): void
    {
        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->expects(static::once())
            ->method('validate')
            ->willReturn([]);

        $validator = new SchemaValidator($rule);

        $validator->validate([User::class => UserResource::class]);

        static::assertTrue(true);
    }

    /**
     * Test that validate throws InvalidSchemaException when a rule
     * returns errors.
     *
     * @return void
     */
    public function testValidateThrowsInvalidSchemaExceptionOnErrors(): void
    {
        $error = new SchemaValidationError(UserResource::class, 'id', 'Test defect');

        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([$error]);

        $validator = new SchemaValidator($rule);

        $this->expectException(InvalidSchemaException::class);

        $validator->validate([User::class => UserResource::class]);
    }

    /**
     * Test that the exception contains all errors from multiple rules.
     *
     * @return void
     */
    public function testExceptionContainsAllErrors(): void
    {
        $errorA = new SchemaValidationError(UserResource::class, 'id', 'Defect A');
        $errorB = new SchemaValidationError(UserResource::class, 'name', 'Defect B');

        $ruleA = $this->createMock(SchemaValidationRule::class);

        $ruleA->method('validate')
            ->willReturn([$errorA]);

        $ruleB = $this->createMock(SchemaValidationRule::class);

        $ruleB->method('validate')
            ->willReturn([$errorB]);

        $validator = new SchemaValidator($ruleA, $ruleB);

        try {
            $validator->validate([User::class => UserResource::class]);
            static::fail('Expected InvalidSchemaException was not thrown');
        } catch (InvalidSchemaException $exception) {
            static::assertCount(2, $exception->getErrors());
            static::assertSame($errorA, $exception->getErrors()[0]);
            static::assertSame($errorB, $exception->getErrors()[1]);
        }
    }

    /**
     * Test that validate with an empty resource map does not call any
     * rules and does not throw.
     *
     * @return void
     */
    public function testValidateWithEmptyResourceMap(): void
    {
        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->expects(static::never())
            ->method('validate');

        $validator = new SchemaValidator($rule);

        $validator->validate([]);

        static::assertTrue(true);
    }

    /**
     * Test that validate calls all rules for each resource in the map.
     *
     * @return void
     */
    public function testValidateCallsAllRulesForEachResource(): void
    {
        $ruleA = $this->createMock(SchemaValidationRule::class);

        $ruleA->expects(static::exactly(2))
            ->method('validate')
            ->willReturn([]);

        $ruleB = $this->createMock(SchemaValidationRule::class);

        $ruleB->expects(static::exactly(2))
            ->method('validate')
            ->willReturn([]);

        $validator = new SchemaValidator($ruleA, $ruleB);

        $validator->validate([
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ]);

        static::assertTrue(true);
    }

    /**
     * Test that validate with no rules passes without errors for a
     * non-empty resource map.
     *
     * @return void
     */
    public function testValidateWithNoRulesPassesWithoutErrors(): void
    {
        $validator = new SchemaValidator;

        $validator->validate([User::class => UserResource::class]);

        static::assertTrue(true);
    }

    /**
     * Test that errors from multiple resources are aggregated into a
     * single exception.
     *
     * @return void
     */
    public function testErrorsFromMultipleResourcesAreAggregated(): void
    {
        $userError = new SchemaValidationError(UserResource::class, 'id', 'User defect');
        $postError = new SchemaValidationError(PostResource::class, 'title', 'Post defect');

        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturnCallback(function (string $resourceClass) use ($userError, $postError): array {

                if ($resourceClass === UserResource::class) {
                    return [$userError];
                }

                return [$postError];
            });

        $validator = new SchemaValidator($rule);

        try {
            $validator->validate([
                User::class => UserResource::class,
                Post::class => PostResource::class,
            ]);
            static::fail('Expected InvalidSchemaException was not thrown');
        } catch (InvalidSchemaException $exception) {
            static::assertCount(2, $exception->getErrors());
            static::assertSame($userError, $exception->getErrors()[0]);
            static::assertSame($postError, $exception->getErrors()[1]);
        }
    }
}
