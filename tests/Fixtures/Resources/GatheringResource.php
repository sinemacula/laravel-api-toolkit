<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Scope;

/**
 * Fixture gathering resource declaring the named scopes a filter may invoke.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GatheringResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'gatherings';

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
            Field::scalar('visibility')->filterable(Capability::EXACT),
            Field::timestamp('starts_at'),
            Relation::to('sessions', GatheringSessionResource::class)->traversable(),
            Scope::of('upcoming')->description('Gatherings that have not started yet.'),
            Scope::of('near')->parameters([
                'lat' => ['required', Rule::numeric()->between(-90, 90)],
                'lng' => ['required', Rule::numeric()->between(-180, 180)],
                'km'  => ['required', 'integer', 'between:1,50'],
            ])->description('Gatherings within km kilometres of the point.'),
            Scope::of('ownedByCaller', 'mine')->visibleTo(static fn (?Request $request): bool => $request?->user() !== null),
        );
    }
}
