<?php

namespace Tests\Unit\Enums\Traits;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Enums\Traits\ProvidesCode;

/**
 * Tests for the ProvidesCode trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ProvidesCode::class)]
class ProvidesCodeTest extends TestCase
{
    /**
     * Test that getCode returns the backing value for HttpStatus cases.
     *
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $case
     * @param  int  $expectedCode
     * @return void
     */
    #[DataProvider('httpStatusProvider')]
    public function testGetCodeReturnsBackingValueForHttpStatus(HttpStatus $case, int $expectedCode): void
    {
        static::assertSame($expectedCode, $case->getCode());
    }

    /**
     * Test that getCode returns the backing value for ErrorCode cases.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ErrorCode  $case
     * @param  int  $expectedCode
     * @return void
     */
    #[DataProvider('errorCodeProvider')]
    public function testGetCodeReturnsBackingValueForErrorCode(ErrorCode $case, int $expectedCode): void
    {
        static::assertSame($expectedCode, $case->getCode());
    }

    /**
     * Test that getCode return type is int.
     *
     * @return void
     */
    public function testGetCodeReturnsInt(): void
    {
        $result = HttpStatus::OK->getCode();

        static::assertIsInt($result);
    }

    /**
     * Provide HttpStatus cases for testing the trait.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\HttpStatus, int}>
     */
    public static function httpStatusProvider(): iterable
    {
        yield 'OK' => [HttpStatus::OK, 200];
        yield 'NOT_FOUND' => [HttpStatus::NOT_FOUND, 404];
        yield 'INTERNAL_SERVER_ERROR' => [HttpStatus::INTERNAL_SERVER_ERROR, 500];
    }

    /**
     * Provide ErrorCode cases for testing the trait.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\ErrorCode, int}>
     */
    public static function errorCodeProvider(): iterable
    {
        yield 'UNHANDLED_ERROR' => [ErrorCode::UNHANDLED_ERROR, 10001];
        yield 'BAD_REQUEST' => [ErrorCode::BAD_REQUEST, 10100];
        yield 'MAINTENANCE_MODE' => [ErrorCode::MAINTENANCE_MODE, 10200];
    }
}
