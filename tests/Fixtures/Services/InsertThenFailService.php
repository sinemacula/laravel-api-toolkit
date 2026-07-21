<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that inserts a row inside the transaction and then throws.
 *
 * handle() writes a users row and then raises, so the transactional lifecycle
 * must roll the insert back. Over HTTP, run()->throw() surfaces the failure as
 * a 500 envelope while the row stays absent, proving the rollback and the
 * rendered error together.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, never>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InsertThenFailService extends Service
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     */
    public function __construct(ServiceInput $input = new ArrayInput([]))
    {
        parent::__construct($input);
    }

    /**
     * Insert a users row inside the transaction, then fail.
     *
     * @return never
     *
     * @throws \RuntimeException
     */
    #[\Override]
    protected function handle(): never
    {
        DB::table('users')->insert([
            'name'  => 'Rolled Back',
            'email' => 'rolled-back@insert-then-fail.test',
        ]);

        throw new \RuntimeException('Service handle failed after inserting a row.');
    }
}
