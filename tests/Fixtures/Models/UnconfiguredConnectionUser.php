<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model naming a connection the application does not configure.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('users')]
final class UnconfiguredConnectionUser extends Model
{
    /** @var string|\UnitEnum|null */
    protected $connection = 'unconfigured';
}
