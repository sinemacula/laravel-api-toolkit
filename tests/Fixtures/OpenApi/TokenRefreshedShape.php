<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape of a refreshed token.
 *
 * Pins its required status to the signed-in shape's constant, so a union of the
 * two has no discriminator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TokenRefreshedShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the token-refreshed shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status'        => ['const' => 'signed_in'],
                'token'         => ['type' => 'string'],
                'refresh_token' => ['type' => 'string'],
            ],
            'required' => ['status', 'token', 'refresh_token'],
        ];
    }
}
