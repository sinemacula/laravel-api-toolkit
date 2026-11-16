<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model read through a connection each test points where it needs.
 *
 * The table may be migrated, missing, or behind a connection that cannot be
 * read or is not configured at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CatalogueRow extends Model
{
    /** @var string|\UnitEnum|null */
    protected $connection = 'catalogue';
}
