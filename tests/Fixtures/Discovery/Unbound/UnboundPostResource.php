<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Unbound;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Resources\TagResource;

/**
 * Fixture resource no model binds, embedding a resource that is neither mapped
 * nor discovered.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UnboundPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'unbound_posts';

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Relation::to('tags', TagResource::class),
        );
    }
}
