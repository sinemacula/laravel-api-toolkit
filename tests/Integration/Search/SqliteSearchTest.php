<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Search\Drivers\SqliteSearchDriver;

/**
 * Search matching suite for the SQLite driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SqliteSearchDriver::class)]
final class SqliteSearchTest extends SearchMatchingTestCase
{
    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'sqlite';
    }

    /**
     * Waive the index proof, which this engine cannot take.
     *
     * @return void
     */
    #[\Override]
    protected function serveSearches(): void
    {
        Config::set('api-toolkit.search.unverified_connections', [DB::connection()->getName()]);
    }

    /**
     * Return the rows the anywhere-match finds for "ann smith".
     *
     * The pattern matches the term's characters, whitespace included.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function multiWordMatches(): array
    {
        return ['Ann Smith', 'ANN SMITH'];
    }

    /**
     * Return, keyed by term, the rows each accented or upper-case term finds.
     *
     * The pattern folds the case of ASCII letters only.
     *
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function foldedMatches(): array
    {
        return [
            'élise' => [],
            'ÉLISE' => ['Élise Müller'],
            'ELISE' => ['Elise Martin'],
        ];
    }

    /**
     * Return the rows an equality match finds for "smith" beside "Smith".
     *
     * The column's binary collation compares case.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function caseFoldedEqualityMatches(): array
    {
        return [];
    }
}
