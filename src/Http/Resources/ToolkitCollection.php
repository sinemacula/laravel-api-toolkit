<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;
use SineMacula\Exporter\Http\ExportNegotiator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for every toolkit resource collection.
 *
 * Common ancestor of the schema-driven {@see ApiResourceCollection} (entity
 * reads) and {@see ReportCollection} (aggregated read models). It contributes
 * the shared response envelope - pagination `meta`/`links` and the
 * `Total-Count` header - via {@see ProvidesApiEnvelope}, leaving item-level
 * transformation to each subclass.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ToolkitCollection extends AnonymousResourceCollection
{
    use ProvidesApiEnvelope;

    /**
     * Negotiate an export response, falling back to the JSON envelope.
     *
     * When the resource exporter package is installed its ExportNegotiator is
     * bound in the container: a negotiated export (a tabular or hierarchical
     * stream over the collected resource) is returned as is, otherwise the
     * native envelope response is returned with a `Vary: Accept` header. When
     * the exporter is absent the native envelope is returned unchanged, so the
     * toolkit carries no hard dependency on it.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @phpstan-ignore method.childReturnType
     */
    #[\Override]
    public function toResponse(mixed $request): Response
    {
        $class = ExportNegotiator::class;

        if (!App::bound($class)) {
            return parent::toResponse($request);
        }

        $negotiator = App::make($class);
        assert($negotiator instanceof ExportNegotiator);

        return $negotiator->collection($this, $this->collects, $request)
            ?? ExportNegotiator::varyAccept(parent::toResponse($request));
    }
}
