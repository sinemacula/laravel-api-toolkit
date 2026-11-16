<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi\Alternate;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape sharing the signed-in shape's short name.
 *
 * Both derive one component name, so a document naming both fails the export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SignedInShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the alternate signed-in shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status'  => ['const' => 'signed_in'],
                'session' => ['type' => 'string'],
            ],
            'required' => ['status', 'session'],
        ];
    }
}
