<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;

/**
 * Resolves the URL shapes a route matches, one path form for each.
 *
 * The router compiles only the trailing run of optional parameters as optional,
 * each with its separator, so a form omits a tail of that run and every other
 * parameter is required in every form.
 *
 * @phpstan-type Token array{'text', string}|array{0: 'variable', 1: string, 2: string, 3: string, 4?: bool, 5?: bool}
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RoutePathFormResolver
{
    /**
     * Resolve the route's path forms, shortest first.
     *
     * The route is compiled afresh, since its own compiled form is only set
     * once it has matched a request.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return non-empty-list<\SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm>
     */
    public function resolve(Route $route): array
    {
        /** @var list<Token> $reversed */
        $reversed = $route->toSymfonyRoute()->compile()->getTokens();
        $tokens   = array_reverse($reversed);
        $run      = $this->optionalRun($reversed, $route->getOptionalParameterNames());
        $required = count($tokens) - $run;

        return array_map(
            fn (int $included): RoutePathForm => $this->form(array_slice($tokens, 0, $required + $included), $included, $included === $run),
            range(0, $run),
        );
    }

    /**
     * Count the trailing run of optional parameters, reading the tokens last
     * first.
     *
     * @param  list<Token>  $reversed
     * @param  array<string, null>  $optional
     * @return int
     */
    private function optionalRun(array $reversed, array $optional): int
    {
        $run = 0;

        foreach ($reversed as $token) {

            if ($token[0] === 'text' || !array_key_exists($token[3], $optional)) {
                break;
            }

            $run++;
        }

        return $run;
    }

    /**
     * Join the tokens into a form whose last included parameters are the
     * optional ones.
     *
     * @param  list<Token>  $tokens
     * @param  int  $included
     * @param  bool  $full
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm
     */
    private function form(array $tokens, int $included, bool $full): RoutePathForm
    {
        $path       = '';
        $parameters = [];

        foreach ($tokens as $token) {

            if ($token[0] === 'text') {
                $path .= $token[1];

                continue;
            }

            $path .= $token[1] . '{' . $token[3] . '}';
            $parameters[] = $token[3];
        }

        return new RoutePathForm($path === '' ? '/' : $path, $parameters, array_slice($parameters, count($parameters) - $included), $full);
    }
}
