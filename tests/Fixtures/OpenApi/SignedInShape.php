<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape of a completed sign-in.
 *
 * One member of a union of response shapes, told apart from its sibling by the
 * constant its required status property carries.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SignedInShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the signed-in shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => ['const' => 'signed_in'],
                'token'  => ['type' => 'string'],
            ],
            'required' => ['status', 'token'],
        ];
    }
}
