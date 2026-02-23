<?php

namespace Tests\Unit\Exceptions;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;

/**
 * Tests for the ApiException abstract class.
 *
 * Uses BadRequestException as the concrete implementation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiException::class)]
class ApiExceptionTest extends TestCase
{
    /**
     * Test that the constructor sets the code from the HTTP status.
     *
     * @return void
     */
    public function testConstructorSetsCodeFromHttpStatus(): void
    {
        $exception = new BadRequestException;

        static::assertSame(400, $exception->getCode());
    }

    /**
     * Test that the constructor sets the message (from getCustomDetail).
     *
     * @return void
     */
    public function testConstructorSetsMessage(): void
    {
        $exception = new BadRequestException;

        static::assertIsString($exception->getMessage());
        static::assertNotEmpty($exception->getMessage());
    }

    /**
     * Test that getCustomDetail returns a string.
     *
     * @return void
     */
    public function testGetCustomDetailReturnsString(): void
    {
        $exception = new BadRequestException;

        static::assertIsString($exception->getCustomDetail());
        static::assertNotEmpty($exception->getCustomDetail());
    }

    /**
     * Test that getCustomTitle returns a string.
     *
     * @return void
     */
    public function testGetCustomTitleReturnsString(): void
    {
        $exception = new BadRequestException;

        static::assertIsString($exception->getCustomTitle());
        static::assertNotEmpty($exception->getCustomTitle());
    }

    /**
     * Test that getHttpStatusCode returns the correct HTTP code.
     *
     * @return void
     */
    public function testGetHttpStatusCodeReturnsCorrectCode(): void
    {
        static::assertSame(400, BadRequestException::getHttpStatusCode());
    }

    /**
     * Test that getInternalErrorCode returns the correct internal code.
     *
     * @return void
     */
    public function testGetInternalErrorCodeReturnsCorrectCode(): void
    {
        static::assertSame(10100, BadRequestException::getInternalErrorCode());
    }

    /**
     * Test that getCustomMeta returns meta when provided.
     *
     * @return void
     */
    public function testGetCustomMetaReturnsMetaWhenProvided(): void
    {
        $meta      = ['field' => 'The field is required.'];
        $exception = new BadRequestException($meta);

        static::assertSame($meta, $exception->getCustomMeta());
    }

    /**
     * Test that getCustomMeta returns null when no meta is provided.
     *
     * @return void
     */
    public function testGetCustomMetaReturnsNullWhenNotProvided(): void
    {
        $exception = new BadRequestException;

        static::assertNull($exception->getCustomMeta());
    }

    /**
     * Test that getHeaders returns headers when provided.
     *
     * @return void
     */
    public function testGetHeadersReturnsHeadersWhenProvided(): void
    {
        $headers   = ['X-Custom-Header' => 'value'];
        $exception = new BadRequestException(null, $headers);

        static::assertSame($headers, $exception->getHeaders());
    }

    /**
     * Test that getHeaders returns an empty array when no headers are provided.
     *
     * @return void
     */
    public function testGetHeadersReturnsEmptyArrayWhenNotProvided(): void
    {
        $exception = new BadRequestException;

        static::assertSame([], $exception->getHeaders());
    }

    /**
     * Test that getNamespace returns api-toolkit.
     *
     * @return void
     */
    public function testGetNamespaceReturnsApiToolkit(): void
    {
        $exception = new BadRequestException;

        $reflection = new \ReflectionMethod($exception, 'getNamespace');

        static::assertSame('api-toolkit', $reflection->invoke($exception));
    }

    /**
     * Test that the previous exception is passed through.
     *
     * @return void
     */
    public function testPreviousExceptionIsPassedThrough(): void
    {
        $previous  = new \RuntimeException('Original error');
        $exception = new BadRequestException(null, null, $previous);

        static::assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that getInternalErrorCode throws LogicException without CODE constant.
     *
     * @return void
     */
    public function testGetInternalErrorCodeWithoutCodeConstantThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The CODE constant must be defined on the exception');

        // Call static method directly on an anonymous class without instantiating
        $class = new class extends ApiException {
            public const HttpStatus HTTP_STATUS = HttpStatus::BAD_REQUEST;
        };

        $class::getInternalErrorCode();
    }

    /**
     * Test that getHttpStatusCode throws LogicException without HTTP_STATUS constant.
     *
     * @return void
     */
    public function testGetHttpStatusCodeWithoutHttpStatusConstantThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The HTTP_STATUS constant must be defined on the exception');

        // Call static method directly on an anonymous class without instantiating
        $class = new class extends ApiException {
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::BAD_REQUEST;
        };

        $class::getHttpStatusCode();
    }

    /**
     * Test that a custom exception with proper constants works.
     *
     * @return void
     */
    public function testCustomExceptionWithConstantsWorks(): void
    {
        $exception = new class extends ApiException {
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::NOT_FOUND;
            public const HttpStatus HTTP_STATUS                                   = HttpStatus::NOT_FOUND;
        };

        static::assertSame(10103, $exception::getInternalErrorCode());
        static::assertSame(404, $exception::getHttpStatusCode());
    }
}
