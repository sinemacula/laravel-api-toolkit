<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource whose posts are governed by a resource traversing
 * onward to their author.
 *
 * Filters `created_at`, which the author's declared resource does not, and not
 * `status`, which it does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeepTraversalUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'deep_traversal_users';

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
            Field::scalar('name')->filterable(Capability::EXACT),
            Field::timestamp('created_at')->filterable(Capability::RANGE),
            Relation::to('posts', DeepTraversalPostResource::class)->traversable(),
        );
    }
}
