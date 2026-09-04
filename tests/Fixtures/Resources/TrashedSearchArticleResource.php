<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture article resource declaring a search surface over a soft-deleting
 * model whose trashed gate is open.
 *
 * The only fixture where the two surfaces meet: `title` is searchable, the
 * model soft deletes, and the gate opts in, so one request can carry a term and
 * a trashed widening together. A filterable pair rides alongside so the search
 * group, the filter group, and the visibility scope can be proven to compose
 * rather than to substitute for one another.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TrashedSearchArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'trashed_search_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'status', 'views'];

    /**
     * Opt in to soft-delete visibility so a search can be driven beside a
     * widened scope.
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
            Field::scalar('views')->filterable(Capability::RANGE),
        );
    }
}
