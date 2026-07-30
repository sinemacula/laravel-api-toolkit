<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Payload fixture that redacts a sensitive field in its inputSummary.
 *
 * Overrides toArray() so the password never leaves the input as a raw value,
 * proving the scrubbing seam that keeps secrets out of the lifecycle events.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ScrubbedInput extends Payload
{
    /** Placeholder substituted for the sensitive field. */
    public const string REDACTION = '[redacted]';

    /**
     * Create a new ScrubbedInput instance.
     *
     * @param  string  $username
     * @param  string  $password
     */
    public function __construct(

        /** The username; exposed verbatim in the summary. */
        public readonly string $username = '',

        /** The password; never exposed in the summary. */
        public readonly string $password = '',
    ) {}

    /**
     * Return the input snapshot with the password redacted.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'password' => self::REDACTION,
        ];
    }
}
