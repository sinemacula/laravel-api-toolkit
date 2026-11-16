<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixture soft-deleting model whose trashed-inclusive route binding looks it up
 * by a value other than its key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('shipments')]
final class Shipment extends Model
{
    use SoftDeletes;

    /**
     * Retrieve the model for a bound value, including trashed models.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    #[\Override]
    public function resolveSoftDeletableRouteBinding(mixed $value, $field = null): ?Model
    {
        return self::withTrashed()->where('tracking_number', $value)->first();
    }
}
