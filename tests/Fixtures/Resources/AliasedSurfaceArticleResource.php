<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\Article;

/**
 * Fixture article resource presenting two queried columns under an alias.
 *
 * The slug is carried in the response as `permalink` and the status as `state`,
 * while both are filtered, ordered, and gated by the column name the articles
 * table holds. A document walked over this resource therefore has to advertise
 * a key that is not the property it hangs on, and a request has to send that
 * key rather than the name it reads the value back under.
 *
 * Each aliased field reads its value through an accessor declaring the column
 * it needs, so a narrowed SELECT asks for the column rather than for the
 * property name, which the table does not carry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AliasedSurfaceArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'aliased_surface_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'permalink', 'state'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable(Capability::RANGE)->sortable(),
            Field::accessor('slug', static function ($resource): string {

                $article = $resource->resource;

                assert($article instanceof Article);

                return $article->slug;
            }, 'permalink')->needs('slug')->filterable(Capability::EXACT)->sortable(),
            Field::accessor('status', static function ($resource): string {

                $article = $resource->resource;

                assert($article instanceof Article);

                return $article->status;
            }, 'state')->needs('status')->filterable(Capability::ENUM),
        );
    }
}
