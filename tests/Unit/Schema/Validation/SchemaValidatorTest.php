<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
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
final class SchemaValidatorTest extends TestCase
{
    /**
     * Clear the schema compiler cache before each test.
     *
     * @return void
     */
    #[\Override]
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
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that validate passes with no errors when all rules return empty
     * arrays.
     *
     * @return void
     */
    public function testValidatePassesWithNoErrors(): void
    {
        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->expects(self::once())
            ->method('validate')
            ->willReturn([]);

        $validator = new SchemaValidator($rule);

        $validator->validate([User::class => UserResource::class]);

        self::assertTrue(true);
    }

    /**
     * Test that validate throws InvalidSchemaException when a rule returns
     * errors.
     *
     * @return void
     */
    public function testValidateThrowsInvalidSchemaExceptionOnErrors(): void
    {
        $error = new SchemaValidationError(UserResource::class, 'id', 'Test defect');

        $rule = self::createStub(SchemaValidationRule::class);

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

        $ruleA = self::createStub(SchemaValidationRule::class);

        $ruleA->method('validate')
            ->willReturn([$errorA]);

        $ruleB = self::createStub(SchemaValidationRule::class);

        $ruleB->method('validate')
            ->willReturn([$errorB]);

        $validator = new SchemaValidator($ruleA, $ruleB);

        try {
            $validator->validate([User::class => UserResource::class]);
            self::fail('Expected InvalidSchemaException was not thrown');
        } catch (InvalidSchemaException $exception) {
            self::assertCount(2, $exception->getErrors());
            self::assertSame($errorA, $exception->getErrors()[0]);
            self::assertSame($errorB, $exception->getErrors()[1]);
        }
    }

    /**
     * Test that validate with an empty resource map does not call any rules and
     * does not throw.
     *
     * @return void
     */
    public function testValidateWithEmptyResourceMap(): void
    {
        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->expects(self::never())
            ->method('validate');

        $validator = new SchemaValidator($rule);

        $validator->validate([]);

        self::assertTrue(true);
    }

    /**
     * Test that validate calls all rules for each resource in the map.
     *
     * @return void
     */
    public function testValidateCallsAllRulesForEachResource(): void
    {
        $ruleA = $this->createMock(SchemaValidationRule::class);

        $ruleA->expects(self::exactly(2))
            ->method('validate')
            ->willReturn([]);

        $ruleB = $this->createMock(SchemaValidationRule::class);

        $ruleB->expects(self::exactly(2))
            ->method('validate')
            ->willReturn([]);

        $validator = new SchemaValidator($ruleA, $ruleB);

        $validator->validate([
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ]);

        self::assertTrue(true);
    }

    /**
     * Test that validate with no rules passes without errors for a non-empty
     * resource map.
     *
     * @return void
     */
    public function testValidateWithNoRulesPassesWithoutErrors(): void
    {
        $validator = new SchemaValidator;

        $validator->validate([User::class => UserResource::class]);

        self::assertTrue(true);
    }

    /**
     * Test that errors from multiple resources are aggregated into a single
     * exception.
     *
     * @return void
     */
    public function testErrorsFromMultipleResourcesAreAggregated(): void
    {
        $userError = new SchemaValidationError(UserResource::class, 'id', 'User defect');
        $postError = new SchemaValidationError(PostResource::class, 'title', 'Post defect');

        $rule = self::createStub(SchemaValidationRule::class);

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
            self::fail('Expected InvalidSchemaException was not thrown');
        } catch (InvalidSchemaException $exception) {
            self::assertCount(2, $exception->getErrors());
            self::assertSame($userError, $exception->getErrors()[0]);
            self::assertSame($postError, $exception->getErrors()[1]);
        }
    }
}
