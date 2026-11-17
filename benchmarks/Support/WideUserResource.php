<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Average;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Max;
use SineMacula\ApiToolkit\Schema\Min;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;

/**
 * User resource declaring 30 fields of every kind, filterable, sortable and
 * traversable ones and six aggregates among them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WideUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'wide_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        $computed = array_map(
            static fn (int $index): Field => Field::compute('computed_' . $index, static fn (): int => $index),
            range(1, 13),
        );

        return Field::set(
            Field::scalar('id')->filterable(Capability::RANGE)->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable(),
            Field::scalar('email')->filterable(Capability::EXACT)->sortable(),
            Field::scalar('status')->filterable(Capability::ENUM),
            Field::scalar('organization_id')->filterable(Capability::EXACT),
            Field::timestamp('created_at')->sortable(),
            Field::timestamp('updated_at')->sortable(),
            Field::accessor('display_name', static fn (): string => '')->needs('name', 'email'),
            Relation::to('organization', OrganizationResource::class)->traversable(),
            Relation::to('posts', PostResource::class)->traversable(),
            Count::of('posts')->default(),
            Exists::of('posts', 'has_posts'),
            Sum::of('posts', 'id'),
            Average::of('posts', 'id'),
            Min::of('posts', 'created_at'),
            Max::of('posts', 'created_at', 'last_posted_at'),
            ...$computed,
        );
    }
}
