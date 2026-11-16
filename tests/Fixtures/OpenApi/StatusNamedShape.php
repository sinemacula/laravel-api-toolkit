<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape whose #[SchemaName] override takes the user
 * status enum's component name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[SchemaName('UserStatus')]
final class StatusNamedShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the colliding shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required'   => ['id'],
        ];
    }
}
