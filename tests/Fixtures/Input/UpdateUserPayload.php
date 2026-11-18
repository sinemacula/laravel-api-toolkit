<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Payload fixture for a partial update, where every field is optional.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UpdateUserPayload extends Payload
{
    /**
     * Create a new UpdateUserPayload instance.
     *
     * @param  string|null  $name
     * @param  string|null  $email
     */
    public function __construct(

        /** The new name, if one was sent. */
        public readonly ?string $name = null,

        /** The new email address, if one was sent. */
        public readonly ?string $email = null,
    ) {}

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [
            'name'  => ['sometimes', 'string'],
            'email' => ['sometimes', 'email'],
        ];
    }
}
