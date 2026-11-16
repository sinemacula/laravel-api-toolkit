<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model with an update timestamp only, stored in its own date format.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('readings', dateFormat: 'U')]
final class Reading extends Model
{
    /** @var string|null */
    public const ?string CREATED_AT = null;
}
