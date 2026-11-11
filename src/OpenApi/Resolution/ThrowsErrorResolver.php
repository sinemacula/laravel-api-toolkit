<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Reflects an action's documented throws into its error descriptors.
 *
 * Only ApiException subclasses reporting an HTTP status are kept, grouped by
 * that status.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ThrowsErrorResolver
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver  $headers
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver  $classNames
     */
    public function __construct(

        /** The resolver of the headers each exception declares. */
        private ResponseHeaderResolver $headers,

        /** The resolver of the class names the @throws tags write. */
        private ClassNameResolver $classNames,
    ) {}

    /**
     * Resolve the status-keyed error descriptors for the action.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return array<int, array{phrase: string, examples: array<string, array<string, mixed>>, headers: array<string, array<string, mixed>>}>
     */
    public function resolve(string $controllerClass, string $action): array
    {
        $errors = [];

        foreach ($this->documentedThrows($controllerClass, $action) as $class) {

            $error = $this->describe($class);

            if ($error === null) {
                continue;
            }

            [$status, $phrase, $name, $example, $headers] = $error;

            $errors[$status] ??= ['phrase' => $phrase, 'examples' => [], 'headers' => []];

            $errors[$status]['examples'][$name] = $example;
            $errors[$status]['headers']         = $this->headers->merge($errors[$status]['headers'], $headers);
        }

        return $errors;
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
     * Describe an exception class, or null when it is not an ApiException
     * reporting a status.
     *
     * @param  string  $exceptionClass
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>, 4: array<string, array<string, mixed>>}|null
     */
    private function describe(string $exceptionClass): ?array
    {
        if (!is_subclass_of($exceptionClass, ApiException::class)) {
            return null;
        }

        try {
            $status = $exceptionClass::getHttpStatusCode();
        } catch (\LogicException) {
            return null;
        }

        $name  = class_basename($exceptionClass);
        $error = ['status' => $status];

        if (defined($exceptionClass . '::CODE')) {
            /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface $code */
            $code          = constant($exceptionClass . '::CODE');
            $error['code'] = $code->getCode();
        }

        return [$status, $this->phraseFor($status, $name), $name, ['value' => ['error' => $error]], $this->headers->forException($exceptionClass)];
    }

    /**
     * Derive the sentence-case phrase for a status, else from the exception's
     * name.
     *
     * @param  int  $status
     * @param  string  $exceptionName
     * @return string
     */
    private function phraseFor(int $status, string $exceptionName): string
    {
        $words = HttpStatus::tryFrom($status)->name ?? Str::snake(Str::beforeLast($exceptionName, \Exception::class));

        return ucfirst(strtolower(str_replace('_', ' ', $words))) . '.';
    }
}
