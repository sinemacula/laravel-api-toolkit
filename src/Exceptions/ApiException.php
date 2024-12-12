<?php

namespace SineMacula\ApiToolkit\Exceptions;

use Exception;
use Illuminate\Support\Facades\Lang;
use LogicException;
use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use Throwable;

/**
 * The base API exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class ApiException extends Exception
{
    /**
     * Constructor.
     *
     * @param  array|null  $meta
     * @param  array|null  $headers
     * @param  \Throwable|null  $previous
     */
    public function __construct(

        /** Exception meta */
        private readonly ?array $meta = null,

        /** Exception headers */
        private readonly ?array $headers = null,

        ?Throwable $previous = null

    ) {
        parent::__construct($this->getCustomDetail(), $this->getHttpStatusCode(), $previous);
    }

    /**
     * Get the custom detail for the exception.
     *
     * @return string
     */
    public function getCustomDetail(): string
    {
        return Lang::get($this->getTranslationKey('detail'));
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
     * Get the custom title for the exception.
     *
     * @return string
     */
    public function getCustomTitle(): string
    {
        return Lang::get($this->getTranslationKey('title'));
    }

    /**
     * Get custom Meta.
     *
     * @return array|null
     */
    public function getCustomMeta(): ?array
    {
        return $this->meta;
    }

    /**
     * Get headers.
     *
     * @return array
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
            throw new LogicException('The CODE constant must be defined on the exception');
        }

        return static::CODE;
    }

    /**
     * Get HTTP status.
     *
     * @return \SineMacula\ApiToolkit\Enums\HttpStatus
     */
    private static function getHttpStatus(): HttpStatus
    {
        if (!defined(static::class . '::HTTP_STATUS')) {
            throw new LogicException('The HTTP_STATUS constant must be defined on the exception');
        }

        return static::HTTP_STATUS;
    }

    /**
     * Return the translation key for the given key.
     *
     * @param  string  $key
     * @return string
     */
    private function getTranslationKey(string $key): string
    {
        return sprintf('%s::exceptions.%s.%s', $this->getNamespace(), $this->getInternalErrorCode(), $key);
    }
}
