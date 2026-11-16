<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape pinning its version to the integer 1.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class IntegerVersionShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the integer-versioned shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['version' => ['const' => 1]],
            'required'   => ['version'],
        ];
    }
}
