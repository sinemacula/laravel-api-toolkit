<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Tests\Fixtures\Enums\UserStatus;

class User extends Model
{
    public $timestamps = false;
    protected $table   = 'users';
    protected $guarded = [];
    protected $appends = ['nickname'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_user');
    }

    public function polymorphicTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function explodingRelation(): BelongsTo
    {
        throw new \RuntimeException('Exploding relation should be caught.');
    }

    protected function casts(): array
    {
        return [
            'age'      => 'integer',
            'active'   => 'boolean',
            'meta'     => 'array',
            'settings' => 'object',
            'state'    => UserStatus::class,
            'score'    => 'decimal:2',
        ];
    }

    protected function nickname(): Attribute
    {
        return Attribute::make(
            get: fn () => strtoupper((string) $this->name),
        );
    }
}
