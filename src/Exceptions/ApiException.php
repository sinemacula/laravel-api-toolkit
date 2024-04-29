<?php

namespace SineMacula\ApiToolkit\Exceptions;

use Exception;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * The base API exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class ApiException extends Exception
{
    /**
     * Constructor.
     *
     * @param  array  $type
     * @param  array|null  $meta
     * @param  array|null  $headers
     * @param  \Throwable|null  $previous
     */
    public function __construct(

        /** Exception type */
        private readonly array $type,

        /** Exception meta */
        private readonly ?array $meta = null,

        /** Exception headers */
        private readonly ?array $headers = null,

        ?Throwable $previous = null

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
        return Lang::get($this->getTranslationKey('detail'));
    }

    /**
     * Get exception HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return (int) $this->type['status'] ?? 400;
    }

    /**
     * Get the internal error code
     *
     * @return int
     */
    public function getCustomCode(): int
    {
        return (int) $this->type['code'] ?? 0;
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
     * @return array|null
     */
    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    /**
     * Get the namespace of the current exception.
     *
     * @return string|null
     */
    abstract protected function getNamespace(): ?string;

    /**
     * Return the translation key for the given key.
     *
     * @param  string  $key
     * @return string
     */
    private function getTranslationKey(string $key): string
    {
        $namespace = $this->getNamespace();

        $prefix = $namespace ? $namespace . '::' : '';

        return sprintf('%sexceptions.%s.%s', $prefix, $this->getCustomCode(), $key);
    }
}
