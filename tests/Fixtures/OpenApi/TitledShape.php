<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape declaring its own title.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TitledShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the titled shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'  => 'object',
            'title' => 'Titled shape',
        ];
    }
}
