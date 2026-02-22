<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphedByMany;

class Tag extends Model
{
    public $timestamps = false;
    protected $table   = 'tags';
    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tag_user');
    }

    public function taggedUsers(): MorphedByMany
    {
        return $this->morphedByMany(User::class, 'taggable');
    }
}
