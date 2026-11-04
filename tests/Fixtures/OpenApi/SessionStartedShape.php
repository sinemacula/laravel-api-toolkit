<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape identical to the signed-in shape.
 *
 * A distinct class whose fragment equals the signed-in fragment, so a union
 * naming both proves members are collapsed by the node they resolve to rather
 * than by the class that names them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SessionStartedShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the session-started shape.
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
