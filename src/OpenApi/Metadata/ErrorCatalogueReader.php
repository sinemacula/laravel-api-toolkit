<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\MetadataReadException;

/**
 * Resolves every documented error code to its HTTP status, title, and detail.
 *
 * The catalogue covers the toolkit's own ErrorCode cases plus any application
 * or module defined ApiException subclass discovered outside the vendor tree.
 * HTTP status is sourced structurally from the owning ApiException subclass so
 * the catalogue always agrees with what the exception handler renders. Toolkit
 * titles and details are read from the package translation namespace, while an
 * application code resolves its title and detail from the exception's own
 * translation strings, gracefully yielding an empty detail when none exist.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ErrorCatalogueReader
{
    /** @var string The base namespace for the toolkit's ApiException subclasses */
    private const string EXCEPTION_NAMESPACE = 'SineMacula\ApiToolkit\Exceptions\\';

    /** @var string The filesystem path to the toolkit Exceptions directory */
    private const string EXCEPTIONS_DIR = __DIR__ . '/../../Exceptions';

    /** @var array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>> Memoised code-to-class map. */
    private array $exceptionMap = [];

    /**
     * Create a new error catalogue reader.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer  $discoverer
     * @return void
     */
    public function __construct(

        /** Discovers application and module defined ApiException subclasses. */
        private readonly ApiExceptionDiscoverer $discoverer,
    ) {}

    /**
     * Resolve every documented error code to its ErrorDescriptor.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\MetadataReadException
     */
    public function read(): array
    {
        $map = $this->buildExceptionMap();

        $toolkit = array_map(
            fn (ErrorCode $code): ErrorDescriptor => $this->resolveToolkitDescriptor($code, $map),
            ErrorCode::cases(),
        );

        return array_merge($toolkit, $this->resolveApplicationDescriptors($map));
    }

    /**
     * Build a map from integer error-code value to owning exception class.
     *
     * Merges the toolkit's own exception scan with the application exceptions
     * found by the discoverer, then registers each so a code claimed by two
     * different exceptions is reported rather than silently overwritten. The
     * map is memoised so a second call to read() does not re-scan.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\MetadataReadException
     */
    private function buildExceptionMap(): array
    {
        if ($this->exceptionMap !== []) {
            return $this->exceptionMap;
        }

        $classes = array_merge($this->scanToolkitExceptions(), $this->discoverer->discover());

        foreach ($classes as $class) {
            $this->registerException($class);
        }

        return $this->exceptionMap;
    }

    /**
     * Scan the toolkit Exceptions directory for its ApiException subclasses.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\MetadataReadException
     */
    private function scanToolkitExceptions(): array
    {
        $files = glob(self::EXCEPTIONS_DIR . '/*.php');

        if ($files === false) {
            throw new MetadataReadException('Unable to scan the exceptions directory: ' . self::EXCEPTIONS_DIR);
        }

        $classes = [];

        foreach ($files as $file) {

            $class = self::EXCEPTION_NAMESPACE . basename($file, '.php');

            if (!class_exists($class) || !is_subclass_of($class, ApiException::class)) {
                continue;
            }

            if (!defined($class . '::CODE')) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Register an exception class against its code, warning on a collision.
     *
     * The first class discovered for a code wins; a second, different class
     * claiming the same code is ignored with a warning so the clash is visible
     * rather than silently resolved.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $class
     * @return void
     */
    private function registerException(string $class): void
    {
        /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface $code */
        $code     = constant($class . '::CODE');
        $intCode  = $code->getCode();
        $existing = $this->exceptionMap[$intCode] ?? null;

        if ($existing !== null && $existing !== $class) {
            Log::warning(sprintf(
                'Error catalogue found multiple exceptions for code [%d]: keeping [%s], ignoring [%s].',
                $intCode,
                $existing,
                $class,
            ));

            return;
        }

        $this->exceptionMap[$intCode] = $class;
    }

    /**
     * Resolve one toolkit ErrorCode case to its descriptor.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ErrorCode  $errorCode
     * @param  array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>  $map
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor
     */
    private function resolveToolkitDescriptor(ErrorCode $errorCode, array $map): ErrorDescriptor
    {
        $intCode = $errorCode->getCode();

        return new ErrorDescriptor(
            code      : $intCode,
            httpStatus: $this->resolveHttpStatus($map[$intCode] ?? null),
            title     : $this->resolveTitle($intCode),
            detail    : $this->resolveDetail($intCode),
        );
    }

    /**
     * Resolve descriptors for the discovered application error codes.
     *
     * Only codes with no matching toolkit ErrorCode case are emitted here; the
     * toolkit's own codes are already covered by the enum-driven pass.
     *
     * @param  array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>  $map
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    private function resolveApplicationDescriptors(array $map): array
    {
        $toolkitCodes = array_map(static fn (ErrorCode $code): int => $code->getCode(), ErrorCode::cases());

        $descriptors = [];

        foreach ($map as $intCode => $class) {
            if (in_array($intCode, $toolkitCodes, true)) {
                continue;
            }

            $descriptors[] = $this->resolveApplicationDescriptor($intCode, $class);
        }

        return $descriptors;
    }

    /**
     * Resolve one application exception to its descriptor.
     *
     * Title and detail come from the exception's own translation strings via a
     * constructor-free instance, so an application code with no language
     * strings yields a derived title and an empty detail rather than failing.
     * The instance is used only to read documentation values and never leaves
     * this method, so bypassing the constructor is safe.
     *
     * @param  int  $intCode
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $class
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor
     *
     * @SuppressWarnings("php:S3011")
     */
    private function resolveApplicationDescriptor(int $intCode, string $class): ErrorDescriptor
    {
        $exception = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new ErrorDescriptor(
            code      : $intCode,
            httpStatus: $class::getHttpStatusCode(),
            title     : $exception->getCustomTitle(),
            detail    : $exception->getCustomDetail(),
        );
    }

    /**
     * Resolve the HTTP status integer for the given exception class.
     *
     * Falls back to 500 for codes with no owning subclass, matching the
     * UnhandledException default.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>|null  $exceptionClass
     * @return int
     */
    private function resolveHttpStatus(?string $exceptionClass): int
    {
        if ($exceptionClass === null) {
            return 500;
        }

        return $exceptionClass::getHttpStatusCode();
    }

    /**
     * Resolve the title string for the given integer error code.
     *
     * Returns null when no title is defined in the language file (e.g. for the
     * generic HTTP_ERROR code, where the title is derived at runtime from the
     * HTTP status phrase).
     *
     * @param  int  $code
     * @return string|null
     */
    private function resolveTitle(int $code): ?string
    {
        $key = sprintf('api-toolkit::exceptions.%d.title', $code);

        if (!Lang::has($key)) {
            return null;
        }

        $translation = Lang::get($key);

        return is_string($translation) ? $translation : null;
    }

    /**
     * Resolve the detail string for the given integer error code.
     *
     * @param  int  $code
     * @return string
     */
    private function resolveDetail(int $code): string
    {
        $key         = sprintf('api-toolkit::exceptions.%d.detail', $code);
        $translation = Lang::get($key);

        return is_string($translation) ? $translation : '';
    }
}
