<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Reflects an action's documented throws into its error descriptors.
 *
 * Each fully qualified @throws tag on the action is kept only when it names an
 * ApiException subclass reporting an HTTP status, whether through its
 * HTTP_STATUS constant or a status override, and is grouped by that status. Any
 * other throwable, and an ApiException reporting no status, is ignored. Each
 * kept exception contributes an example named by its short class name and
 * carrying its status and error code. A status's phrase comes from the status
 * enum case name, or from the exception's name for a status the enum lacks. An
 * action that cannot be reflected or carries no doc comment yields none.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ThrowsErrorResolver
{
    /**
     * Resolve the status-keyed error descriptors for the action.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return array<int, array{phrase: string, examples: array<string, array<string, mixed>>}>
     */
    public function resolve(string $controllerClass, string $action): array
    {
        $errors = [];

        foreach ($this->documentedThrows($controllerClass, $action) as $class) {

            $error = $this->describe($class);

            if ($error === null) {
                continue;
            }

            [$status, $phrase, $name, $example] = $error;

            $errors[$status] ??= ['phrase' => $phrase, 'examples' => []];

            $errors[$status]['examples'][$name] = $example;
        }

        return $errors;
    }

    /**
     * List the class names the action's @throws tags name, as written.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return array<int, string>
     */
    private function documentedThrows(string $controllerClass, string $action): array
    {
        try {
            $docComment = (new \ReflectionMethod($controllerClass, $action))->getDocComment();
        } catch (\ReflectionException) {
            return [];
        }

        if ($docComment === false) {
            return [];
        }

        preg_match_all('/@throws\s+([\\\\\w]+)/', $docComment, $matches);

        return $matches[1];
    }

    /**
     * Describe an exception class as its status, phrase, example name, and
     * example, or null when it is not an ApiException reporting a status.
     *
     * @param  string  $exceptionClass
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>}|null
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

        return [$status, $this->phraseFor($status, $name), $name, ['value' => ['error' => $error]]];
    }

    /**
     * Derive the sentence-case phrase for a status from its enum case name, or
     * from the exception's name when the enum has no case for the status.
     *
     * @param  int  $status
     * @param  string  $exceptionName
     * @return string
     */
    private function phraseFor(int $status, string $exceptionName): string
    {
        $words = HttpStatus::tryFrom($status)->name ?? Str::snake(Str::beforeLast($exceptionName, 'Exception'));

        return ucfirst(strtolower(str_replace('_', ' ', $words))) . '.';
    }
}
