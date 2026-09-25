<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model read through a connection a tenancy switcher repoints.
 *
 * The connection name stays the same while the database, prefix, or search path
 * behind it changes, which is what the schema identity has to follow.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('widgets')]
final class TenantWidget extends Model
{
    /** @var string|\UnitEnum|null */
    protected $connection = 'tenant';
}
