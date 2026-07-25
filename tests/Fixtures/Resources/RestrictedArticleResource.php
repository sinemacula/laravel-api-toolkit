<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture article resource with soft-delete visibility withheld.
 *
 * Maps the soft-deleting Article model without opting in to trashed visibility,
 * so it exercises the safe-by-default closed gate: a `?trashed=with` request
 * must never surface soft-deleted rows through this resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RestrictedArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'restricted_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'status'];

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
            Field::scalar('status'),
        );
    }
}
