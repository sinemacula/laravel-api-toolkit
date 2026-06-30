<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\HttpException;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Tests for the generic HTTP exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(HttpException::class)]
final class HttpExceptionTest extends TestCase
{
    /**
     * Test that the exception extends ApiException.
     *
     * @return void
     */
    public function testExtendsApiException(): void
    {
        self::assertInstanceOf(ApiException::class, new HttpException(HttpStatus::CONFLICT));
    }

    /**
     * Test that getStatus returns the runtime status rather than the
     * HTTP_STATUS constant.
     *
     * @return void
     */
    public function testGetStatusReturnsRuntimeStatus(): void
    {
        $exception = new HttpException(HttpStatus::LOCKED);

        self::assertSame(HttpStatus::LOCKED, $exception->getStatus());
        self::assertSame(423, $exception->getCode());
    }

    /**
     * Test that getInternalErrorCode returns the generic HTTP error code.
     *
     * @return void
     */
    public function testGetInternalErrorCodeReturnsGenericHttpError(): void
    {
        self::assertSame(10113, HttpException::getInternalErrorCode());
    }

    /**
     * Test that the title is derived from the runtime status name.
     *
     * @return void
     */
    public function testCustomTitleDerivesFromRuntimeStatus(): void
    {
        $exception = new HttpException(HttpStatus::GONE);

        self::assertSame('Gone', $exception->getCustomTitle());
    }

    /**
     * Test that meta and headers are carried through the constructor.
     *
     * @return void
     */
    public function testMetaAndHeadersAreCarried(): void
    {
        $exception = new HttpException(HttpStatus::CONFLICT, ['field' => 'value'], ['Retry-After' => '60']);

        self::assertSame(['field' => 'value'], $exception->getCustomMeta());
        self::assertSame(['Retry-After' => '60'], $exception->getHeaders());
    }
}
