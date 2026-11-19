<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseExample;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;

/**
 * Resolves the response examples #[ResponseExample] directives declare.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResponseExampleResolver
{
    /** The message for a name declared twice */
    private const string DUPLICATE_MESSAGE = 'The response example [%s] is declared more than once on %s::%s.';

    /**
     * Resolve the examples declared on the action, in declaration order.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return list<\SineMacula\ApiToolkit\OpenApi\Attributes\ResponseExample>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException
     */
    public function forAction(?string $controllerClass, string $action): array
    {
        if ($controllerClass === null || !method_exists($controllerClass, $action)) {
            return [];
        }

        $examples = [];

        foreach ((new \ReflectionMethod($controllerClass, $action))->getAttributes(ResponseExample::class) as $attribute) {

            $example = $attribute->newInstance();

            if (array_key_exists($example->name, $examples)) {
                throw new ExampleDeclarationException(sprintf(self::DUPLICATE_MESSAGE, $example->name, $controllerClass, $action));
            }

            $examples[$example->name] = $example;
        }

        return array_values($examples);
    }
}
