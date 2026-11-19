<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\App;
use SineMacula\ApiToolkit\Http\Concurrency\IfMatch;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Repositories\DocumentRepository;

/**
 * Fixture service that retitles a document under the run's If-Match
 * precondition.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, \Tests\Fixtures\Models\Document>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RetitleDocumentService extends Service
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Input\ArrayInput  $input
     */
    public function __construct(ArrayInput $input)
    {
        parent::__construct($input);
    }

    /**
     * Lock the document, check the precondition and write the new title.
     *
     * @return \Tests\Fixtures\Models\Document
     *
     * @throws \Throwable
     */
    #[\Override]
    protected function handle(): Document
    {
        $documents = App::make(DocumentRepository::class);
        $id        = $this->input->get('id');

        assert($documents instanceof DocumentRepository && is_int($id));

        $document = $documents->findForUpdate($id, IfMatch::fromContext($this->context()));

        assert($document instanceof Document);

        $documents->persist($document, ['title' => $this->input->get('title')]);

        return $document;
    }
}
