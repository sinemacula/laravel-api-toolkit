<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture post resource embedding a to-one relation of each kind.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AuthoredPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'authored_posts';

    /** @var array<int, string> */
    protected static array $default = ['id'];

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
            Relation::to('author', ProfiledUserResource::class),
            Relation::to('profile', ProfileResource::class),
            Relation::to('authorProfile', ProfileResource::class, 'author_profile'),
            Relation::to('pinnedNote', NoteResource::class, 'pinned_note'),
        );
    }
}
