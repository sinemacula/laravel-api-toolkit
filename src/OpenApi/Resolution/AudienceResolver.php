<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\NotDocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;

/**
 * Decides whether a route belongs to a given OpenAPI audience.
 *
 * Membership is resolved from three directive sources - route macros, method
 * attributes, and class attributes - applying the most-specific-wins rule: a
 * route macro overrides a method attribute, which overrides a class attribute.
 * A level decides the outcome for an audience only when it speaks about that
 * audience (names it, or carries a blanket Undocumented); otherwise resolution
 * falls through to the next-broader level and finally to the audience posture.
 * Under the blocklist posture a route is a member by default; under the
 * allowlist posture it is a member only when explicitly included.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class AudienceResolver
{
    /** @var string The route action key the route macros store directives on. */
    public const string ROUTE_ACTION_KEY = 'sinemacula.openapi.audience';

    /**
     * Determine whether the route belongs to the given audience.
     *
     * @param  string  $audience
     * @param  string  $posture
     * @param  class-string|null  $controllerClass
     * @param  string|null  $method
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    public function isInAudience(string $audience, string $posture, ?string $controllerClass, ?string $method, Route $route): bool
    {
        $levels = [
            $this->routeDirectives($route),
            $this->methodDirectives($controllerClass, $method),
            $this->classDirectives($controllerClass),
        ];

        foreach ($levels as $level) {

            if ($level === null) {
                continue;
            }

            $included = in_array($audience, $level['documentedIn'], true);
            $excluded = in_array($audience, $level['notDocumentedIn'], true);

            // Skip a level that says nothing about this audience.
            if (!$included && !$excluded && !$level['undocumented']) {
                continue;
            }

            // Exclusion wins over a same-level inclusion; inclusion overrides a
            // blanket Undocumented for the named audience.
            return $included && !$excluded;
        }

        return $posture !== QuerySurface::POSTURE_ALLOWLIST;
    }

    /**
     * Read the directives stored on the route by the route macros.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array{undocumented: bool, documentedIn: list<string>, notDocumentedIn: list<string>}|null
     */
    private function routeDirectives(Route $route): ?array
    {
        $stored = $route->getAction(self::ROUTE_ACTION_KEY);

        if (!is_array($stored)) {
            return null;
        }

        return [
            'undocumented'    => (bool) ($stored['undocumented'] ?? false),
            'documentedIn'    => array_values(array_map('strval', (array) ($stored['documentedIn'] ?? []))),
            'notDocumentedIn' => array_values(array_map('strval', (array) ($stored['notDocumentedIn'] ?? []))),
        ];
    }

    /**
     * Read the directives declared by attributes on the action method.
     *
     * @param  class-string|null  $controllerClass
     * @param  string|null  $method
     * @return array{undocumented: bool, documentedIn: list<string>, notDocumentedIn: list<string>}|null
     */
    private function methodDirectives(?string $controllerClass, ?string $method): ?array
    {
        if ($controllerClass === null || $method === null) {
            return null;
        }

        if (!class_exists($controllerClass) || !method_exists($controllerClass, $method)) {
            return null;
        }

        return $this->directivesFrom(new \ReflectionMethod($controllerClass, $method));
    }

    /**
     * Read the directives declared by attributes on the controller class.
     *
     * @param  class-string|null  $controllerClass
     * @return array{undocumented: bool, documentedIn: list<string>, notDocumentedIn: list<string>}|null
     */
    private function classDirectives(?string $controllerClass): ?array
    {
        if ($controllerClass === null || !class_exists($controllerClass)) {
            return null;
        }

        return $this->directivesFrom(new \ReflectionClass($controllerClass));
    }

    /**
     * Collapse the audience attributes on a reflector into a directive set.
     *
     * @param  \ReflectionClass<object>|\ReflectionMethod  $reflector
     * @return array{undocumented: bool, documentedIn: list<string>, notDocumentedIn: list<string>}|null
     */
    private function directivesFrom(\ReflectionClass|\ReflectionMethod $reflector): ?array
    {
        $undocumented    = $reflector->getAttributes(Undocumented::class) !== [];
        $documentedIn    = [];
        $notDocumentedIn = [];

        foreach ($reflector->getAttributes(DocumentedIn::class) as $attribute) {
            $documentedIn = array_merge($documentedIn, $attribute->newInstance()->audiences);
        }

        foreach ($reflector->getAttributes(NotDocumentedIn::class) as $attribute) {
            $notDocumentedIn = array_merge($notDocumentedIn, $attribute->newInstance()->audiences);
        }

        if (!$undocumented && $documentedIn === [] && $notDocumentedIn === []) {
            return null;
        }

        return [
            'undocumented'    => $undocumented,
            'documentedIn'    => $documentedIn,
            'notDocumentedIn' => $notDocumentedIn,
        ];
    }
}
