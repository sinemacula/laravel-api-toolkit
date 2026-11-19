<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Contract for an ApiException that describes the meta its error carries.
 *
 * The exported component of the exception's error code types `error.meta` with
 * this schema, else as an open object. Every exception sharing the code must
 * declare the same schema, as a subclass inheriting it does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DefinesErrorMeta
{
    /**
     * Return the JSON-Schema object describing the error meta.
     *
     * @return array<string, mixed>
     */
    public static function metaSchema(): array;
}
