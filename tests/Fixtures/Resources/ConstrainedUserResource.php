<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource whose posts relation is eager-load constrained.
 *
 * The posts relation carries a constraint closure that limits the eager load to
 * published posts, so the whole collection resolves through one constrained
 * sub-query rather than a per-row filtered load.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConstrainedUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'constrained_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name'];

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
            Field::scalar('name'),
            Relation::to('posts', PostResource::class)->constrain(
                static function (Builder $query): void {
                    $query->where('published', true);
                },
            ),
        );
    }
}
