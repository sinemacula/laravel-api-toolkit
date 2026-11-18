<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Payload fixture whose rule lets a field be absent that its constructor
 * requires.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OptionalRulePayload extends Payload
{
    /**
     * Create a new OptionalRulePayload instance.
     *
     * @param  string  $displayName
     */
    public function __construct(

        /** The display name; required here but optional in the rules. */
        public readonly string $displayName,
    ) {}

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return ['display_name' => ['sometimes', 'string']];
    }
}
