<?php

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixture organization model.
 *
 * @method static static create(array $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class Organization extends Model
{
    /** @var string */
    protected $table = 'organizations';

    /** @var array<int, string> */
    protected $fillable = ['name', 'slug'];

    /**
     * Get the organization's users.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
