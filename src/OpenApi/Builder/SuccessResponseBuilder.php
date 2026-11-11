<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver;

/**
 * Applies what an action declares about its success response.
 *
 * A no-content status drops the body and any exporter-emitted headers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SuccessResponseBuilder
{
    /** The media type every documented response body is served under */
    public const string MEDIA_TYPE = 'application/json';

    /** The message thrown when an action redeclares an exporter-emitted header */
    private const string HEADER_COLLISION_MESSAGE = 'The %s header declared for %s::%s is emitted by the exporter and cannot be redeclared';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder  $envelope
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver  $responseSchema
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver  $responseHeaders
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver  $responseStatus
     */
    public function __construct(

        /** The builder assembling the shared response envelopes. */
        private EnvelopeBuilder $envelope,

        /** The resolver of an action's declared response body. */
        private ResponseSchemaResolver $responseSchema,

        /** The resolver of the headers an action declares it responds with. */
        private ResponseHeaderResolver $responseHeaders,

        /** The resolver of the success status an action declares. */
        private ResponseStatusResolver $responseStatus,
    ) {}

    /**
     * Apply the action's declared status and headers to its default success
     * response.
     *
     * @param  array<int|string, array<string, mixed>>  $responses
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<int|string, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException
     */
    public function declare(array $responses, ?string $controllerClass, string $action): array
    {
        $declared = $this->responseHeaders->forAction($controllerClass, $action);

        return array_map(
            fn (array $response): array => $this->withHeaders($response, $declared, $controllerClass, $action),
            $this->withStatus($responses, $controllerClass, $action),
        );
    }

    /**
     * Determine whether a #[ResponseSchema] replaces the given resource's body.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  class-string  $resourceClass
     * @param  bool  $collection
     * @return bool
     */
    public function replacesResource(?string $controllerClass, string $action, string $resourceClass, bool $collection): bool
    {
        return $this->responseSchema->declares($controllerClass, $action)
            && !$this->responseSchema->declaresResource($controllerClass, $action, $resourceClass, $collection);
    }

    /**
     * Build the success body of an action outside the resource contract.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array{content: array<string, array{schema: array<string, mixed>}>}
     */
    public function body(?string $controllerClass, string $action): array
    {
        $schema = $this->responseSchema->resolve($controllerClass, $action) ?? $this->envelope->undocumentedEnvelope();

        return ['content' => [self::MEDIA_TYPE => ['schema' => $schema]]];
    }

    /**
     * Move the success response under the status the action declares.
     *
     * @param  array<int|string, array<string, mixed>>  $responses
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<int|string, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException
     */
    private function withStatus(array $responses, ?string $controllerClass, string $action): array
    {
        $directive = $this->responseStatus->resolve($controllerClass, $action);
        $default   = array_key_first($responses);

        if ($directive === null || $directive->status->value === $default) {
            return $responses;
        }

        $response = ['description' => $directive->status->getReasonPhrase() . '.'];

        if ($directive->hasNoContent()) {
            return [$directive->status->value => $response];
        }

        return [$directive->status->value => $response + $responses[$default] + $this->body($controllerClass, $action)];
    }

    /**
     * Attach the declared headers after any the exporter emits itself.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, array<string, mixed>>  $declared
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<string, mixed>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException
     */
    private function withHeaders(array $response, array $declared, ?string $controllerClass, string $action): array
    {
        if ($declared === []) {
            return $response;
        }

        /** @var array<string, array<string, mixed>> $emitted */
        $emitted = $response['headers'] ?? [];
        $owned   = array_change_key_case($emitted);

        foreach (array_keys($declared) as $name) {
            if (array_key_exists(strtolower($name), $owned)) {
                throw new ResponseHeaderCollisionException(sprintf(self::HEADER_COLLISION_MESSAGE, $name, $controllerClass, $action));
            }
        }

        return ['description' => $response['description'], 'headers' => [...$emitted, ...$declared]] + $response;
    }
}
