<?php

declare(strict_types = 1);

namespace Tests\Unit;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\FileUploadException;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;
use SineMacula\ApiToolkit\Exceptions\InvalidImageException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\InvalidNotificationException;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Exceptions\NotAllowedException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\SmsSendFailedException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Exceptions\UnauthenticatedException;
use SineMacula\ApiToolkit\Exceptions\UnhandledException;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ApiExceptionTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    public function testApiExceptionExposesTranslationBasedPayloadAndMetadata(): void
    {
        $previous  = new \RuntimeException('inner');
        $exception = new BadRequestException(meta: ['foo' => 'bar'], headers: ['X-Test' => '1'], previous: $previous);

        static::assertSame(400, $exception->getCode());
        static::assertSame(400, $exception->getHttpStatusCode());
        static::assertSame(10100, $exception->getInternalErrorCode());
        static::assertSame('Bad Request', $exception->getCustomTitle());
        static::assertStringContainsString('request', strtolower($exception->getCustomDetail()));
        static::assertSame(['foo' => 'bar'], $exception->getCustomMeta());
        static::assertSame(['X-Test' => '1'], $exception->getHeaders());

        static::assertSame('api-toolkit', $this->invokeNonPublic($exception, 'getNamespace'));
    }

    public function testConcreteApiExceptionSubclassesExposeExpectedInternalCodesAndStatuses(): void
    {
        $map = [
            BadRequestException::class          => [ErrorCode::BAD_REQUEST, HttpStatus::BAD_REQUEST],
            UnauthenticatedException::class     => [ErrorCode::UNAUTHENTICATED, HttpStatus::UNAUTHORIZED],
            ForbiddenException::class           => [ErrorCode::FORBIDDEN, HttpStatus::FORBIDDEN],
            NotFoundException::class            => [ErrorCode::NOT_FOUND, HttpStatus::NOT_FOUND],
            NotAllowedException::class          => [ErrorCode::NOT_ALLOWED, HttpStatus::METHOD_NOT_ALLOWED],
            TokenMismatchException::class       => [ErrorCode::TOKEN_MISMATCH, HttpStatus::TOKEN_MISMATCH],
            InvalidInputException::class        => [ErrorCode::INVALID_INPUT, HttpStatus::UNPROCESSABLE_ENTITY],
            TooManyRequestsException::class     => [ErrorCode::TOO_MANY_REQUESTS, HttpStatus::TOO_MANY_REQUESTS],
            MaintenanceModeException::class     => [ErrorCode::MAINTENANCE_MODE, HttpStatus::SERVICE_UNAVAILABLE],
            FileUploadException::class          => [ErrorCode::FILE_UPLOAD_ERROR, HttpStatus::INTERNAL_SERVER_ERROR],
            InvalidImageException::class        => [ErrorCode::INVALID_IMAGE, HttpStatus::UNPROCESSABLE_ENTITY],
            InvalidNotificationException::class => [ErrorCode::INVALID_NOTIFICATION, HttpStatus::INTERNAL_SERVER_ERROR],
            SmsSendFailedException::class       => [ErrorCode::FAILED_TO_SEND_SMS, HttpStatus::INTERNAL_SERVER_ERROR],
            UnhandledException::class           => [ErrorCode::UNHANDLED_ERROR, HttpStatus::INTERNAL_SERVER_ERROR],
        ];

        foreach ($map as $class => [$errorCode, $status]) {
            /** @var ApiException $exception */
            $exception = new $class;

            static::assertSame($errorCode->getCode(), $exception->getInternalErrorCode(), $class);
            static::assertSame($status->getCode(), $exception->getHttpStatusCode(), $class);
        }
    }

    public function testApiExceptionThrowsWhenRequiredConstantsAreMissing(): void
    {
        $this->expectException(\LogicException::class);

        MissingCodeException::getInternalErrorCode();
    }

    public function testApiExceptionThrowsWhenHttpStatusConstantIsMissing(): void
    {
        $this->expectException(\LogicException::class);

        MissingStatusException::getHttpStatusCode();
    }
}

class MissingCodeException extends ApiException
{
    public const HttpStatus HTTP_STATUS = HttpStatus::BAD_REQUEST;
}

class MissingStatusException extends ApiException
{
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::BAD_REQUEST;
}
