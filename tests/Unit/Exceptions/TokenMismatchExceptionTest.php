<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use Tests\TestCase;

/**
 * Tests for the TokenMismatchException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(TokenMismatchException::class)]
final class TokenMismatchExceptionTest extends TestCase
{
    /**
     * Test that the exception reports the token mismatch code with a 419, which
     * has no standard status case.
     *
     * @return void
     */
    public function testReportsTheTokenMismatchCodeWithA419(): void
    {
        $exception = new TokenMismatchException;

        self::assertSame(ErrorCode::TOKEN_MISMATCH->getCode(), $exception::getInternalErrorCode());
        self::assertSame(419, $exception->getStatusCode());
    }
}
