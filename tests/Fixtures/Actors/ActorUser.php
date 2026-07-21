<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Actors;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent + Authenticatable fixture for EloquentActor tests.
 *
 * Backed by the `users` table created in the base TestCase. Named (not
 * anonymous) so PHP can serialise it during queue round-trip tests. The
 * is_admin accessor lets an authenticated-user-keyed guard read a simple admin
 * predicate when this fixture is the actingAs() subject.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ActorUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    /** @var string|null */
    protected $table = 'users';

    /** @var array<int, string> */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * Determine whether the actor is an admin.
     *
     * A fixture-only predicate: an actor is treated as an admin when its email
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
}
