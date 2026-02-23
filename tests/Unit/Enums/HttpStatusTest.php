<?php

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\HttpStatus;

/**
 * Tests for the HttpStatus enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(HttpStatus::class)]
class HttpStatusTest extends TestCase
{
    /**
     * Test that getCode returns the enum backing value.
     *
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $case
     * @param  int  $expectedCode
     * @return void
     */
    #[DataProvider('representativeCaseProvider')]
    public function testGetCodeReturnsEnumValue(HttpStatus $case, int $expectedCode): void
    {
        static::assertSame($expectedCode, $case->getCode());
    }

    /**
     * Test that informational status codes are in the 1xx range.
     *
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $case
     * @return void
     */
    #[DataProvider('informationalProvider')]
    public function testInformationalStatusCodesAreInRange(HttpStatus $case): void
    {
        $code = $case->getCode();

        static::assertGreaterThanOrEqual(100, $code);
        static::assertLessThan(200, $code);
    }

    /**
     * Test that successful status codes are in the 2xx range.
     *
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $case
     * @return void
     */
    #[DataProvider('successfulProvider')]
    public function testSuccessfulStatusCodesAreInRange(HttpStatus $case): void
    {
        $code = $case->getCode();

        static::assertGreaterThanOrEqual(200, $code);
        static::assertLessThan(300, $code);
    }

    /**
     * Test that redirection status codes are in the 3xx range.
     *
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $case
     * @return void
     */
    #[DataProvider('redirectionProvider')]
    public function testRedirectionStatusCodesAreInRange(HttpStatus $case): void
    {
        $code = $case->getCode();

        static::assertGreaterThanOrEqual(300, $code);
        static::assertLessThan(400, $code);
    }

    /**
     * Test that client error status codes are in the 4xx range.
     *
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $case
     * @return void
     */
    #[DataProvider('clientErrorProvider')]
    public function testClientErrorStatusCodesAreInRange(HttpStatus $case): void
    {
        $code = $case->getCode();

        static::assertGreaterThanOrEqual(400, $code);
        static::assertLessThan(500, $code);
    }

    /**
     * Test that server error status codes are in the 5xx range.
     *
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $case
     * @return void
     */
    #[DataProvider('serverErrorProvider')]
    public function testServerErrorStatusCodesAreInRange(HttpStatus $case): void
    {
        $code = $case->getCode();

        static::assertGreaterThanOrEqual(500, $code);
        static::assertLessThan(600, $code);
    }

    /**
     * Test that all backing values are unique.
     *
     * @return void
     */
    public function testAllBackingValuesAreUnique(): void
    {
        $values = array_map(fn (HttpStatus $case) => $case->value, HttpStatus::cases());

        static::assertSame(count($values), count(array_unique($values)));
    }

    /**
     * Provide representative cases from each HTTP status category.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\HttpStatus, int}>
     */
    public static function representativeCaseProvider(): iterable
    {
        yield 'CONTINUE (1xx)' => [HttpStatus::CONTINUE, 100];
        yield 'OK (2xx)' => [HttpStatus::OK, 200];
        yield 'CREATED (2xx)' => [HttpStatus::CREATED, 201];
        yield 'NO_CONTENT (2xx)' => [HttpStatus::NO_CONTENT, 204];
        yield 'MOVED_PERMANENTLY (3xx)' => [HttpStatus::MOVED_PERMANENTLY, 301];
        yield 'NOT_MODIFIED (3xx)' => [HttpStatus::NOT_MODIFIED, 304];
        yield 'BAD_REQUEST (4xx)' => [HttpStatus::BAD_REQUEST, 400];
        yield 'UNAUTHORIZED (4xx)' => [HttpStatus::UNAUTHORIZED, 401];
        yield 'FORBIDDEN (4xx)' => [HttpStatus::FORBIDDEN, 403];
        yield 'NOT_FOUND (4xx)' => [HttpStatus::NOT_FOUND, 404];
        yield 'UNPROCESSABLE_ENTITY (4xx)' => [HttpStatus::UNPROCESSABLE_ENTITY, 422];
        yield 'TOO_MANY_REQUESTS (4xx)' => [HttpStatus::TOO_MANY_REQUESTS, 429];
        yield 'INTERNAL_SERVER_ERROR (5xx)' => [HttpStatus::INTERNAL_SERVER_ERROR, 500];
        yield 'SERVICE_UNAVAILABLE (5xx)' => [HttpStatus::SERVICE_UNAVAILABLE, 503];
    }

    /**
     * Provide informational (1xx) status cases.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\HttpStatus}>
     */
    public static function informationalProvider(): iterable
    {
        yield 'CONTINUE' => [HttpStatus::CONTINUE];
        yield 'SWITCHING_PROTOCOLS' => [HttpStatus::SWITCHING_PROTOCOLS];
        yield 'PROCESSING' => [HttpStatus::PROCESSING];
        yield 'EARLY_HINTS' => [HttpStatus::EARLY_HINTS];
    }

    /**
     * Provide successful (2xx) status cases.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\HttpStatus}>
     */
    public static function successfulProvider(): iterable
    {
        yield 'OK' => [HttpStatus::OK];
        yield 'CREATED' => [HttpStatus::CREATED];
        yield 'ACCEPTED' => [HttpStatus::ACCEPTED];
        yield 'NO_CONTENT' => [HttpStatus::NO_CONTENT];
    }

    /**
     * Provide redirection (3xx) status cases.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\HttpStatus}>
     */
    public static function redirectionProvider(): iterable
    {
        yield 'MULTIPLE_CHOICES' => [HttpStatus::MULTIPLE_CHOICES];
        yield 'MOVED_PERMANENTLY' => [HttpStatus::MOVED_PERMANENTLY];
        yield 'FOUND' => [HttpStatus::FOUND];
        yield 'TEMPORARY_REDIRECT' => [HttpStatus::TEMPORARY_REDIRECT];
    }

    /**
     * Provide client error (4xx) status cases.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\HttpStatus}>
     */
    public static function clientErrorProvider(): iterable
    {
        yield 'BAD_REQUEST' => [HttpStatus::BAD_REQUEST];
        yield 'UNAUTHORIZED' => [HttpStatus::UNAUTHORIZED];
        yield 'FORBIDDEN' => [HttpStatus::FORBIDDEN];
        yield 'NOT_FOUND' => [HttpStatus::NOT_FOUND];
        yield 'UNPROCESSABLE_ENTITY' => [HttpStatus::UNPROCESSABLE_ENTITY];
    }

    /**
     * Provide server error (5xx) status cases.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\HttpStatus}>
     */
    public static function serverErrorProvider(): iterable
    {
        yield 'INTERNAL_SERVER_ERROR' => [HttpStatus::INTERNAL_SERVER_ERROR];
        yield 'BAD_GATEWAY' => [HttpStatus::BAD_GATEWAY];
        yield 'SERVICE_UNAVAILABLE' => [HttpStatus::SERVICE_UNAVAILABLE];
        yield 'GATEWAY_TIMEOUT' => [HttpStatus::GATEWAY_TIMEOUT];
    }
}
