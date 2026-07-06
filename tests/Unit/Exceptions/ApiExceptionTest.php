<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Illuminate\Support\Facades\Lang;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\HttpException;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Tests for the ApiException abstract class.
 *
 * Uses BadRequestException as the concrete implementation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiException::class)]
final class ApiExceptionTest extends TestCase
{
    /**
     * Test that the constructor sets the code from the HTTP status.
     *
     * @return void
     */
    public function testConstructorSetsCodeFromHttpStatus(): void
    {
        $exception = new BadRequestException;

        self::assertSame(400, $exception->getCode());
    }

    /**
     * Test that the constructor sets the message (from getCustomDetail).
     *
     * @return void
     */
    public function testConstructorSetsMessage(): void
    {
        $exception = new BadRequestException;

        self::assertIsString($exception->getMessage());
        self::assertNotEmpty($exception->getMessage());
    }

    /**
     * Test that getCustomDetail returns a string.
     *
     * @return void
     */
    public function testGetCustomDetailReturnsString(): void
    {
        $exception = new BadRequestException;

        self::assertIsString($exception->getCustomDetail());
        self::assertNotEmpty($exception->getCustomDetail());
    }

    /**
     * Test that getCustomTitle returns a string.
     *
     * @return void
     */
    public function testGetCustomTitleReturnsString(): void
    {
        $exception = new BadRequestException;

        self::assertIsString($exception->getCustomTitle());
        self::assertNotEmpty($exception->getCustomTitle());
    }

    /**
     * Test that getHttpStatusCode returns the correct HTTP code.
     *
     * @return void
     */
    public function testGetHttpStatusCodeReturnsCorrectCode(): void
    {
        self::assertSame(400, BadRequestException::getHttpStatusCode());
    }

    /**
     * Test that getInternalErrorCode returns the correct internal code.
     *
     * @return void
     */
    public function testGetInternalErrorCodeReturnsCorrectCode(): void
    {
        self::assertSame(10100, BadRequestException::getInternalErrorCode());
    }

    /**
     * Test that getCustomMeta returns meta when provided.
     *
     * @return void
     */
    public function testGetCustomMetaReturnsMetaWhenProvided(): void
    {
        $meta      = ['field' => 'The field is required.'];
        $exception = new BadRequestException($meta);

        self::assertSame($meta, $exception->getCustomMeta());
    }

    /**
     * Test that getCustomMeta returns null when no meta is provided.
     *
     * @return void
     */
    public function testGetCustomMetaReturnsNullWhenNotProvided(): void
    {
        $exception = new BadRequestException;

        self::assertNull($exception->getCustomMeta());
    }

    /**
     * Test that getHeaders returns headers when provided.
     *
     * @return void
     */
    public function testGetHeadersReturnsHeadersWhenProvided(): void
    {
        $headers   = ['X-Custom-Header' => 'value'];
        $exception = new BadRequestException(null, $headers);

        self::assertSame($headers, $exception->getHeaders());
    }

    /**
     * Test that getHeaders returns an empty array when no headers are provided.
     *
     * @return void
     */
    public function testGetHeadersReturnsEmptyArrayWhenNotProvided(): void
    {
        $exception = new BadRequestException;

        self::assertSame([], $exception->getHeaders());
    }

    /**
     * Test that getNamespace returns api-toolkit.
     *
     * @return void
     */
    public function testGetNamespaceReturnsApiToolkit(): void
    {
        $exception = new BadRequestException;

        $reflection = new \ReflectionMethod($exception, 'getNamespace');

        self::assertSame('api-toolkit', $reflection->invoke($exception));
    }

    /**
     * Test that the previous exception is passed through.
     *
     * @return void
     */
    public function testPreviousExceptionIsPassedThrough(): void
    {
        $previous  = new \RuntimeException('Original error');
        $exception = new BadRequestException(null, null, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that getInternalErrorCode throws LogicException without CODE
     * constant.
     *
     * @return void
     */
    public function testGetInternalErrorCodeWithoutCodeConstantThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The CODE constant must be defined on the exception');

        // Call static method directly on an anonymous class without
        // instantiating
        $class = new class extends ApiException {
            /** The mapped HTTP status for the test exception. */
            public const HttpStatus HTTP_STATUS = HttpStatus::BAD_REQUEST;
        };

        $class::getInternalErrorCode();
    }

    /**
     * Test that getHttpStatusCode throws LogicException without HTTP_STATUS
     * constant.
     *
     * @return void
     */
    public function testGetHttpStatusCodeWithoutHttpStatusConstantThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The HTTP_STATUS constant must be defined on the exception');

        // Call static method directly on an anonymous class without
        // instantiating
        $class = new class extends ApiException {
            /** The internal error code for the test exception. */
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::BAD_REQUEST;
        };

        $class::getHttpStatusCode();
    }

    /**
     * Test that a custom exception with proper constants works.
     *
     * @return void
     */
    public function testCustomExceptionWithConstantsWorks(): void
    {
        $exception = new class extends ApiException {
            /** The internal error code for the test exception. */
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::NOT_FOUND;

            /** The mapped HTTP status for the test exception. */
            public const HttpStatus HTTP_STATUS = HttpStatus::NOT_FOUND;
        };

        self::assertSame(10103, $exception::getInternalErrorCode());
        self::assertSame(404, $exception::getHttpStatusCode());
    }

    /**
     * Test that getStatusCode is publicly callable on instances.
     *
     * @return void
     */
    public function testGetStatusCodeIsPubliclyCallableOnInstances(): void
    {
        $exception = new BadRequestException;

        self::assertSame(400, $exception->getStatusCode());
    }

    /**
     * Test that getStatus is publicly callable on instances.
     *
     * @return void
     */
    public function testGetStatusIsPubliclyCallableOnInstances(): void
    {
        $exception = new BadRequestException;

        self::assertSame(HttpStatus::BAD_REQUEST, $exception->getStatus());
    }

    /**
     * Test that subclasses can override the translation namespace used to
     * resolve exception messages.
     *
     * @return void
     */
    public function testSubclassesCanOverrideTheTranslationNamespace(): void
    {
        // Register a detail translation under a custom namespace so the
        // override can be proven by resolution. A missing key now yields ''
        // (see testGetCustomDetailReturnsEmptyStringWhenTranslationMissing)
        // rather than leaking the raw translation key, so resolution is the
        // oracle.
        Lang::addLines(['exceptions.10100.detail' => 'Custom namespace detail'], 'en', 'custom-namespace');

        $exception = new class extends ApiException {
            /** The internal error code for the test exception. */
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::BAD_REQUEST;

            /** The mapped HTTP status for the test exception. */
            public const HttpStatus HTTP_STATUS = HttpStatus::BAD_REQUEST;

            /**
             * Get the namespace of the current exception.
             *
             * @return string
             */
            #[\Override]
            protected function getNamespace(): string
            {
                return 'custom-namespace';
            }
        };

        // The translation registered under the custom namespace resolves,
        // proving the override changed the translation key.
        self::assertSame('Custom namespace detail', $exception->getCustomDetail());
    }

    /**
     * Test that getCustomDetail returns an empty string when the detail
     * translation key is missing from every locale, rather than leaking the raw
     * translation key.
     *
     * @return void
     */
    public function testGetCustomDetailReturnsEmptyStringWhenTranslationMissing(): void
    {
        $exception = new class extends ApiException {
            /** The internal error code for the test exception. */
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::BAD_REQUEST;

            /** The mapped HTTP status for the test exception. */
            public const HttpStatus HTTP_STATUS = HttpStatus::BAD_REQUEST;

            /**
             * Resolve from a namespace with no registered translations.
             *
             * @return string
             */
            #[\Override]
            protected function getNamespace(): string
            {
                return 'missing-namespace';
            }
        };

        self::assertSame('', $exception->getCustomDetail());
    }

    /**
     * Test that getNamespace is accessible to subclasses.
     *
     * @return void
     */
    public function testGetNamespaceIsAccessibleToSubclasses(): void
    {
        $exception = new class extends ApiException {
            /** The internal error code for the test exception. */
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::BAD_REQUEST;

            /** The mapped HTTP status for the test exception. */
            public const HttpStatus HTTP_STATUS = HttpStatus::BAD_REQUEST;

            /**
             * Expose the inherited namespace for assertion.
             *
             * @return string
             */
            public function exposedNamespace(): string
            {
                return $this->getNamespace();
            }
        };

        self::assertSame('api-toolkit', $exception->exposedNamespace());
    }

    /**
     * Test that getCustomTitle derives a multi-word title from the HTTP status
     * enum case name when no translation exists.
     *
     * @return void
     */
    public function testGetCustomTitleDerivesMultiWordTitleFromStatusName(): void
    {
        $exception = new class extends ApiException {
            /** The internal error code for the test exception. */
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::HTTP_ERROR;

            /** The mapped HTTP status for the test exception. */
            public const HttpStatus HTTP_STATUS = HttpStatus::UNAVAILABLE_FOR_LEGAL_REASONS;
        };

        self::assertSame('Unavailable For Legal Reasons', $exception->getCustomTitle());
    }

    /**
     * Test that a published per-status translation localises the derived
     * title for the generic HTTP error path.
     *
     * @return void
     */
    public function testGetCustomTitleUsesPerStatusTranslationWhenPublished(): void
    {
        Lang::addLines(['exceptions.http.451' => 'Statutairement Indisponible'], 'en', 'api-toolkit');

        $exception = new HttpException(HttpStatus::UNAVAILABLE_FOR_LEGAL_REASONS);

        self::assertSame('Statutairement Indisponible', $exception->getCustomTitle());
    }

    /**
     * Test that an exception whose status has no enum case resolves its title
     * through the unknown-status translation key, which a published lang file
     * can localise.
     *
     * @return void
     */
    public function testGetCustomTitleResolvesUnknownStatusThroughTranslationKey(): void
    {
        $exception = new class extends ApiException {
            /** The internal error code for the test exception. */
            public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::HTTP_ERROR;

            /**
             * Get the HTTP status code for this exception instance.
             *
             * @return int
             */
            #[\Override]
            public function getStatusCode(): int
            {
                return 419;
            }

            /**
             * Get the HTTP status for this exception instance.
             *
             * @return \SineMacula\Http\Enums\HttpStatus|null
             */
            #[\Override]
            public function getStatus(): ?HttpStatus
            {
                return null;
            }
        };

        // The shipped lang file resolves the unknown-status key.
        self::assertSame('Unknown Error', $exception->getCustomTitle());

        Lang::addLines(['exceptions.http.unknown' => 'Erreur Inconnue'], 'en', 'api-toolkit');

        self::assertSame('Erreur Inconnue', $exception->getCustomTitle());
    }

    /**
     * Test that a non-string per-status translation yields an empty title
     * rather than an error, matching the code-keyed title guard.
     *
     * @return void
     */
    public function testGetCustomTitleReturnsEmptyStringForNonStringStatusTranslation(): void
    {
        Lang::addLines(['exceptions.http.451' => ['nested' => 'value']], 'en', 'api-toolkit');

        $exception = new HttpException(HttpStatus::UNAVAILABLE_FOR_LEGAL_REASONS);

        self::assertSame('', $exception->getCustomTitle());
    }

    /**
     * Define the test environment.
     *
     * Loads the package's exception translations so getCustomDetail resolves
     * real translations rather than the (now-fixed) raw-key fallback.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        /** @var \Illuminate\Translation\Translator $translator */
        $translator = $app['translator'];

        $translator->addNamespace('api-toolkit', __DIR__ . '/../../../resources/lang');
    }
}
