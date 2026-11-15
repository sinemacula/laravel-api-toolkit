<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Naming;

use SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;

/**
 * Guards the exported operations against two of them sharing an operationId.
 *
 * Ids must be unique across every audience, since the operation descriptions
 * keyed by them are shared.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OperationIdCollisionGuard
{
    /** The message describing an operationId collision */
    private const string COLLISION_MESSAGE = 'The operationId "%s" is claimed by both [%s] and [%s].'
        . ' Ids must be unique across every audience: name the routes apart, or give their actions distinct #[Operation] ids.';

    /**
     * Assert that no two operations claim one id.
     *
     * Each exported verb claims its own id and its route's primary id, which
     * its description falls back to. An operation is its verb, path and
     * handler: a controller action is one handler however many routes and
     * audiences export it, and a closure is a handler of its route alone.
     *
     * @param  array<string, array<string, array<string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>>>  $operations
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException
     */
    public function assertUnique(array $operations): void
    {
        $claims = [];

        foreach ($this->claimsOf($operations) as [$verb, $route]) {

            $id = $route->operationIds[$verb] ?? null;

            if ($id === null) {
                continue;
            }

            $claims[$id][$this->operationOf($verb, $route)] ??= $route->label($verb);

            if (count($claims[$id]) > 1) {
                throw new OperationIdCollisionException(sprintf(self::COLLISION_MESSAGE, $id, ...array_values($claims[$id])));
            }
        }
    }

    /**
     * Identify the operation the route exports under the verb.
     *
     * @param  string  $verb
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @return string
     */
    private function operationOf(string $verb, DocumentedRoute $route): string
    {
        $handler = $route->controllerClass === null
            ? [spl_object_id($route->route)]
            : [$route->controllerClass, $route->action];

        return implode(' ', [$verb, $route->path, ...$handler]);
    }

    /**
     * Yield the verb of each id an operation claims, with the owning route: the
     * operation's own verb, then its route's primary verb.
     *
     * @param  array<string, array<string, array<string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>>>  $operations
     * @return iterable<array{string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute}>
     */
    private function claimsOf(array $operations): iterable
    {
        foreach ($operations as $paths) {
            foreach ($paths as $verbs) {
                foreach ($verbs as $verb => $route) {
                    yield [$verb, $route];
                    yield [$route->verbs[0], $route];
                }
            }
        }
    }
}
