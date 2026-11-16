<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Fixture related model whose casts and accessors decide how a minimum or a
 * maximum over its columns reads back.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[Table('aggregate_lines')]
final class AggregateLine extends Model
{
    /** @var string|null */
    protected $dateFormat = 'd/m/Y H:i';

    /**
     * Read the legacy code column through an accessor.
     *
     * @param  mixed  $value
     * @return string
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function getCodeAttribute(mixed $value): string
    {
        return 'code-' . (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Read the label column through an attribute accessor.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function label(): Attribute
    {
        return Attribute::make(get: static fn (mixed $value): string => strtoupper(is_scalar($value) ? (string) $value : ''));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'price'      => 'decimal:2',
            'quantity'   => 'integer',
            'shipped_on' => 'date',
            'status'     => UserStatus::class,
            'platform'   => Platform::class,
            'secret'     => 'encrypted',
            'payload'    => 'array',
            'weight'     => 'Float',
        ];
    }
}
