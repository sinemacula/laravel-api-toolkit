<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;

/**
 * Resolves the success status a #[ResponseStatus] directive declares.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResponseStatusResolver
{
    /** The message thrown when a no-content status is declared beside a body */
    private const string CONFLICT_MESSAGE = 'The %d status declared for %s::%s answers with no content and cannot carry a #[ResponseSchema] body';

    /**
     * Resolve the status directive declared on the action, if any.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return \SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus|null
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException
     */
    public function resolve(?string $controllerClass, string $action): ?ResponseStatus
    {
        if ($controllerClass === null || !method_exists($controllerClass, $action)) {
            return null;
        }

        $method     = new \ReflectionMethod($controllerClass, $action);
        $attributes = $method->getAttributes(ResponseStatus::class);

        if ($attributes === []) {
            return null;
        }

        $directive = $attributes[0]->newInstance();

        if ($directive->hasNoContent() && $method->getAttributes(ResponseSchema::class) !== []) {
            throw new ResponseStatusConflictException(sprintf(self::CONFLICT_MESSAGE, $directive->status->value, $controllerClass, $action));
        }

        return $directive;
    }
}
