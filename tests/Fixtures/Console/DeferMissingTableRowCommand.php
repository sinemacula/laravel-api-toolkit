<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Console;

use Illuminate\Console\Command;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;

/**
 * Fixture command that defers a row bound for a table that does not exist.
 *
 * The row stays buffered in the scoped write pool until the command-finished
 * boundary flushes it, so a real Artisan run can prove how that boundary flush
 * failure surfaces.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeferMissingTableRowCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'fixtures:defer-missing-table-row';

    /** @var string The console command description. */
    protected $description = 'Defer a row bound for a table that does not exist';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePool  $pool
     * @return int
     */
    public function handle(WritePool $pool): int
    {
        $pool->add('nonexistent_table', ['col' => 'val']);

        return self::SUCCESS;
    }
}
