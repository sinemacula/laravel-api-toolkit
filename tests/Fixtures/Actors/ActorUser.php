<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Actors;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent + Authenticatable fixture for EloquentActor tests.
 *
 * Backed by the `users` table created in the base TestCase. Named (not
 * anonymous) so PHP can serialise it during queue round-trip tests.
 *
 * @property int $id
 * @property string $name
 * @property string $email
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
}
