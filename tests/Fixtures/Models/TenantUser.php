<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
