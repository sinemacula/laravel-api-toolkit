<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Resources\DocumentResource;

/**
 * Fixture resource controller showing a versioned document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Document::class, except: ['show'])]
final class ParityDocumentController extends AuthorizedController
{
    /**
     * Show a bound document.
     *
     * @param  \Tests\Fixtures\Models\Document  $document
     * @return \Tests\Fixtures\Resources\DocumentResource
     */
    public function show(Document $document): DocumentResource
    {
        return new DocumentResource($document, true);
    }
}
