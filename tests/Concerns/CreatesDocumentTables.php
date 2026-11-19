<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tables of the versioned document fixture.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait CreatesDocumentTables
{
    /**
     * Create the documents table and its tag pivot.
     *
     * @return void
     */
    protected function createDocumentTables(): void
    {
        Schema::dropIfExists('document_tag');
        Schema::dropIfExists('documents');

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('document_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['document_id', 'tag_id']);
        });
    }
}
