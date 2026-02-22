<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    public $timestamps = false;
    protected $table   = 'organizations';
    protected $guarded = [];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
