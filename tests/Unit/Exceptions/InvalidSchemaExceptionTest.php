<?php

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;

/**
 * Tests for the InvalidSchemaException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(InvalidSchemaException::class)]
class InvalidSchemaExceptionTest extends TestCase
{
    /**
     * Test that the constructor accepts errors.
     *
     * @return void
     */
    public function testConstructorAcceptsErrors(): void
    {
        $errors = [
            new SchemaValidationError('App\Http\Resources\UserResource', 'name', 'Missing accessor'),
        ];

        $exception = new InvalidSchemaException($errors);

        static::assertCount(1, $exception->getErrors());
    }

    /**
     * Test that getErrors returns all errors.
     *
     * @return void
     */
    public function testGetErrorsReturnsAllErrors(): void
    {
        $errors = [
            new SchemaValidationError('App\Http\Resources\UserResource', 'name', 'Missing accessor'),
            new SchemaValidationError('App\Http\Resources\PostResource', 'title', 'Guard is not callable'),
        ];

        $exception = new InvalidSchemaException($errors);

        static::assertSame($errors, $exception->getErrors());
    }

    /**
     * Test that getMessage contains all errors.
     *
     * @return void
     */
    public function testGetMessageContainsAllErrors(): void
    {
        $errors = [
            new SchemaValidationError('App\Http\Resources\UserResource', 'name', 'Missing accessor'),
            new SchemaValidationError('App\Http\Resources\PostResource', 'title', 'Guard is not callable'),
        ];

        $exception = new InvalidSchemaException($errors);
        $message   = $exception->getMessage();

        static::assertStringContainsString('[App\Http\Resources\UserResource] Field "name": Missing accessor', $message);
        static::assertStringContainsString('[App\Http\Resources\PostResource] Field "title": Guard is not callable', $message);
        static::assertStringContainsString('  - [App\Http\Resources\UserResource]', $message);
        static::assertStringContainsString('  - [App\Http\Resources\PostResource]', $message);
    }

    /**
     * Test that getMessage contains the error count.
     *
     * @return void
     */
    public function testGetMessageContainsErrorCount(): void
    {
        $errors = [
            new SchemaValidationError('App\Http\Resources\UserResource', 'name', 'Missing accessor'),
            new SchemaValidationError('App\Http\Resources\PostResource', 'title', 'Guard is not callable'),
            new SchemaValidationError('App\Http\Resources\PostResource', 'body', 'Invalid transformer'),
        ];

        $exception = new InvalidSchemaException($errors);

        static::assertStringStartsWith('Schema validation failed with 3 error(s):', $exception->getMessage());
    }

    /**
     * Test that the exception extends RuntimeException.
     *
     * @return void
     */
    public function testExceptionExtendsRuntimeException(): void
    {
        $exception = new InvalidSchemaException([]);

        static::assertInstanceOf(\RuntimeException::class, $exception);
    }
}
