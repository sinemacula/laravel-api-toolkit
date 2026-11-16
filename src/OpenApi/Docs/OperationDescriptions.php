<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;

/**
 * Resolves the operation descriptions filed under `operations/<id>.md`.
 *
 * The files are keyed by operationId, so any of them fails the export while
 * operation ids are off.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OperationDescriptions
{
    /** The message for an operation file found while operation ids are off */
    private const string IDS_OFF_MESSAGE = 'The operation description [%s] cannot be matched while operation ids are off. Set api-toolkit.openapi.operation_ids to true.';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\KeyedDocFiles  $files
     */
    public function __construct(

        /** The resolver of the keyed description files. */
        private KeyedDocFiles $files = new KeyedDocFiles,
    ) {}

    /**
     * Resolve the audience's operation descriptions, keyed by operationId.
     *
     * @param  string  $audience
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes  $routes
     * @return array<string, string>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     */
    public function for(string $audience, DocumentedRoutes $routes): array
    {
        if (!$routes->operationIdsEnabled) {

            $files = $this->files->files(DocKind::OPERATION);

            if ($files !== []) {
                throw new OrphanedDocumentationException(sprintf(self::IDS_OFF_MESSAGE, $files[0]));
            }
        }

        $keys = [];

        foreach ($routes->audiences() as $name) {
            $keys[$name] = $routes->descriptionKeys($name);
        }

        return $this->files->resolve(DocKind::OPERATION, $keys, $audience);
    }
}
