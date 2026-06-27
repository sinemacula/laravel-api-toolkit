<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\LockedException;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Exceptions\NotAllowedException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\PayloadTooLargeException;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Exceptions\UnauthenticatedException;
use SineMacula\ApiToolkit\Exceptions\UnhandledException;

/**
 * Parametric tests covering all concrete ApiException subclasses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BadRequestException::class)]
#[CoversClass(ConflictException::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(GoneException::class)]
#[CoversClass(InvalidInputException::class)]
#[CoversClass(LockedException::class)]
#[CoversClass(MaintenanceModeException::class)]
#[CoversClass(NotAllowedException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(PayloadTooLargeException::class)]
#[CoversClass(ServiceUnavailableException::class)]
#[CoversClass(TokenMismatchException::class)]
#[CoversClass(TooManyRequestsException::class)]
#[CoversClass(UnauthenticatedException::class)]
#[CoversClass(UnhandledException::class)]
final class ConcreteExceptionsTest extends TestCase
{
    /**
     * Provide all concrete exception classes with their expected codes.
     *
     * @return iterable<string, array{class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>, int, int}>
     */
    public static function exceptionProvider(): iterable
    {
        yield 'BadRequestException' => [BadRequestException::class, 10100, 400];
        yield 'ConflictException' => [ConflictException::class, 10108, 409];
        yield 'ForbiddenException' => [ForbiddenException::class, 10102, 403];
        yield 'GoneException' => [GoneException::class, 10109, 410];
        yield 'InvalidInputException' => [InvalidInputException::class, 10106, 422];
        yield 'LockedException' => [LockedException::class, 10111, 423];
        yield 'MaintenanceModeException' => [MaintenanceModeException::class, 10200, 503];
        yield 'NotAllowedException' => [NotAllowedException::class, 10104, 405];
        yield 'NotFoundException' => [NotFoundException::class, 10103, 404];
        yield 'PayloadTooLargeException' => [PayloadTooLargeException::class, 10110, 413];
        yield 'ServiceUnavailableException' => [ServiceUnavailableException::class, 10112, 503];
        yield 'TokenMismatchException' => [TokenMismatchException::class, 10105, 419];
        yield 'TooManyRequestsException' => [TooManyRequestsException::class, 10107, 429];
        yield 'UnauthenticatedException' => [UnauthenticatedException::class, 10101, 401];
        yield 'UnhandledException' => [UnhandledException::class, 10001, 500];
    }

    /**
     * Test that the exception extends ApiException.
     *
     * @param  string  $class
     * @param  int  $expectedInternalCode
     * @param  int  $expectedHttpCode
     * @return void
     */
    #[DataProvider('exceptionProvider')]
    public function testExtendsApiException(string $class, int $expectedInternalCode, int $expectedHttpCode): void
    {
        $exception = new $class;

        self::assertInstanceOf(ApiException::class, $exception);
    }

    /**
     * Test that getInternalErrorCode returns the expected code.
     *
     * @param  string  $class
     * @param  int  $expectedInternalCode
     * @param  int  $expectedHttpCode
     * @return void
     */
    #[DataProvider('exceptionProvider')]
    public function testGetInternalErrorCodeReturnsExpectedCode(string $class, int $expectedInternalCode, int $expectedHttpCode): void
    {
        self::assertSame($expectedInternalCode, $class::getInternalErrorCode());
    }

    /**
     * Test that getHttpStatusCode returns the expected HTTP status.
     *
     * @param  string  $class
     * @param  int  $expectedInternalCode
     * @param  int  $expectedHttpCode
     * @return void
     */
    #[DataProvider('exceptionProvider')]
    public function testGetHttpStatusCodeReturnsExpectedStatus(string $class, int $expectedInternalCode, int $expectedHttpCode): void
    {
        self::assertSame($expectedHttpCode, $class::getHttpStatusCode());
    }
}
