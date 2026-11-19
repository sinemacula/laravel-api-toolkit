<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture shape requiring a field it does not document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UndocumentedFieldShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'   => ['type' => 'integer'],
                'meta' => ['x-undocumented' => true],
            ],
            'required' => ['id', 'meta'],
        ];
    }
}
