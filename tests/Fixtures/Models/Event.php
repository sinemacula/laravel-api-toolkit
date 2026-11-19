<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture event model, stamped to the microsecond.
 *
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon $occurred_at
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['name', 'occurred_at'])]
#[Table('events', timestamps: false, dateFormat: 'Y-m-d H:i:s.u')]
final class Event extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
