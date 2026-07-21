<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Console;

use Illuminate\Console\Command;
use Tests\Fixtures\Repositories\DeferrableUserRepository;

/**
 * Fixture command that defers user rows through a deferrable repository.
 *
 * The rows are buffered in the scoped write pool during handle() and are only
 * persisted when the framework fires the command-finished boundary, so a real
 * Artisan run can prove the boundary flush without any manual event dispatch.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeferUsersCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'fixtures:defer-users {--count=2 : The number of user rows to defer}';

    /** @var string The console command description. */
    protected $description = 'Defer a number of user rows through the deferrable repository';

    /**
     * Execute the console command.
     *
     * @param  \Tests\Fixtures\Repositories\DeferrableUserRepository  $repository
     * @return int
     */
    public function handle(DeferrableUserRepository $repository): int
    {
        $count = (int) $this->option('count');

        for ($index = 1; $index <= $count; $index++) {
            $repository->defer([
                'name'  => 'Deferred ' . $index,
                'email' => 'deferred-' . $index . '@console.test',
            ]);
        }

        return self::SUCCESS;
    }
}
