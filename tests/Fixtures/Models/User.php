<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Fixture user model.
 *
 * Implements Authenticatable so tests can drive it through actingAs(). The
 * is_admin accessor lets an authenticated-user-keyed field guard read a simple
 * admin predicate without a dedicated column.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string $status
 * @property bool $is_admin
 * @property \Tests\Fixtures\Models\Organization|null $organization
 *
 * @formatter:off
 *
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static static first(array<int, string>|string $columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string|array<string, mixed> $column, mixed $operator = null, mixed $value = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static> withCount(mixed $relations)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, static> paginate(int|null $perPage = null)
 * @method static static findOrFail(mixed $id)
 *
 * @formatter:on
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class User extends Model implements AuthenticatableContract
{
    use Authenticatable;

    /** @var string|null */
    protected $table = 'users';

    /** @var array<int, string> */
    protected $fillable = ['name', 'email', 'password', 'status', 'organization_id'];

    /**
     * Get the organization that the user belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user's profile.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\Tests\Fixtures\Models\Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Get the user's posts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get the user's full label as a computed Eloquent attribute.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    public function fullLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->name . ' <' . $this->email . '>',
        );
    }

    /**
     * Determine whether the user is an admin.
     *
     * A fixture-only predicate: a user is treated as an admin when its email
     * begins with "admin@", so an authenticated-user-keyed guard can toggle
     * without a dedicated column.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    public function isAdmin(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => str_starts_with($this->email, 'admin@'),
        );
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
            'status' => UserStatus::class,
        ];
    }
}
