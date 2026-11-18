<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture post resource embedding its author with pinned fields.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PinnedAuthorPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'pinned_posts';

    /** @var array<int, string> */
    protected static array $default = ['title', 'user'];

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
            Relation::to('user', UserResource::class)->fields(['name']),
        );
    }
}
