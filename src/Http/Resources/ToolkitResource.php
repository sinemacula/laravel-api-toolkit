<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use SineMacula\Exporter\Http\ExportNegotiator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for every toolkit single-item resource.
 *
 * Common ancestor of the schema-driven {@see ApiResource} (entity reads) and
 * {@see ReportResource} (aggregated read models), so the toolkit's response
 * conventions are anchored in one place. The single-item envelope (the `data`
 * wrap) is inherited from Laravel's JsonResource; this base owns the shared
 * `_type` discriminator convention via {@see self::withType()}.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ToolkitResource extends JsonResource
{
    /**
     * Negotiate an export response, falling back to the JSON item response.
     *
     * When the resource exporter package is installed its ExportNegotiator is
     * bound in the container: a negotiated export (a tabular or hierarchical
     * stream) is returned as is, otherwise the native JSON response is returned
     * with a `Vary: Accept` header so caches never serve the wrong
     * representation. When the exporter is absent the native response is
     * returned unchanged, so the toolkit carries no hard dependency on it.
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

        return $negotiator->item($this, $request)
            ?? ExportNegotiator::varyAccept(parent::toResponse($request));
    }

    /**
     * Prepend the `_type` discriminator to a resolved payload.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withType(string $type, array $data): array
    {
        return ['_type' => $type, ...$data];
    }
}
