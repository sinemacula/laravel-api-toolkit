<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Support\CurrentTenant;

/**
 * Fixture post model behind a tenant global scope, so a post can belong to a
 * user of another tenant and still be hidden from that user's tenant.
 *
 * Its table is created by the test that reads it.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $title
 * @property int $words
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['organization_id', 'user_id', 'title', 'words'])]
#[Table('tenant_posts')]
final class TenantPost extends Model
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
            $query->where($query->qualifyColumn('organization_id'), app(CurrentTenant::class)->organizationId);
        });
    }
}
