<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture post resource exposing only published posts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PublishedPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'published_posts';

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
        );
    }

    /**
     * Restrict the posts to the published ones.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    #[\Override]
    public static function restrictRows(Builder $query, Request $request): void
    {
        $query->where($query->qualifyColumn('published'), true);
    }
}
