<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Illuminate\Support\Facades\Lang;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\MetadataReadException;

/**
 * Resolves each ErrorCode enum case to its HTTP status, title, and detail.
 *
 * HTTP status is sourced structurally from the owning ApiException subclass
 * (via its HTTP_STATUS constant or a static override), ensuring the catalogue
 * always agrees with what the exception handler renders. Title and detail are
 * read from the package translation namespace so the catalogue reflects any
 * published language overrides.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ErrorCatalogueReader
{
    /** @var string The base namespace for all ApiException subclasses */
    private const string EXCEPTION_NAMESPACE = 'SineMacula\ApiToolkit\Exceptions\\';

    /** @var string The filesystem path to the Exceptions directory */
    private const string EXCEPTIONS_DIR = __DIR__ . '/../../Exceptions';

    /**
     * Memoised map from integer error-code value to owning exception class.
     *
     * @var array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    private array $exceptionMap = [];

    /**
     * Resolve every ErrorCode case to its ErrorDescriptor.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    public function read(): array
    {
        $map = $this->buildExceptionMap();

        return array_map(
            fn (ErrorCode $code) => $this->resolveDescriptor($code, $map),
            ErrorCode::cases(),
        );
    }

    /**
     * Build a map from integer error-code value to owning exception class.
     *
     * Scans the Exceptions directory for PHP files, derives the FQCN, triggers
     * autoloading via class_exists(), then inspects each subclass for its CODE
     * constant. The map is memoised so a second call to read() does not
     * re-scan.
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

        $files = glob(self::EXCEPTIONS_DIR . '/*.php');

        if ($files === false) {
            throw new MetadataReadException('Unable to scan the exceptions directory: ' . self::EXCEPTIONS_DIR);
        }

        foreach ($files as $file) {
            $class = self::EXCEPTION_NAMESPACE . basename($file, '.php');

            if (!class_exists($class) || !is_subclass_of($class, ApiException::class)) {
                continue;
            }

            if (!defined($class . '::CODE')) {
                continue;
            }

            /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface $codeConstant */
            $codeConstant = constant($class . '::CODE');

            $this->exceptionMap[$codeConstant->getCode()] = $class;
        }

        return $this->exceptionMap;
    }

    /**
     * Resolve one ErrorCode case to its descriptor.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ErrorCode  $errorCode
     * @param  array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>  $map
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor
     */
    private function resolveDescriptor(ErrorCode $errorCode, array $map): ErrorDescriptor
    {
        $intCode        = $errorCode->getCode();
        $exceptionClass = $map[$intCode] ?? null;
        $httpStatus     = $this->resolveHttpStatus($exceptionClass);

        return new ErrorDescriptor(
            code      : $intCode,
            httpStatus: $httpStatus,
            title     : $this->resolveTitle($intCode),
            detail    : $this->resolveDetail($intCode),
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

        // TokenMismatchException overrides getHttpStatusCode() to return 419
        // directly (no HTTP_STATUS constant) — call the static method.
        if ($exceptionClass === TokenMismatchException::class || !defined($exceptionClass . '::HTTP_STATUS')) {
            return $exceptionClass::getHttpStatusCode();
        }

        /** @var \SineMacula\Http\Enums\HttpStatus $statusConstant */
        $statusConstant = constant($exceptionClass . '::HTTP_STATUS');

        return $statusConstant->getCode();
    }

    /**
     * Resolve the title string for the given integer error code.
     *
     * Returns null when no title is defined in the language file (e.g. for
     * the generic HTTP_ERROR code, where the title is derived at runtime
     * from the HTTP status phrase).
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
