<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture post resource rendering pivot columns on its tags.
 *
 * `tags` hides tags without a role and is bounded per post; `typed_tags` reads
 * its columns through a casting pivot loaded under its own accessor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PivotPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'pivot_posts';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title'];

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
            Relation::to('tags', TagResource::class)
                ->pivot('role')
                ->constrain(static function (Builder $tags): void {
                    assert($tags instanceof BelongsToMany);
                    $tags->wherePivotNotNull('role');
                })
                ->limit(2),
            Relation::to('typedTags', TagResource::class, 'typed_tags')->pivot('role', 'tagged_at'),
        );
    }
}
