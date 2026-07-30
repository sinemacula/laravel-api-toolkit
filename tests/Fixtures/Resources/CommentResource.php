<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture comment resource with soft-delete visibility withheld.
 *
 * Maps the soft-deleting Comment model without opting in to trashed visibility,
 * so an eager load through this resource keeps soft-deleted comments hidden
 * even when the same request opens a sibling relation's gate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CommentResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'comments';

    /** @var array<int, string> */
    protected static array $default = ['id', 'body'];

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
            Field::scalar('body'),
        );
    }
}
