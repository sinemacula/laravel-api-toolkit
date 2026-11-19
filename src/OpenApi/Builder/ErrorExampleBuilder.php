<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesErrorMeta;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ErrorCatalogueMismatchException;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;

/**
 * Builds the components.examples set and the references to it, one example per
 * error code.
 *
 * Each example carries its code's catalogue text, so exceptions sharing a code
 * share one example.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ErrorExampleBuilder
{
    /** The component name of each per-code error example and envelope schema */
    public const string NAME_FORMAT = 'Error%d';

    /** The path prefix under which example components are referenced */
    private const string REFERENCE_PREFIX = '#/components/examples/';

    /** The message for a code the error catalogue does not hold */
    private const string UNCATALOGUED_MESSAGE = 'The error code %d of [%s] is not in the error catalogue; add its namespace to api-toolkit.openapi.exception_namespaces.';

    /** The message for an exception the handler cannot render for want of a code */
    private const string CODELESS_MESSAGE = '[%s] declares no error code, so the handler cannot render it; give it a CODE constant.';

    /** The message for a status the error catalogue documents otherwise */
    private const string STATUS_MESSAGE = '[%1$s] answers %2$d with error code %3$d, which the error catalogue documents for [%4$s] as %5$d; give [%1$s] its own error code.';

    /** The message for a meta the error catalogue documents otherwise */
    private const string META_MESSAGE = '[%1$s] declares a different meta for error code %2$d than [%3$s] does; give [%1$s] its own error code.';

    /** @var array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>|null The catalogue indexed by code, once read. */
    private ?array $descriptors = null;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     */
    public function __construct(

        /** The metadata catalogue providing the error catalogue. */
        private readonly MetadataCatalogue $catalogue,
    ) {}

    /**
     * Build the media-type examples entry referencing the exception's example.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $exceptionClass
     * @return array<string, array{'$ref': string}>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ErrorCatalogueMismatchException
     */
    public function reference(string $exceptionClass): array
    {
        if (!defined($exceptionClass . '::CODE')) {
            throw new ErrorCatalogueMismatchException(sprintf(self::CODELESS_MESSAGE, $exceptionClass));
        }

        $code       = $exceptionClass::getInternalErrorCode();
        $status     = $exceptionClass::getHttpStatusCode();
        $descriptor = $this->descriptors()[$code]
            ?? throw new ErrorCatalogueMismatchException(sprintf(self::UNCATALOGUED_MESSAGE, $code, $exceptionClass));

        if ($descriptor->httpStatus !== $status) {
            throw new ErrorCatalogueMismatchException(sprintf(self::STATUS_MESSAGE, $exceptionClass, $status, $code, $descriptor->source, $descriptor->httpStatus));
        }

        $meta = is_a($exceptionClass, DefinesErrorMeta::class, true) ? $exceptionClass::metaSchema() : null;

        if ($meta !== $descriptor->meta) {
            throw new ErrorCatalogueMismatchException(sprintf(self::META_MESSAGE, $exceptionClass, $code, $descriptor->source));
        }

        $name = sprintf(self::NAME_FORMAT, $code);

        return [$name => ['$ref' => self::REFERENCE_PREFIX . $name]];
    }

    /**
     * Build the components.examples map the paths reference, ordered by code.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, array{summary: string, value: array{error: array<string, int|string>}}>
     */
    public function build(array $paths): array
    {
        return array_map(fn (ErrorDescriptor $descriptor): array => $this->example($descriptor), $this->referenced($paths));
    }

    /**
     * Resolve the descriptor of each code whose example the paths reference,
     * keyed by its component name and ordered by code.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    public function referenced(array $paths): array
    {
        $names       = $this->referencedNames($paths);
        $descriptors = [];

        foreach ($this->descriptors() as $code => $descriptor) {

            $name = sprintf(self::NAME_FORMAT, $code);

            if (!($names[$name] ?? false)) {
                continue;
            }

            $descriptors[$name] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * Build the example object for an error code from its descriptor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor  $descriptor
     * @return array{summary: string, value: array{error: array<string, int|string>}}
     */
    private function example(ErrorDescriptor $descriptor): array
    {
        return [
            'summary' => $descriptor->title ?? $descriptor->detail,
            'value'   => [
                'error' => array_filter([
                    'status' => $descriptor->httpStatus,
                    'code'   => $descriptor->code,
                    'title'  => $descriptor->title,
                    'detail' => $descriptor->detail,
                ], static fn (int|string|null $value): bool => $value !== null),
            ],
        ];
    }

    /**
     * Collect the example names the paths' media types reference.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, true>
     */
    private function referencedNames(array $paths): array
    {
        $names = [];

        foreach ($this->mediaTypes($paths) as $media) {
            foreach ($this->arrayAt($media, 'examples') as $example) {

                $reference = is_array($example) ? $example['$ref'] ?? null : null;

                if (!is_string($reference) || !str_starts_with($reference, self::REFERENCE_PREFIX)) {
                    continue;
                }

                $names[substr($reference, strlen(self::REFERENCE_PREFIX))] = true;
            }
        }

        return $names;
    }

    /**
     * Yield every response media type object across the paths' operations.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return iterable<mixed>
     */
    private function mediaTypes(array $paths): iterable
    {
        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                foreach ($this->arrayAt($operation, 'responses') as $response) {
                    yield from $this->arrayAt($response, 'content');
                }
            }
        }
    }

    /**
     * Read the array held under a key of a node, empty when either is not one.
     *
     * @param  mixed  $node
     * @param  string  $key
     * @return array<array-key, mixed>
     */
    private function arrayAt(mixed $node, string $key): array
    {
        return is_array($node) && is_array($node[$key] ?? null) ? $node[$key] : [];
    }

    /**
     * Index the error catalogue by code on first use.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    private function descriptors(): array
    {
        if ($this->descriptors === null) {

            $this->descriptors = [];

            foreach ($this->catalogue->getErrorCatalogue() as $descriptor) {
                $this->descriptors[$descriptor->code] = $descriptor;
            }

            ksort($this->descriptors);
        }

        return $this->descriptors;
    }
}
