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
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ToolkitCollection extends AnonymousResourceCollection
{
    use ProvidesApiEnvelope;

    /**
     * Negotiate an export response, falling back to the JSON envelope.
     *
     * The exporter is optional; when unbound the envelope is returned as is.
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

        return $negotiator->collection($this, class_exists($this->collects) ? $this->collects : null, $request)
            ?? ExportNegotiator::varyAccept(parent::toResponse($request));
    }
}
