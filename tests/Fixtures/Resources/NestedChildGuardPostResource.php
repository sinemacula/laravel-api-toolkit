<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture post resource embedding a child that declares its own field guard.
 *
 * The user relation is wrapped by GuardedUserResource, whose email field
 * carries a request-scoped guard, so the parent's response can prove the
 * child's guarded field is hidden or shown per the same request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NestedChildGuardPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'nested_child_guard_posts';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'user'];

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
            Relation::to('user', GuardedUserResource::class),
        );
    }
}
