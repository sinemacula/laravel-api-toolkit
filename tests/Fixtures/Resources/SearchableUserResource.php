<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource that declares a searchable surface.
 *
 * Each strategy appears at least once and one of them twice, so a plan built
 * from this resource carries both the column-to-strategy mapping and more than
 * one column under a single strategy. `status` is presented but never
 * searchable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SearchableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'searchable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->searchable(SearchStrategy::EXACT),
            Field::scalar('name')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('email')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('status'),
        );
    }
}
