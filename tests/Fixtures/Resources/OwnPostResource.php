<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture post resource exposing only the posts the caller wrote.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @inheritable
 */
class OwnPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'own_posts';

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
            Field::scalar('title')->filterable(Capability::EXACT),
            Field::scalar('published'),
            Relation::to('tags', PostTagResource::class),
        );
    }

    /**
     * Restrict the posts to the caller's own, and to none without a caller.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    #[\Override]
    public static function restrictRows(Builder $query, Request $request): void
    {
        $user = $request->user();

        $query->whereIn($query->qualifyColumn('user_id'), $user instanceof Authenticatable ? [$user->getAuthIdentifier()] : []);
    }
}
