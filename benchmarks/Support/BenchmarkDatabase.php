<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tables the benchmark subjects read and write.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BenchmarkDatabase
{
    /**
     * Create the benchmark tables.
     *
     * @return void
     */
    public static function migrate(): void
    {
        Schema::create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(false);
            $table->timestamps();
        });

        Schema::create('tags', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('post_tag', static function (Blueprint $table): void {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('import_rows', static function (Blueprint $table): void {
            $table->string('reference');
            $table->string('payload');
        });
    }
}
