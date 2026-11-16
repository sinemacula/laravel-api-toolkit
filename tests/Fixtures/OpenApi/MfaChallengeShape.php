<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture self-describing shape of a sign-in awaiting a second factor.
 *
 * Its #[SchemaName] override names its component, and its required status
 * constant discriminates it from the signed-in shape.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[SchemaName('MfaChallenge')]
final class MfaChallengeShape implements DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the MFA challenge shape.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status'  => ['const' => 'mfa_required'],
                'methods' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['status', 'methods'],
        ];
    }
}
