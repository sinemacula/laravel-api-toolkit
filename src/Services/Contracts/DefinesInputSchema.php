<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

/**
 * Single static source of truth for a request's validation rules.
 *
 * The rules are declared statically so the OpenAPI exporter can read them
 * directly, with no faked request or hydrated instance, which keeps the
 * generated document a faithful reflection of what the code enforces. A Payload
 * delegates to this contract, a FormRequest can satisfy it, and the
 * RequestSchema attribute points at an implementer of it, so every input path
 * shares one authoritative rule set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array;
}
