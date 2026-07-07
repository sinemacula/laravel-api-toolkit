<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

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

        // The previous throwable
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
        $key = $this->getTranslationKey('detail');

        if (Lang::has($key)) {
            $translation = Lang::get($key);

            return is_string($translation) ? $translation : '';
        }

        return '';
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
     *
     * @throws \LogicException
     */
    public static function getHttpStatusCode(): int
    {
        return self::getHttpStatus()?->getCode()
            ?? throw new \LogicException('The HTTP_STATUS constant must be defined on the exception');
    }

    /**
     * Get the HTTP status code for this exception instance.
     *
     * Defaults to the static resolution (HTTP_STATUS constant or a static
     * override for non-standard codes). Subclasses carrying a runtime status
     * (e.g. the generic HttpException) may override this; the exception handler
     * renders responses from this instance-level code.
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
     * Defaults to the HTTP_STATUS constant, or null for exceptions whose status
     * has no corresponding case in the shared HTTP status enum (e.g. the
     * non-standard 419). Used to derive the default title when no translation
     * exists for the error code.
     *
     * @return \SineMacula\Http\Enums\HttpStatus|null
     */
    public function getStatus(): ?HttpStatus
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
     * @return string
     */
    protected function getNamespace(): string
    {
        return 'api-toolkit';
    }

    /**
     * Get internal error.
     *
     * @return \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface
     *
     * @throws \LogicException
     */
    private static function getInternalError(): ErrorCodeInterface
    {
        if (!defined(static::class . '::CODE')) {
            throw new \LogicException('The CODE constant must be defined on the exception');
        }

        /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface */
        return constant(static::class . '::CODE');
    }

    /**
     * Get HTTP status.
     *
     * Returns null for exceptions that declare no HTTP_STATUS constant, such as
     * those whose status has no case in the shared HTTP status enum.
     *
     * @return \SineMacula\Http\Enums\HttpStatus|null
     */
    private static function getHttpStatus(): ?HttpStatus
    {
        if (!defined(static::class . '::HTTP_STATUS')) {
            return null;
        }

        /** @var \SineMacula\Http\Enums\HttpStatus */
        return constant(static::class . '::HTTP_STATUS');
    }

    /**
     * Derive a human-readable title from the HTTP status.
     *
     * A per-status translation (`exceptions.http.{status}`, or
     * `exceptions.http.unknown` when the status has no enum case) is consulted
     * first so the generic path can be localised; otherwise the title is
     * derived from the status enum case name, with a generic literal fallback
     * ensuring rendering never fails for a missing title.
     *
     * @return string
     */
    private function getDefaultTitle(): string
    {
        $status = $this->getStatus();
        $key    = sprintf('%s::exceptions.http.%s', $this->getNamespace(), $status->value ?? 'unknown');

        if (Lang::has($key)) {
            $translation = Lang::get($key);

            return is_string($translation) ? $translation : '';
        }

        if ($status === null) {
            return 'Unknown Error';
        }

        return ucwords(strtolower(str_replace('_', ' ', $status->name)));
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
