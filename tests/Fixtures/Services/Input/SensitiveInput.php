<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Payload fixture that marks its secret with #[\SensitiveParameter].
 *
 * Relies on the base toArray() rather than an override, proving the attribute
 * alone keeps the password out of the lifecycle-event inputSummary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SensitiveInput extends Payload
{
    /**
     * Create a new SensitiveInput instance.
     *
     * @param  string  $username
     * @param  string  $password
     */
    public function __construct(

        /** The username; exposed verbatim in the summary. */
        public readonly string $username = '',

        /** The password; left out of the summary. */
        #[\SensitiveParameter]
        public readonly string $password = '',
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
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
