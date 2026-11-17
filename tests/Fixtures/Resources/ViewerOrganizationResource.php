<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture organization resource whose relations depend on who is asking.
 *
 * The posts constraint reads the authenticated user when the eager load runs,
 * and the members relation resolves to a tenant-scoped model, so neither is
 * visible in the schema declared here.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ViewerOrganizationResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'viewer_organizations';

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
            Field::scalar('name'),
            Relation::to('posts', PostResource::class)->constrain(
                static function (Builder $query): void {
                    $query->where('posts.user_id', Auth::id());
                },
            ),
            Relation::to('members', FilterableUserResource::class),
        );
    }
}
