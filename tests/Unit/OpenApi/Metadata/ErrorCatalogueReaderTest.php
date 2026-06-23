<?php

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use Tests\TestCase;

/**
 * Tests for the ErrorCatalogueReader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorCatalogueReader::class)]
final class ErrorCatalogueReaderTest extends TestCase
{
    /**
     * Test that read returns exactly one descriptor per ErrorCode case.
     *
     * @return void
     */
    public function testReadReturnsOneDescriptorPerErrorCode(): void
    {
        $reader      = new ErrorCatalogueReader;
        $descriptors = $reader->read();

        static::assertCount(count(ErrorCode::cases()), $descriptors);
    }

    /**
     * Test that every descriptor in the catalogue is an ErrorDescriptor
     * instance.
     *
     * @return void
     */
    public function testReadReturnsOnlyErrorDescriptorInstances(): void
    {
        $reader = new ErrorCatalogueReader;

        foreach ($reader->read() as $descriptor) {
            static::assertInstanceOf(ErrorDescriptor::class, $descriptor);
        }
    }

    /**
     * Provide every ErrorCode case with its expected HTTP status so that the
     * test matrix is driven by the enum itself.
     *
     * @return iterable<string, array{0: \SineMacula\ApiToolkit\Enums\ErrorCode, 1: int}>
     */
    public static function errorCodeStatusProvider(): iterable
    {
        yield 'UNHANDLED_ERROR -> 500' => [ErrorCode::UNHANDLED_ERROR, 500];
        yield 'BAD_REQUEST -> 400' => [ErrorCode::BAD_REQUEST, 400];
        yield 'UNAUTHENTICATED -> 401' => [ErrorCode::UNAUTHENTICATED, 401];
        yield 'FORBIDDEN -> 403' => [ErrorCode::FORBIDDEN, 403];
        yield 'NOT_FOUND -> 404' => [ErrorCode::NOT_FOUND, 404];
        yield 'NOT_ALLOWED -> 405' => [ErrorCode::NOT_ALLOWED, 405];
        yield 'TOKEN_MISMATCH -> 419' => [ErrorCode::TOKEN_MISMATCH, 419];
        yield 'INVALID_INPUT -> 422' => [ErrorCode::INVALID_INPUT, 422];
        yield 'TOO_MANY_REQUESTS -> 429' => [ErrorCode::TOO_MANY_REQUESTS, 429];
        yield 'CONFLICT -> 409' => [ErrorCode::CONFLICT, 409];
        yield 'GONE -> 410' => [ErrorCode::GONE, 410];
        yield 'PAYLOAD_TOO_LARGE -> 413' => [ErrorCode::PAYLOAD_TOO_LARGE, 413];
        yield 'LOCKED -> 423' => [ErrorCode::LOCKED, 423];
        yield 'SERVICE_UNAVAILABLE -> 503' => [ErrorCode::SERVICE_UNAVAILABLE, 503];
        yield 'HTTP_ERROR -> 500' => [ErrorCode::HTTP_ERROR, 500];
        yield 'MAINTENANCE_MODE -> 503' => [ErrorCode::MAINTENANCE_MODE, 503];
        yield 'FILE_UPLOAD_ERROR -> 500' => [ErrorCode::FILE_UPLOAD_ERROR, 500];
        yield 'INVALID_IMAGE -> 422' => [ErrorCode::INVALID_IMAGE, 422];
        yield 'INVALID_NOTIFICATION -> 500' => [ErrorCode::INVALID_NOTIFICATION, 500];
        yield 'FAILED_TO_SEND_SMS -> 500' => [ErrorCode::FAILED_TO_SEND_SMS, 500];
    }

    /**
     * Test that each error code maps to the HTTP status that agrees with its
     * owning ApiException subclass.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ErrorCode  $errorCode
     * @param  int  $expectedHttpStatus
     * @return void
     */
    #[DataProvider('errorCodeStatusProvider')]
    public function testDescriptorCarriesCorrectHttpStatus(ErrorCode $errorCode, int $expectedHttpStatus): void
    {
        $reader      = new ErrorCatalogueReader;
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, $errorCode->getCode());

        static::assertNotNull($descriptor, "No descriptor found for {$errorCode->name}");
        static::assertSame($expectedHttpStatus, $descriptor->httpStatus);
    }

    /**
     * Test that descriptors carry real title strings from the language file
     * (not empty strings or nulls for codes that define a title).
     *
     * @return void
     */
    public function testDescriptorsCarryRealTitleStrings(): void
    {
        $reader      = new ErrorCatalogueReader;
        $descriptors = $reader->read();

        // NOT_FOUND has a defined title in the language file
        $descriptor = $this->findDescriptor($descriptors, ErrorCode::NOT_FOUND->getCode());

        static::assertNotNull($descriptor);
        static::assertSame('Not Found', $descriptor->title);
    }

    /**
     * Test that descriptors carry real detail strings from the language file.
     *
     * @return void
     */
    public function testDescriptorsCarryRealDetailStrings(): void
    {
        $reader      = new ErrorCatalogueReader;
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, ErrorCode::NOT_FOUND->getCode());

        static::assertNotNull($descriptor);
        static::assertSame('The requested resource could not be found', $descriptor->detail);
    }

    /**
     * Test that the HTTP_ERROR code has a null title because the language file
     * intentionally omits a title for the generic HTTP error.
     *
     * @return void
     */
    public function testHttpErrorCodeHasNullTitle(): void
    {
        $reader      = new ErrorCatalogueReader;
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, ErrorCode::HTTP_ERROR->getCode());

        static::assertNotNull($descriptor);
        static::assertNull($descriptor->title);
    }

    /**
     * Test that the TOKEN_MISMATCH code resolves to HTTP 419 via its static
     * override rather than an HTTP_STATUS constant.
     *
     * @return void
     */
    public function testTokenMismatchResolvesToStatus419(): void
    {
        $reader      = new ErrorCatalogueReader;
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, ErrorCode::TOKEN_MISMATCH->getCode());

        static::assertNotNull($descriptor);
        static::assertSame(419, $descriptor->httpStatus);
    }

    /**
     * Test that every descriptor carries a non-empty detail string.
     *
     * @return void
     */
    public function testEveryDescriptorHasNonEmptyDetail(): void
    {
        $reader = new ErrorCatalogueReader;

        foreach ($reader->read() as $descriptor) {
            static::assertNotSame('', $descriptor->detail, "Empty detail for code {$descriptor->code}");
        }
    }

    /**
     * Find a descriptor by its integer error code, or return null.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>  $descriptors
     * @param  int  $code
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor|null
     */
    private function findDescriptor(array $descriptors, int $code): ?ErrorDescriptor
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor->code === $code) {
                return $descriptor;
            }
        }

        return null;
    }
}
