<?php

namespace SineMacula\ApiToolkit\Exceptions;

use Exception;
use Illuminate\Support\Facades\Lang;
use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\Http\Enums\HttpStatus;

/**
 * The base API exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ApiException extends \Exception
{
    /**
     * Constructor.
     *
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>|null  $headers
     * @param  \Throwable|null  $previous
     */
    public function __construct(

        /** Exception meta */
        private readonly ?array $meta = null,

        /** Exception headers */
        private readonly ?array $headers = null,

        ?\Throwable $previous = null,

    ) {
        parent::__construct($this->getCustomDetail(), $this->getStatusCode(), $previous);
    }

    /**
     * Get the custom detail for the exception.
     *
     * @return string
     */
    public function getCustomDetail(): string
    {
        $translation = Lang::get($this->getTranslationKey('detail'));

        return is_string($translation) ? $translation : '';
    }

    /**
     * Get internal error code.
     *
     * @return int
     */
    public static function getInternalErrorCode(): int
    {
        return self::getInternalError()->getCode();
    }

    /**
     * Get HTTP status code.
     *
     * @return int
     */
    public static function getHttpStatusCode(): int
    {
        return self::getHttpStatus()->getCode();
    }

    /**
     * Get the HTTP status code for this exception instance.
     *
     * Defaults to the static resolution (HTTP_STATUS constant or a static
     * override for non-standard codes). Subclasses carrying a runtime
     * status (e.g. the generic HttpException) may override this; the
     * exception handler renders responses from this instance-level code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return static::getHttpStatusCode();
    }

    /**
     * Get the HTTP status for this exception instance.
     *
     * Defaults to the HTTP_STATUS constant. Used to derive the default
     * title when no translation exists for the error code.
     *
     * @return \SineMacula\Http\Enums\HttpStatus
     */
    public function getStatus(): HttpStatus
    {
        return self::getHttpStatus();
    }

    /**
     * Get the custom title for the exception.
     *
     * @return string
     */
    public function getCustomTitle(): string
    {
        $key = $this->getTranslationKey('title');

        if (Lang::has($key)) {
            $translation = Lang::get($key);

            return is_string($translation) ? $translation : '';
        }

        return $this->getDefaultTitle();
    }

    /**
     * Get custom Meta.
     *
     * @return array<string, mixed>|null
     */
    public function getCustomMeta(): ?array
    {
        return $this->meta;
    }

    /**
     * Get headers.
     *
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return (array) $this->headers;
    }

    /**
     * Get the namespace of the current exception.
     *
     * @return string|null
     */
    protected function getNamespace(): ?string
    {
        return 'api-toolkit';
    }

    /**
     * Get internal error.
     *
     * @return \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface
     */
    private static function getInternalError(): ErrorCodeInterface
    {
        if (!defined(static::class . '::CODE')) {
            throw new \LogicException('The CODE constant must be defined on the exception');
        }

        /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface $code */
        $code = constant(static::class . '::CODE');

        return $code;
    }

    /**
     * Get HTTP status.
     *
     * @return \SineMacula\Http\Enums\HttpStatus
     */
    private static function getHttpStatus(): HttpStatus
    {
        if (!defined(static::class . '::HTTP_STATUS')) {
            throw new \LogicException('The HTTP_STATUS constant must be defined on the exception');
        }

        /** @var \SineMacula\Http\Enums\HttpStatus $status */
        $status = constant(static::class . '::HTTP_STATUS');

        return $status;
    }

    /**
     * Derive a human-readable title from the HTTP status enum case name.
     *
     * @return string
     */
    private function getDefaultTitle(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->getStatus()->name)));
    }

    /**
     * Return the translation key for the given key.
     *
     * @param  string  $key
     * @return string
     */
    private function getTranslationKey(string $key): string
    {
        return sprintf('%s::exceptions.%s.%s', $this->getNamespace(), static::getInternalErrorCode(), $key);
    }
}
