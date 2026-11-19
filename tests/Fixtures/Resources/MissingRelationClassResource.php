<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Fixture resource whose relation names a resource class that does not exist.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MissingRelationClassResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'missing_relation_class';

    /** @var array<int, string> */
    protected static array $default = ['id'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'id'           => [],
            'organization' => ['relation' => 'organization', 'resource' => 'Tests\Fixtures\Resources\MissingResource'],
        ];
    }
}
