<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Scope;

/**
 * Fixture user resource declaring every field, relation, aggregate and scope
 * the API documentation's examples name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ManualUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'user';

    /** @var array<int, string> */
    protected static array $default = ['id', 'first_name', 'last_name', 'created_at'];

    /**
     * Expose soft-deleted users to the trashed parameter.
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
            Field::scalar('name')->sortable()->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('first_name')->filterable(Capability::EXACT),
            Field::scalar('last_name')->filterable(Capability::EXACT),
            Field::scalar('email')->filterable(Capability::EXACT)->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('age')->filterable(Capability::RANGE),
            Field::scalar('status')->filterable(Capability::EXACT),
            Field::scalar('tags')->filterable(Capability::DOCUMENT),
            Field::timestamp('created_at')->filterable(Capability::RANGE)->sortable(),
            Relation::to('organization', ManualOrganizationResource::class)->sortable('Admin list of a few hundred users'),
            Relation::to('posts', PostResource::class)->traversable(),
            Relation::to('comments', ManualCommentResource::class)->traversable(),
            Count::of('posts')->sortable('Admin list of a few hundred users'),
            Scope::of('upcoming'),
            Scope::of('near')->parameters([
                'lat' => ['required', Rule::numeric()->between(-90, 90)],
                'lng' => ['required', Rule::numeric()->between(-180, 180)],
                'km'  => ['required', 'integer', 'between:1,50'],
            ]),
        );
    }
}
