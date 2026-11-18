<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;

/**
 * Builds the servers of an operation whose route answers on a domain.
 *
 * Each audience server keeps its scheme, port, path and their variables, with
 * its host replaced by the domain and the domain's parameters as variables.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DomainServerBuilder
{
    /** The scheme, userinfo and host of a URL with an authority */
    private const string AUTHORITY = '~^([^:/?#]*:)?//([^/?#]*@)?(\[[^\]]*]|[^:/?#]*)~';

    /** The server a route answers on when the audience lists none */
    private const array BARE_SERVER = ['url' => ''];

    /** The message for a server variable a domain parameter would rebind */
    private const string CONFLICT_MESSAGE = 'Unable to export audience [%s]: server [%s] uses the variable [%s] outside its host, which the domain of [%s] binds.';

    /**
     * Build the route's servers from the audience's, identical entries kept
     * once.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  array<string, array<string, mixed>>  $variables
     * @param  list<array<string, mixed>>  $servers
     * @param  string  $audience
     * @return non-empty-list<array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException
     */
    public function build(DocumentedRoute $route, array $variables, array $servers, string $audience): array
    {
        $domain = str_replace('?}', '}', (string) $route->route->getDomain());
        $scheme = $this->scheme($route);
        $built  = [];

        foreach ($servers === [] ? [self::BARE_SERVER] : $servers as $server) {

            $url = $server['url'];

            assert(is_string($url));

            [$head, $tail] = $this->aroundHost($url, $scheme);

            foreach (array_keys($variables) as $name) {
                if (str_contains($head . $tail, '{' . $name . '}')) {
                    throw new ServerConfigurationException(sprintf(self::CONFLICT_MESSAGE, $audience, $url, $name, $route->label($route->verbs[0])));
                }
            }

            $built[] = $this->rehost($server, [$head, $domain, $tail], $variables);
        }

        return array_values(array_unique($built, SORT_REGULAR));
    }

    /**
     * Resolve the scheme the route is restricted to, in the order the router
     * applies the restrictions.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @return string|null
     */
    private function scheme(DocumentedRoute $route): ?string
    {
        return match (true) {
            $route->route->httpOnly() => 'http',
            $route->route->secure()   => 'https',
            default                   => null,
        };
    }

    /**
     * Split the URL around its host, forcing the scheme when one is given.
     *
     * A URL without an authority is served from `https://<host>`, followed by
     * the URL when it is an absolute path.
     *
     * @param  string  $url
     * @param  string|null  $scheme
     * @return array{string, string}
     */
    private function aroundHost(string $url, ?string $scheme): array
    {
        if (preg_match(self::AUTHORITY, $url, $matches) !== 1) {
            return [($scheme ?? 'https') . '://', str_starts_with($url, '/') ? $url : ''];
        }

        $prefix = $scheme === null ? $matches[1] : $scheme . ':';

        return [$prefix . '//' . $matches[2], substr($url, strlen($matches[0]))];
    }

    /**
     * Point the server at the domain between the rest of its URL, keeping the
     * variables the rest still uses and adding the domain's.
     *
     * @param  array<string, mixed>  $server
     * @param  array{string, string, string}  $url
     * @param  array<string, array<string, mixed>>  $variables
     * @return array<string, mixed>
     */
    private function rehost(array $server, array $url, array $variables): array
    {
        [$head, $domain, $tail] = $url;

        $kept = array_filter(
            (array) ($server['variables'] ?? []),
            static fn (int|string $name): bool => str_contains($head . $tail, '{' . $name . '}'),
            ARRAY_FILTER_USE_KEY,
        );

        $server['url']       = $head . $domain . $tail;
        $server['variables'] = $kept + $variables;

        if ($server['variables'] === []) {
            unset($server['variables']);
        }

        return $server;
    }
}
