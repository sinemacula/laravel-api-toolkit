<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\ApiToolkit\Enums\ErrorCode;

/**
 * Tests for the ErrorCode enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorCode::class)]
final class ErrorCodeTest extends TestCase
{
    /**
     * Provide all ErrorCode cases with their expected values.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\ErrorCode, int}>
     */
    public static function caseProvider(): iterable
    {
        yield 'UNHANDLED_ERROR' => [ErrorCode::UNHANDLED_ERROR, 10001];
        yield 'BAD_REQUEST' => [ErrorCode::BAD_REQUEST, 10100];
        yield 'UNAUTHENTICATED' => [ErrorCode::UNAUTHENTICATED, 10101];
        yield 'FORBIDDEN' => [ErrorCode::FORBIDDEN, 10102];
        yield 'NOT_FOUND' => [ErrorCode::NOT_FOUND, 10103];
        yield 'NOT_ALLOWED' => [ErrorCode::NOT_ALLOWED, 10104];
        yield 'TOKEN_MISMATCH' => [ErrorCode::TOKEN_MISMATCH, 10105];
        yield 'INVALID_INPUT' => [ErrorCode::INVALID_INPUT, 10106];
        yield 'TOO_MANY_REQUESTS' => [ErrorCode::TOO_MANY_REQUESTS, 10107];
        yield 'CONFLICT' => [ErrorCode::CONFLICT, 10108];
        yield 'GONE' => [ErrorCode::GONE, 10109];
        yield 'PAYLOAD_TOO_LARGE' => [ErrorCode::PAYLOAD_TOO_LARGE, 10110];
        yield 'LOCKED' => [ErrorCode::LOCKED, 10111];
        yield 'SERVICE_UNAVAILABLE' => [ErrorCode::SERVICE_UNAVAILABLE, 10112];
        yield 'HTTP_ERROR' => [ErrorCode::HTTP_ERROR, 10113];
        yield 'MAINTENANCE_MODE' => [ErrorCode::MAINTENANCE_MODE, 10200];
    }

    /**
     * Test that getCode returns the enum backing value.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ErrorCode  $case
     * @param  int  $expectedCode
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testGetCodeReturnsEnumValue(ErrorCode $case, int $expectedCode): void
    {
        self::assertSame($expectedCode, $case->getCode());
    }

    /**
     * Test that ErrorCode implements the ErrorCodeInterface.
     *
     * @return void
     */
    public function testImplementsErrorCodeInterface(): void
    {
        self::assertInstanceOf(ErrorCodeInterface::class, ErrorCode::UNHANDLED_ERROR);
    }

    /**
     * Test that all expected cases exist.
     *
     * @return void
     */
    public function testAllExpectedCasesExist(): void
    {
        $expectedCases = [
            'UNHANDLED_ERROR',
            'BAD_REQUEST',
            'UNAUTHENTICATED',
            'FORBIDDEN',
            'NOT_FOUND',
            'NOT_ALLOWED',
            'TOKEN_MISMATCH',
            'INVALID_INPUT',
            'TOO_MANY_REQUESTS',
            'CONFLICT',
            'GONE',
            'PAYLOAD_TOO_LARGE',
            'LOCKED',
            'SERVICE_UNAVAILABLE',
            'HTTP_ERROR',
            'MAINTENANCE_MODE',
        ];

        $actualNames = array_map(fn (ErrorCode $case) => $case->name, ErrorCode::cases());

        foreach ($expectedCases as $name) {
            self::assertContains($name, $actualNames, "Expected case '{$name}' not found");
        }

        self::assertCount(count($expectedCases), ErrorCode::cases());
    }
}
