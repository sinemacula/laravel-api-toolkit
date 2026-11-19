<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Models\Concerns\ScopesByStart;

/**
 * Fixture gathering model, a soft-deleting dated and located event whose
 * sessions are gatherings under it, declaring the local scopes clients invoke.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $name
 * @property string $visibility
 * @property \Illuminate\Support\Carbon $starts_at
 * @property float $latitude
 * @property float $longitude
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @phpstan-ignore sineMaculaLaravel.modelBehaviour
 */
#[Fillable(['parent_id', 'user_id', 'name', 'visibility', 'starts_at', 'latitude', 'longitude'])]
#[Table('gatherings')]
final class Gathering extends Model
{
    use ScopesByStart, SoftDeletes;

    /** @var bool */
    public $timestamps = false;

    /**
     * Get the user who owns the gathering.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the sessions held under the gathering.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<self, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Determine whether the gathering has started; not a scope, so the prefixed
     * scope of the same name answers the query.
     *
     * @return bool
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    /**
     * Scope the query to gatherings that have not started.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now());
    }

    /**
     * Scope the query to gatherings that have started.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopeHasStarted(Builder $query): void
    {
        $query->where('starts_at', '<=', now());
    }

    /**
     * Scope the query to gatherings the authenticated user owns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopeOwnedByCaller(Builder $query): void
    {
        $query->where('user_id', Auth::id());
    }

    /**
     * Scope the query to gatherings bearing either name.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @param  string  $first
     * @param  string  $second
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopeNamed(Builder $query, string $first, string $second): void
    {
        $query->where('name', $first)->orWhere('name', $second);
    }

    /**
     * Scope the query to the given keys.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @param  int  ...$ids
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopeIdentified(Builder $query, int ...$ids): void
    {
        $query->whereIn('id', $ids);
    }

    /**
     * Scope the query to the given keys, under the given name where one is
     * given.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @param  string|null  $name
     * @param  int  ...$ids
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopeNamedAmong(Builder $query, ?string $name, int ...$ids): void
    {
        $query->whereIn('id', $ids);

        if ($name === null) {
            return;
        }

        $query->where('name', $name);
    }

    /**
     * Scope the query through the builder call the case names, each reaching
     * past the scope's where clauses; any other case returns a builder of its
     * own.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @param  string  $case
     * @return mixed
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopeOverreach(Builder $query, string $case): mixed
    {
        return match ($case) {
            'withTrashed' => $query->withTrashed(), // @phpstan-ignore method.notFound
            'with'        => $query->with('sessions'),
            'orderBy'     => $query->orderBy('name'),
            'join'        => $query->join('users', 'users.id', '=', 'gatherings.user_id'),
            'select'      => $query->select('name'),
            'from'        => $query->from('users'),
            'limit'       => $query->limit(1),
            default       => self::query()->where('name', 'Elsewhere'),
        };
    }

    /**
     * Scope the query through the predicate-only builder call the case names,
     * or refuse it, or add nothing.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @param  string  $case
     * @return mixed
     *
     * @throws \Illuminate\Validation\ValidationException
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function scopePredicate(Builder $query, string $case): mixed
    {
        return match ($case) {
            'whereHas'        => $query->whereHas('user', static fn (Builder $users): Builder => $users->where('status', 'active')),
            'whereInSubquery' => $query->whereIn('user_id', static fn (QueryBuilder $users): QueryBuilder => $users->select('id')->from('users')->where('status', 'active')),
            'whereExists'     => $query->whereExists(static fn (QueryBuilder $users): QueryBuilder => $users->from('users')->whereColumn('users.id', 'gatherings.user_id')),
            'whereRaw'        => $query->whereRaw('latitude > ?', [10]),
            'when'            => $query->when(true, static fn (Builder $query): Builder => $query->where('visibility', 'public')),
            'refuse'          => throw ValidationException::withMessages(['case' => 'Not on this date.']),
            default           => null,
        };
    }

    /**
     * Scope the query to gatherings within a box of km kilometres about the
     * point.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @param  float  $lat
     * @param  float  $lng
     * @param  int  $km
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    #[Scope]
    protected function near(Builder $query, float $lat, float $lng, int $km): void
    {
        $degrees = $km / 111;

        $query->whereBetween('latitude', [$lat - $degrees, $lat + $degrees])
            ->whereBetween('longitude', [$lng - $degrees, $lng + $degrees]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'latitude'  => 'float',
            'longitude' => 'float',
        ];
    }
}
