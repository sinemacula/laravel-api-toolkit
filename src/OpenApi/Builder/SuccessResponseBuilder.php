<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseExample;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleDirectory;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseExampleResolver;
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

    /** The message for an example declared on a body that is not a shape */
    private const string UNSHAPED_EXAMPLE_MESSAGE = 'The response example [%s] on %s::%s cannot be declared: the response body is not a DefinesSchema shape.'
        . ' A resource body\'s examples are generated, so give its fields example values instead.';

    /** The message for an example naming a status the action does not answer with */
    private const string EXAMPLE_STATUS_MESSAGE = 'The response example [%s] on %s::%s names status %d, but the action answers with %d.';

    /** The message for an example naming a status that has no body */
    private const string BODYLESS_EXAMPLE_MESSAGE = 'The response example [%s] on %s::%s names status %d, which has no body.';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder  $envelope
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver  $responseSchema
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver  $responseHeaders
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver  $responseStatus
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseExampleResolver  $responseExamples
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\ExampleDirectory  $exampleFiles
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

        /** The resolver of the response examples an action declares. */
        private ResponseExampleResolver $responseExamples = new ResponseExampleResolver,

        /** The directory the declared example files are read from. */
        private ExampleDirectory $exampleFiles = new ExampleDirectory,
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
     * Attach the examples the action declares to its success body, read for the
     * audience.
     *
     * @param  array<string, mixed>  $operation
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  string  $audience
     * @return array<string, mixed>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException
     */
    public function withExamples(array $operation, ?string $controllerClass, string $action, string $audience): array
    {
        $examples = $this->responseExamples->forAction($controllerClass, $action);

        if ($examples === []) {
            return $operation;
        }

        if (!$this->responseSchema->declaresShape($controllerClass, $action)) {
            throw new ExampleDeclarationException(sprintf(self::UNSHAPED_EXAMPLE_MESSAGE, $examples[0]->name, $controllerClass, $action));
        }

        /** @var array<int, array<string, mixed>> $responses */
        $responses = $operation['responses'];

        foreach ($examples as $example) {
            $responses = $this->withExample($responses, $example, $controllerClass, $action, $audience);
        }

        $operation['responses'] = $responses;

        return $operation;
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
     * Build the success body of an action outside the resource contract, with
     * the total-count header of a declared collection.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array{headers?: array<string, array{'$ref': string}>, content: array<string, array{schema: array<string, mixed>}>}
     */
    public function body(?string $controllerClass, string $action): array
    {
        $schema = $this->responseSchema->resolve($controllerClass, $action) ?? $this->envelope->undocumentedEnvelope();
        $body   = ['content' => [self::MEDIA_TYPE => ['schema' => $schema]]];

        return $this->responseSchema->declaresCollection($controllerClass, $action)
            ? ['headers' => $this->envelope->collectionHeaders(), ...$body]
            : $body;
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
     * Attach a declared example to the body of the success response, the first
     * of the responses.
     *
     * @param  array<int, array<string, mixed>>  $responses
     * @param  \SineMacula\ApiToolkit\OpenApi\Attributes\ResponseExample  $example
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  string  $audience
     * @return array<int, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException
     */
    private function withExample(array $responses, ResponseExample $example, ?string $controllerClass, string $action, string $audience): array
    {
        $status = array_key_first($responses);

        if ($example->status->value !== $status) {
            throw new ExampleDeclarationException(sprintf(self::EXAMPLE_STATUS_MESSAGE, $example->name, $controllerClass, $action, $example->status->value, $status));
        }

        if (!isset($responses[$status]['content'][self::MEDIA_TYPE]['schema'])) {
            throw new ExampleDeclarationException(sprintf(self::BODYLESS_EXAMPLE_MESSAGE, $example->name, $controllerClass, $action, $status));
        }

        $responses[$status]['content'][self::MEDIA_TYPE]['examples'][$example->name] = ['value' => $this->exampleFiles->read($example->file, $audience)];

        return $responses;
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
