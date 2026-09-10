<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture author resource declaring a search surface over a parent that eager
 * loads a soft-deleting child.
 *
 * The parent itself never soft deletes, so a request carrying both parameters
 * exercises the one path where the term narrows the root and the trashed state
 * is spent entirely on the relation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TrashedSearchAuthorResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'trashed_search_authors';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'comments'];

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
            Field::scalar('name')->searchable(SearchStrategy::SUBSTRING),
            Relation::to('comments', TrashedSearchCommentResource::class),
        );
    }
}
