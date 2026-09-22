<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model behind a table whose indexes separate what a catalogue reports
 * from what the engine will actually serve.
 *
 * The indexes are created by the suite that reads them, one shape per test, so
 * an index over an expression and one over part of a table are written in the
 * engine's own dialect rather than through the shared migrations. It carries a
 * table of its own rather than sharing the catalogue suite's, because both
 * suites build their tables outside a transaction and would otherwise drop each
 * other's out from under a parallel run.
 *
 * @property int $id
 * @property string $label
 * @property string $status
 * @property string|null $body
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SortEligibilityRow extends Model
{
    /** @var bool */
    public $timestamps = false;
}
