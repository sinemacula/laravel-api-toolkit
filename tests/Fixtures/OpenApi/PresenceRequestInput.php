<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source relating its fields through presence rules.
 *
 * Declares a required and an optional confirmed field, one naming its own
 * confirmation field, and fields required with or without others, at the top
 * level and inside array items, so the exported body can be checked for the
 * required list, dependentRequired, anyOf, and if/then constraints those rules
 * express.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PresenceRequestInput implements DefinesInputSchema
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
            'password'        => ['required', 'string', 'confirmed'],
            'pin'             => ['sometimes', 'string', 'confirmed:pin_repeat'],
            'credential'      => ['sometimes', 'required', 'string'],
            'member_id'       => ['required_with:credential', 'required_without:group_id', 'nullable', 'uuid'],
            'group_id'        => ['nullable', 'uuid'],
            'address'         => ['required_with_all:credential,group_id', 'string'],
            'email'           => ['required_without_all:member_id,group_id', 'email'],
            'contacts'        => ['array'],
            'contacts.*.id'   => ['required_without:contacts.*.name', 'uuid'],
            'contacts.*.name' => ['string'],
        ];
    }
}
