<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Search;

use Illuminate\Database\Connection;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;

/**
 * Fixture inspector returning a report prepared in advance.
 *
 * Answers without asking a connection anything, so what a reader does with a
 * report is provable without an engine behind it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StubIndexEligibilityInspector extends IndexEligibilityInspector
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility  $eligibility
     * @return void
     */
    public function __construct(

        /** The report every inspection returns */
        private readonly IndexEligibility $eligibility = new IndexEligibility,
    ) {}

    /**
     * Return the prepared report.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    #[\Override]
    public function inspect(string $table, Connection $connection): IndexEligibility
    {
        return $this->eligibility;
    }
}
