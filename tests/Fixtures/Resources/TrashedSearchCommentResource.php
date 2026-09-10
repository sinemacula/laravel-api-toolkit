<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture comment resource opting in to soft-delete visibility.
 *
 * Gives the searched-parent suite a soft-deleting child whose gate is open, so
 * the relation cascade can be observed on a request that also carries a search
 * term against the parent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TrashedSearchCommentResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'trashed_search_comments';

    /** @var array<int, string> */
    protected static array $default = ['id', 'body'];

    /**
     * Opt in to soft-delete visibility so the cascade reaches this relation.
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
            Field::scalar('body'),
        );
    }
}
