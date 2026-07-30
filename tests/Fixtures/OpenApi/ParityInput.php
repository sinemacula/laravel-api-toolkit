<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Canonical shared rule set materialised as a plain self-describing input.
 *
 * Holds the one rule set the request-body flow-parity test exercises through
 * every source: a bounded required string, a nullable string, a typed array
 * with typed items, an enumerated field, and an email. The FormRequest and
 * Payload parity fixtures delegate to this rule set so all four discovery paths
 * are proven to document byte-identical bodies from a single authoritative
 * source.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParityInput implements DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [
            'title'   => 'required|string|max:255',
            'summary' => 'nullable|string',
            'tags'    => 'array',
            'tags.*'  => 'string',
            'status'  => 'in:active,inactive,banned',
            'email'   => 'required|string|email',
        ];
    }
}
