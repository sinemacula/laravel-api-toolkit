<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;

/**
 * Builds the components.responses set, one entry per defined error code.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ErrorResponseBuilder
{
    /** The component name of the shared error-envelope schema */
    public const string ENVELOPE_SCHEMA_NAME = 'ErrorEnvelope';

    /** The component-name prefix for each per-code error response */
    private const string RESPONSE_NAME_PREFIX = 'ErrorResponse';

    /** The reference to the shared error-envelope schema */
    private const string ENVELOPE_SCHEMA_REF = '#/components/schemas/' . self::ENVELOPE_SCHEMA_NAME;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     */
    public function __construct(

        /** The metadata catalogue providing the error catalogue. */
        private MetadataCatalogue $catalogue,
    ) {}

    /**
     * Build the full components.responses map, keyed by component name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function build(): array
    {
        $responses = [];

        foreach ($this->catalogue->getErrorCatalogue() as $descriptor) {
            $responses[$this->responseName($descriptor)] = $this->buildResponse($descriptor);
        }

        return $responses;
    }

    /**
     * Build the shared error-envelope schema referenced by every response.
     *
     * Mirrors the runtime exception payload.
     *
     * @return array<string, mixed>
     */
    public function buildEnvelopeSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'error' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer'],
                        'code'   => ['type' => 'integer'],
                        'title'  => ['type' => 'string'],
                        'detail' => ['type' => 'string'],
                        'meta'   => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['status', 'code', 'detail'],
                ],
            ],
            'required' => ['error'],
        ];
    }

    /**
     * Build a single error-response component from its descriptor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor  $descriptor
     * @return array<string, mixed>
     */
    private function buildResponse(ErrorDescriptor $descriptor): array
    {
        return [
            'description' => $this->responseDescription($descriptor),
            'x-status'    => $descriptor->httpStatus,
            'x-code'      => $descriptor->code,
            'content'     => [
                'application/json' => [
                    'schema'  => ['$ref' => self::ENVELOPE_SCHEMA_REF],
                    'example' => $this->responseExample($descriptor),
                ],
            ],
        ];
    }

    /**
     * Build the example error payload for the given descriptor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor  $descriptor
     * @return array<string, mixed>
     */
    private function responseExample(ErrorDescriptor $descriptor): array
    {
        return [
            'error' => array_filter([
                'status' => $descriptor->httpStatus,
                'code'   => $descriptor->code,
                'title'  => $descriptor->title,
                'detail' => $descriptor->detail,
            ], static fn ($value) => $value !== null),
        ];
    }

    /**
     * Resolve the description for the given descriptor, title before detail.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor  $descriptor
     * @return string
     */
    private function responseDescription(ErrorDescriptor $descriptor): string
    {
        return $descriptor->title ?? $descriptor->detail;
    }

    /**
     * Derive the component name for an error-response from its code.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor  $descriptor
     * @return string
     */
    private function responseName(ErrorDescriptor $descriptor): string
    {
        return self::RESPONSE_NAME_PREFIX . $descriptor->code;
    }
}
