<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model whose route binding looks it up by a value other than its key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('invoices')]
final class Invoice extends Model
{
    /**
     * Retrieve the model for a bound value.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    #[\Override]
    public function resolveRouteBinding(mixed $value, $field = null): ?Model
    {
        return $this->newQuery()->where('number', $value)->first();
    }
}
