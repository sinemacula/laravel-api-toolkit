<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\Exceptions\ApiException;

/**
 * Lists the ApiExceptions an action's @throws tags name.
 *
 * Only ApiException subclasses reporting an HTTP status are kept, in tag order
 * and once each.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ThrowsErrorResolver
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver  $classNames
     */
    public function __construct(

        /** The resolver of the class names the @throws tags write. */
        private ClassNameResolver $classNames,
    ) {}

    /**
     * Resolve the ApiException classes the action documents.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return list<class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    public function resolve(string $controllerClass, string $action): array
    {
        $classes = [];

        foreach ($this->documentedThrows($controllerClass, $action) as $class) {

            if (!$this->reportsStatus($class) || in_array($class, $classes, true)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * List the fully qualified class names the action's @throws tags name.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return array<int, string>
     */
    private function documentedThrows(string $controllerClass, string $action): array
    {
        try {
            $method = new \ReflectionMethod($controllerClass, $action);
        } catch (\ReflectionException) {
            return [];
        }

        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return [];
        }

        preg_match_all('/@throws\s+([\\\\\w|]+)/', $docComment, $matches);

        return array_map(
            fn (string $name): string => $this->classNames->resolve($name, $method),
            preg_split('/\|/', implode('|', $matches[1]), flags: PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }

    /**
     * Determine whether the class is an ApiException reporting an HTTP status.
     *
     * @param  string  $exceptionClass
     * @return bool
     *
     * @phpstan-assert-if-true class-string<\SineMacula\ApiToolkit\Exceptions\ApiException> $exceptionClass
     */
    private function reportsStatus(string $exceptionClass): bool
    {
        if (!is_subclass_of($exceptionClass, ApiException::class)) {
            return false;
        }

        try {
            $exceptionClass::getHttpStatusCode();
        } catch (\LogicException) {
            return false;
        }

        return true;
    }
}
