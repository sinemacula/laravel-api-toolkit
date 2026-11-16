<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing DTO shape.
 *
 * Declares its own JSON-Schema fragment, so a non-resource body is exported as
 * one component referenced by every action naming it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WidgetShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the widget shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'    => ['type' => 'integer'],
                'label' => ['type' => 'string'],
            ],
            'required' => ['id', 'label'],
        ];
    }
}
