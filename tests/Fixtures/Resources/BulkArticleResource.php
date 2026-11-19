<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Fixture article resource declaring every query surface over a soft-deleting
 * model whose trashed gate is open, so one request can select rows and shape a
 * page at once.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BulkArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'bulk_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'status'];

    /**
     * Open the trashed gate.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     *
     * @SuppressWarnings("php:S1172")
     */
    #[\Override]
    public static function allowsTrashed(Request $request): bool
    {
        return true;
    }

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
            Field::scalar('title')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('status')->filterable(Capability::ENUM),
            Field::scalar('views')->filterable(Capability::RANGE)->sortable(),
            Relation::to('author', UserResource::class),
            Count::of('authorComments'),
            Sum::of('authorComments', 'id'),
        );
    }
}
