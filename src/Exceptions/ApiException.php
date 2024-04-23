<?php

namespace SineMacula\ApiToolkit\Exceptions;

use Exception;
use Throwable;

/**
 * The API exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class ApiException extends Exception
{
    /**
     * Constructor.
     *
     * @param  array  $type
     * @param  array|null  $meta
     * @param  array|null  $headers
     * @param  \Throwable|null  $previous
     *
     * @throws \Throwable
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
     * Get custom Detail.
     *
     * @return string
     */
    public function getCustomDetail(): string
    {
        return app('translator')->get('exceptions.' . $this->type['code'] . '.detail');
    }

    /**
     * Get status Code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->type['status'] ?? 400;
    }

    /**
     * Get custom Code.
     *
     * @return int
     */
    public function getCustomCode(): int
    {
        return $this->type['code'] ?? 0;
    }

    /**
     * Get custom Title.
     *
     * @return string
     */
    public function getCustomTitle(): string
    {
        return app('translator')->get('exceptions.' . $this->type['code'] . '.title');
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
        return (array) $this->headers;
    }
}
