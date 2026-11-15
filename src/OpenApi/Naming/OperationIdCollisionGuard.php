<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Naming;

use SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException;

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
     * One route exported to several audiences claims each of its ids once.
     *
     * @param  array<string, array<string, array<string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>>>  $operations
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException
     */
    public function assertUnique(array $operations): void
    {
        $claims = [];

        foreach ($this->verbsOf($operations) as [$verb, $route]) {

            $id = $route->operationIds[$verb] ?? null;

            if ($id === null) {
                continue;
            }

            $existing = $claims[$id] ?? null;

            if ($existing !== null && $existing['route'] !== $route->route) {
                throw new OperationIdCollisionException(sprintf(self::COLLISION_MESSAGE, $id, $existing['label'], $route->label($verb)));
            }

            $claims[$id] = ['route' => $route->route, 'label' => $route->label($verb)];
        }
    }

    /**
     * Yield each operation's verb and owning route, audience by audience.
     *
     * @param  array<string, array<string, array<string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>>>  $operations
     * @return iterable<array{string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute}>
     */
    private function verbsOf(array $operations): iterable
    {
        foreach ($operations as $paths) {
            foreach ($paths as $verbs) {
                foreach ($verbs as $verb => $route) {
                    yield [$verb, $route];
                }
            }
        }
    }
}
