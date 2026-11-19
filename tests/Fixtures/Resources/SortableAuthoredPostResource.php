<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture post resource ordered by its author, who may be missing, or by
 * whether it is published.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SortableAuthoredPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'sortable_posts';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'author'];

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
            Field::scalar('title'),
            Field::scalar('published')->sortable(),
            Relation::to('author', SortableUserResource::class)->sortable('Back-office post list'),
        );
    }
}
