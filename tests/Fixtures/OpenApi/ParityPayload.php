<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Payload materialising the canonical shared rule set for flow parity.
 *
 * Returns the same rule set the plain and FormRequest parity fixtures declare,
 * read through its static rules(), so the resolver can be proven to document a
 * byte-identical body from the Payload discovery path. The promoted properties
 * mirror the rule set so the input stays a legitimate, hydratable Payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParityPayload extends Payload
{
    /**
     * Create a new ParityPayload instance.
     *
     * @param  string  $title
     * @param  string|null  $summary
     * @param  array<int, string>  $tags
     * @param  string|null  $status
     * @param  string  $email
     */
    public function __construct(

        /** The bounded required title. */
        public readonly string $title = '',

        /** The optional nullable summary. */
        public readonly ?string $summary = null,

        /** The typed array of tags. */
        public readonly array $tags = [],

        /** The enumerated status. */
        public readonly ?string $status = null,

        /** The email address. */
        public readonly string $email = '',
    ) {}

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return ParityInput::rules();
    }
}
