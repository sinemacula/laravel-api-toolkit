<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use SineMacula\ApiToolkit\Services\Input\Payload;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\TicketPriority;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Payload of ten fields, four of them string-backed, int-backed and pure enums.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ProfilePayload extends Payload
{
    /**
     * Constructor.
     *
     * @param  string  $name
     * @param  string  $email
     * @param  string  $phone
     * @param  string  $city
     * @param  string  $country
     * @param  string|null  $bio
     * @param  \Tests\Fixtures\Enums\UserStatus  $status
     * @param  \Tests\Fixtures\Enums\TicketPriority  $priority
     * @param  \Tests\Fixtures\Enums\UserLevel  $level
     * @param  \Tests\Fixtures\Enums\Platform  $platform
     */
    public function __construct(

        /** The display name. */
        public readonly string $name,

        /** The contact email address. */
        public readonly string $email,

        /** The contact phone number. */
        public readonly string $phone,

        /** The home city. */
        public readonly string $city,

        /** The ISO 3166-1 alpha-2 country code. */
        public readonly string $country,

        /** The optional biography. */
        public readonly ?string $bio,

        /** A string-backed enum. */
        public readonly UserStatus $status,

        /** Another string-backed enum. */
        public readonly TicketPriority $priority,

        /** An int-backed enum. */
        public readonly UserLevel $level,

        /** A pure enum, read by its lowercase case name. */
        public readonly Platform $platform,
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
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email'],
            'phone'    => ['required', 'string', 'max:32'],
            'city'     => ['required', 'string', 'max:64'],
            'country'  => ['required', 'string', 'size:2'],
            'bio'      => ['nullable', 'string', 'max:1000'],
            'status'   => ['required'],
            'priority' => ['required'],
            'level'    => ['required'],
            'platform' => ['required'],
        ];
    }
}
