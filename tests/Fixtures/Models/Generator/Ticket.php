<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models\Generator;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model keyed by a uuid column.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('tickets', key: 'uuid', keyType: 'string', incrementing: false)]
final class Ticket extends Model {}
