<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\MetadataReadException;
use SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use Tests\Fixtures\Enums\AppErrorCode;
use Tests\Fixtures\Exceptions\WidgetFailureException;
use Tests\Fixtures\Support\FunctionOverrides;
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
        $reader      = $this->reader();
        $descriptors = $reader->read();

        self::assertCount(count(ErrorCode::cases()), $descriptors);
    }

    /**
     * Test that every descriptor is an ErrorDescriptor instance.
     *
     * @return void
     */
    public function testReadReturnsOnlyErrorDescriptorInstances(): void
    {
        $reader = $this->reader();

        foreach ($reader->read() as $descriptor) {
            self::assertInstanceOf(ErrorDescriptor::class, $descriptor);
        }
    }

    /**
     * Provide every ErrorCode case with its expected HTTP status.
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
    }

    /**
     * Test that each code maps to its owning ApiException's HTTP status.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ErrorCode  $errorCode
     * @param  int  $expectedHttpStatus
     * @return void
     */
    #[DataProvider('errorCodeStatusProvider')]
    public function testDescriptorCarriesCorrectHttpStatus(ErrorCode $errorCode, int $expectedHttpStatus): void
    {
        $reader      = $this->reader();
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, $errorCode->getCode());

        self::assertNotNull($descriptor, "No descriptor found for {$errorCode->name}");
        self::assertSame($expectedHttpStatus, $descriptor->httpStatus);
    }

    /**
     * Test that descriptors carry real title strings from the language file.
     *
     * @return void
     */
    public function testDescriptorsCarryRealTitleStrings(): void
    {
        $reader      = $this->reader();
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($descriptor);
        self::assertSame('Not Found', $descriptor->title);
    }

    /**
     * Test that descriptors carry real detail strings from the language file.
     *
     * @return void
     */
    public function testDescriptorsCarryRealDetailStrings(): void
    {
        $reader      = $this->reader();
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($descriptor);
        self::assertSame('The requested resource could not be found', $descriptor->detail);
    }

    /**
     * Test that HTTP_ERROR has a null title.
     *
     * @return void
     */
    public function testHttpErrorCodeHasNullTitle(): void
    {
        $reader      = $this->reader();
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, ErrorCode::HTTP_ERROR->getCode());

        self::assertNotNull($descriptor);
        self::assertNull($descriptor->title);
    }

    /**
     * Test that TOKEN_MISMATCH resolves to 419 via its static override.
     *
     * @return void
     */
    public function testTokenMismatchResolvesToStatus419(): void
    {
        $reader      = $this->reader();
        $descriptors = $reader->read();

        $descriptor = $this->findDescriptor($descriptors, ErrorCode::TOKEN_MISMATCH->getCode());

        self::assertNotNull($descriptor);
        self::assertSame(419, $descriptor->httpStatus);
    }

    /**
     * Test that every descriptor carries a non-empty detail string.
     *
     * @return void
     */
    public function testEveryDescriptorHasNonEmptyDetail(): void
    {
        $reader = $this->reader();

        foreach ($reader->read() as $descriptor) {
            self::assertNotSame('', $descriptor->detail, "Empty detail for code {$descriptor->code}");
        }
    }

    /**
     * Test that the exception map is memoised.
     *
     * @return void
     */
    public function testExceptionMapIsMemoisedAcrossReads(): void
    {
        $reader = $this->reader();
        $first  = $reader->read();

        // A re-scan would fail, so a successful second read proves memoisation
        FunctionOverrides::set('glob', static fn (): false => false);

        self::assertEquals($first, $reader->read());
    }

    /**
     * Test that a failed directory scan raises a metadata read exception.
     *
     * @return void
     */
    public function testThrowsWhenExceptionsDirectoryScanFails(): void
    {
        FunctionOverrides::set('glob', static fn (): false => false);

        try {
            $this->reader()->read();
        } catch (MetadataReadException $e) {
            self::assertStringStartsWith('Unable to scan the exceptions directory: ', $e->getMessage());
            self::assertStringContainsString('/Exceptions', $e->getMessage());

            return;
        }

        self::fail('Expected a MetadataReadException to be thrown.');
    }

    /**
     * Test that a subclass without a CODE constant does not abort the scan.
     *
     * @return void
     */
    public function testContinuesScanningAfterCodelessSubclass(): void
    {
        $codeless = (new \ReflectionClass(BadRequestException::class))->getFileName();
        $later    = (new \ReflectionClass(NotFoundException::class))->getFileName();

        self::assertIsString($codeless);
        self::assertIsString($later);

        FunctionOverrides::set('glob', static fn (): array => [$codeless, $later]);
        FunctionOverrides::set(
            'defined',
            static fn (mixed ...$arguments): bool => is_string($arguments[0]) && !str_starts_with($arguments[0], BadRequestException::class . '::') && \defined($arguments[0]),
        );

        $descriptor = $this->findDescriptor($this->reader()->read(), ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($descriptor);
        self::assertSame(404, $descriptor->httpStatus);
    }

    /**
     * Test that a code without an owning exception falls back to 500.
     *
     * @return void
     */
    public function testUnmappedErrorCodeFallsBackToStatus500(): void
    {
        FunctionOverrides::set('glob', static fn (): array => []);

        $descriptor = $this->findDescriptor($this->reader()->read(), ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($descriptor);
        self::assertSame(500, $descriptor->httpStatus);
    }

    /**
     * Test that a subclass without a CODE constant is skipped.
     *
     * @return void
     */
    public function testSkipsExceptionSubclassWithoutCodeConstant(): void
    {
        $file = (new \ReflectionClass(NotFoundException::class))->getFileName();

        self::assertIsString($file);

        // The file is discovered but its CODE constant is reported absent
        FunctionOverrides::set('glob', static fn (): array => [$file]);
        FunctionOverrides::set(
            'defined',
            static fn (mixed ...$arguments): bool => is_string($arguments[0]) && !str_ends_with($arguments[0], '::CODE') && \defined($arguments[0]),
        );

        $descriptor = $this->findDescriptor($this->reader()->read(), ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($descriptor);
        self::assertSame(500, $descriptor->httpStatus);
    }

    /**
     * Test that an application ApiException is documented beside the baseline.
     *
     * @return void
     */
    public function testDiscoveredApplicationExceptionJoinsTheCatalogue(): void
    {
        $descriptors = $this->reader($this->fixturesDiscoverer())->read();

        $application = $this->findDescriptor($descriptors, AppErrorCode::WIDGET_FAILURE->getCode());

        self::assertNotNull($application);
        self::assertSame(502, $application->httpStatus);
        self::assertSame(WidgetFailureException::getHttpStatusCode(), $application->httpStatus);

        $baseline = $this->findDescriptor($descriptors, ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($baseline);
        self::assertSame(404, $baseline->httpStatus);
    }

    /**
     * Test that an application exception's descriptor carries its class.
     *
     * @return void
     */
    public function testDiscoveredApplicationExceptionCarriesSource(): void
    {
        $descriptors = $this->reader($this->fixturesDiscoverer())->read();

        $descriptor = $this->findDescriptor($descriptors, AppErrorCode::WIDGET_FAILURE->getCode());

        self::assertNotNull($descriptor);
        self::assertSame(WidgetFailureException::class, $descriptor->source);
    }

    /**
     * Test that a toolkit code carries its owning exception class.
     *
     * @return void
     */
    public function testToolkitCodeCarriesOwningExceptionSource(): void
    {
        $descriptor = $this->findDescriptor($this->reader()->read(), ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($descriptor);
        self::assertSame(NotFoundException::class, $descriptor->source);
    }

    /**
     * Test that a code without an owning exception has a null source.
     *
     * @return void
     */
    public function testUnmappedCodeCarriesNullSource(): void
    {
        FunctionOverrides::set('glob', static fn (): array => []);

        $descriptor = $this->findDescriptor($this->reader()->read(), ErrorCode::NOT_FOUND->getCode());

        self::assertNotNull($descriptor);
        self::assertNull($descriptor->source);
    }

    /**
     * Test that a duplicate code keeps the first class and warns.
     *
     * @return void
     */
    public function testDuplicateCodeLogsAWarning(): void
    {
        $directory = $this->makeCollisionFixtures();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'multiple exceptions for code')
                && str_contains($message, (string) AppErrorCode::WIDGET_FAILURE->getCode())
                && str_contains($message, 'BetaException'));

        try {
            $discoverer = new ApiExceptionDiscoverer(['Tests\Fixtures\Runtime\\' => [$directory]]);

            $this->reader($discoverer)->read();
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * Build a reader over the given discoverer, defaulting to an empty one.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer|null  $discoverer
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader
     */
    private function reader(?ApiExceptionDiscoverer $discoverer = null): ErrorCatalogueReader
    {
        return new ErrorCatalogueReader($discoverer ?? new ApiExceptionDiscoverer([]));
    }

    /**
     * Build a discoverer pointing at the fixture exceptions directory.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer
     */
    private function fixturesDiscoverer(): ApiExceptionDiscoverer
    {
        $file = (new \ReflectionClass(WidgetFailureException::class))->getFileName();

        self::assertIsString($file);

        return new ApiExceptionDiscoverer(['Tests\Fixtures\Exceptions\\' => [dirname($file)]]);
    }

    /**
     * Create and load two temporary exceptions that declare the same code.
     *
     * @return string
     */
    private function makeCollisionFixtures(): string
    {
        $directory = sys_get_temp_dir() . '/api-toolkit-collision-' . uniqid('', true);

        mkdir($directory, 0777, true);

        foreach (['AlphaException', 'BetaException'] as $name) {

            $source = "<?php\n\ndeclare(strict_types = 1);\n\nnamespace Tests\\Fixtures\\Runtime;\n\n"
                . "use SineMacula\\ApiToolkit\\Exceptions\\ApiException;\n"
                . "use SineMacula\\Http\\Enums\\HttpStatus;\n"
                . "use Tests\\Fixtures\\Enums\\AppErrorCode;\n\n"
                . "final class {$name} extends ApiException\n{\n"
                . "    public const CODE = AppErrorCode::WIDGET_FAILURE;\n"
                . "    public const HTTP_STATUS = HttpStatus::BAD_GATEWAY;\n}\n";

            $file = $directory . '/' . $name . '.php';

            file_put_contents($file, $source);

            require_once $file;
        }

        return $directory;
    }

    /**
     * Remove a temporary fixtures directory and its files.
     *
     * @param  string  $directory
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
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
