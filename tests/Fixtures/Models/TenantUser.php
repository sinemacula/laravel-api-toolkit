<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\Fixtures\Support\CurrentTenant;

/**
 * Fixture user model behind a tenant global scope.
 *
 * The scope reads the tenant bound in the container when the query runs, so the
 * same repository call returns different rows for each tenant.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Database\Eloquent\Collection<int, \Tests\Fixtures\Models\TenantPost> $posts
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['name', 'email', 'organization_id'])]
#[Table('users')]
final class TenantUser extends Model
{
    /**
     * Get the user's posts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\TenantPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(TenantPost::class, 'user_id');
    }

    /**
     * Register the tenant global scope.
     *
     * @return void
     */
    #[\Override]
    protected static function booted(): void
    {
        self::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where('organization_id', app(CurrentTenant::class)->organizationId);
        });
    }
}
