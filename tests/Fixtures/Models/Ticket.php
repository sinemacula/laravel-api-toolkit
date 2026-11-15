<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Enums\TicketPriority;

/**
 * Fixture UUID-keyed ticket model.
 *
 * @property string $id
 * @property string $code
 * @property \Tests\Fixtures\Enums\TicketPriority $priority
 * @property array<string, mixed>|null $meta
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['code', 'priority'])]
#[Table('tickets')]
final class Ticket extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'meta'     => 'array',
        ];
    }
}
