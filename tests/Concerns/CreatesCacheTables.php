<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tables the database cache store keeps its entries and locks in.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait CreatesCacheTables
{
    /**
     * Create the cache and cache lock tables on the given connection.
     *
     * @param  string|null  $connection
     * @return void
     */
    protected function createCacheTables(?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        $schema->dropIfExists('cache');
        $schema->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        $schema->dropIfExists('cache_locks');
        $schema->create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }
}
