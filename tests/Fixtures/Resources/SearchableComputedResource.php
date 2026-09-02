<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource that declares a computed field searchable.
 *
 * The compute value is a real method on the resource, so the only defect is the
 * search declaration itself: `full_name` is assembled here and has no column on
 * the users table to search against.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SearchableComputedResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'searchable_computed';

    /** @var array<int, string> */
    protected static array $default = ['id', 'full_name'];

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
            Field::compute('full_name', 'getFullName')->searchable(SearchStrategy::SUBSTRING),
        );
    }

    /**
     * Return the assembled full name.
     *
     * @return string
     */
    public function getFullName(): string
    {
        $user = $this->resource;

        assert($user instanceof User);

        return $user->name;
    }
}
