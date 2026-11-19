<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

use SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleGenerationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException;

/**
 * Validates the examples an assembled document carries, then generates one for
 * every JSON request body and success body that has none.
 *
 * A body needing an undocumented value gets no example and is reported, unless
 * its whole payload is undocumented.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExampleComposer
{
    /** The message reporting a body skipped for an undocumented value */
    private const string SKIPPED_MESSAGE = 'No example for %s in the [%s] audience: the value at #%s is undocumented.';

    /** The message for a body no generated value satisfies */
    private const string UNSATISFIED_MESSAGE = 'The exporter could not generate an example for %s in the [%s] audience: none of its values satisfies the schema at #%s. %s';

    /** The remedy for a request value the generator finds none for */
    private const string REQUEST_REMEDY = 'Give the field a value with fieldExamples() on its rules source.';

    /** The remedy for a response value the generator finds none for */
    private const string RESPONSE_REMEDY = 'Give the field a value with ->openapi()->example(), or an examples keyword in its DefinesSchema.';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\ExampleValidator  $validator
     */
    public function __construct(

        /** The validator of the examples the document already carries. */
        private ExampleValidator $validator = new ExampleValidator,
    ) {}

    /**
     * Compose the examples of an assembled document for its audience.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $audience
     * @return \SineMacula\ApiToolkit\OpenApi\Examples\ExampleComposition
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleGenerationException
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException
     */
    public function compose(array $document, string $audience): ExampleComposition
    {
        $schemas   = SchemaDocument::of($document);
        $generator = new ExampleGenerator($schemas);
        $skipped   = [];

        $this->validator->validate($schemas, $audience);

        foreach (Body::in((array) $schemas->node('')) as $body) {

            if ($body->scope === null) {
                continue;
            }

            $examples = $generator->generate($body->schemaPointer(), $body->scope);

            if ($examples instanceof MissingValue) {
                $skipped = [...$skipped, ...$this->skip($body, $examples, $audience)];

                continue;
            }

            $this->assertMatches($schemas, $body, $examples, $audience);

            $document['paths'] = $this->withExamples((array) $document['paths'], array_slice($body->keys, 1), $examples);
        }

        return new ExampleComposition($document, $skipped);
    }

    /**
     * Report a body left without an example, failing when its value is not
     * undocumented.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Body  $body
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\MissingValue  $missing
     * @param  string  $audience
     * @return list<string>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleGenerationException
     */
    private function skip(Body $body, MissingValue $missing, string $audience): array
    {
        if (!$missing->undocumented) {
            $remedy = $body->scope === ExampleScope::REQUEST ? self::REQUEST_REMEDY : self::RESPONSE_REMEDY;

            throw new ExampleGenerationException(sprintf(self::UNSATISFIED_MESSAGE, $body->target, $audience, $missing->pointer, $remedy));
        }

        if ($missing->pointer === SchemaDocument::append(SchemaDocument::append($body->schemaPointer(), 'properties'), 'data')) {
            return [];
        }

        return [sprintf(self::SKIPPED_MESSAGE, $body->target, $audience, $missing->pointer)];
    }

    /**
     * Assert that every generated example matches its body's schema.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument  $schemas
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Body  $body
     * @param  array<string, mixed>  $examples
     * @param  string  $audience
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException
     */
    private function assertMatches(SchemaDocument $schemas, Body $body, array $examples, string $audience): void
    {
        foreach ($examples as $name => $value) {

            $mismatch = $schemas->mismatch($value, $body->schemaPointer());

            if ($mismatch !== null) {
                throw new ExampleMismatchException(sprintf(ExampleValidator::MISMATCH_MESSAGE, 'generated', $name, $body->target, $audience, $mismatch));
            }
        }
    }

    /**
     * Write the named examples onto the media type object the keys lead to from
     * a node.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<int|string>  $keys
     * @param  array<string, mixed>  $examples
     * @return array<array-key, mixed>
     */
    private function withExamples(array $node, array $keys, array $examples): array
    {
        if ($keys === []) {
            return [...$node, 'examples' => array_map(static fn (mixed $value): array => ['value' => $value], $examples)];
        }

        $key = array_shift($keys);

        $node[$key] = $this->withExamples((array) $node[$key], $keys, $examples);

        return $node;
    }
}
