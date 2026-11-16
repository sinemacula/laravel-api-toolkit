<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture backed enum that also describes its own schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum SelfDescribingPlan: string implements DefinesSchema
{
    case FREE = 'free';

    /**
     * Return the JSON-Schema fragment describing the plan.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return ['type' => 'object', 'properties' => ['plan' => ['type' => 'string']]];
    }
}
