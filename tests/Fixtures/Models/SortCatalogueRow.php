<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model behind a table whose indexes separate what an ordered read
 * needs from what merely covers a column.
 *
 * The table carries one index leading with `label`, one composite index naming
 * `status` before `ranking`, and one index over `body` of a kind that holds no
 * order. Its catalogue therefore answers every question the sort backing rule
 * asks, with the kinds and the column order coming from the engine rather than
 * from a fixture array. The table is created by the suite that reads it, since
 * only an engine naming index kinds can carry the third index at all.
 *
 * @property int $id
 * @property string $label
 * @property string $status
 * @property int $ranking
 * @property string $body
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SortCatalogueRow extends Model
{
    /** @var bool */
    public $timestamps = false;
}
