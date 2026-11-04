<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape of a sign-in still awaiting a choice.
 *
 * One member of a union of response shapes, told apart from its sibling by the
 * constant its required status property carries.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PendingSignInShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the pending sign-in shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status'  => ['const' => 'pending'],
                'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['status', 'choices'],
        ];
    }
}
