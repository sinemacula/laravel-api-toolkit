<?php

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixture organization model.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class Organization extends Model
{
    /** @var string|null */
    protected $table = 'organizations';

    /** @var array<int, string> */
    protected $fillable = ['name', 'slug'];

    /**
     * Get the organization's users.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
