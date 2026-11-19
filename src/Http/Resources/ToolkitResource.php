<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use SineMacula\ApiToolkit\Http\Concurrency\EntityTag;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Http\Enums\HttpHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for every toolkit single-item resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ToolkitResource extends JsonResource
{
    /**
     * Negotiate an export response, falling back to the JSON item response.
     *
     * The exporter is optional; when unbound the response is returned as is.
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
     * Tag the JSON item response with the entity tag of a versioned model.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\JsonResponse  $response
     * @return void
     */
    #[\Override]
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $tag = $this->resource instanceof Model ? EntityTag::for($this->resource) : null;

        if ($tag === null) {
            return;
        }

        $response->headers->set(HttpHeader::ETAG->getName(), $tag);
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
