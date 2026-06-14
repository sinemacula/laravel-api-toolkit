<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector;
use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Rule R4: Plural-collections enforcement.
 *
 * Flags every literal URI segment that represents a resource collection but is
 * not plural. A segment is treated as a collection when it is immediately
 * followed by a route-parameter segment (`{param}`), or when it is the last
 * literal segment of the path (a top-level collection). Parameter segments and
 * empty segments are never evaluated. Uncountable nouns configured in the
 * active rule config are treated as plural-safe and never flagged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PluralCollectionsRule implements Rule
{
    /**
     * Create a new plural collections rule.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector  $inflector
     */
    public function __construct(private readonly Inflector $inflector) {}

    /**
     * Return the stable rule identifier.
     *
     * @return string
     */
    #[\Override]
    public function id(): string
    {
        return 'R4';
    }

    /**
     * Return the severity tier for this rule.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Severity
     */
    #[\Override]
    public function severity(): Severity
    {
        return Severity::ERROR;
    }

    /**
     * Inspect one normalised route and return zero or more violations.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
     */
    #[\Override]
    public function inspect(NormalisedRoute $route, RuleConfig $config): array
    {
        $violations = [];
        $segments   = $route->segments;
        $total      = count($segments);

        for ($i = 0; $i < $total; $i++) {
            $segment = $segments[$i];

            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }

            if (!$this->isCollectionSegment($i, $segments)) {
                continue;
            }

            if (in_array($segment, $config->uncountables, true)) {
                continue;
            }

            if ($this->inflector->isPlural($segment)) {
                continue;
            }

            $violations[] = new Violation(
                ruleId: $this->id(),
                severity: $this->severity(),
                routeIdentity: $route->identity(),
                offendingSurface: $segment,
                remediationHint: null,
            );
        }

        return $violations;
    }

    /**
     * Determine whether the segment at the given index is a collection segment.
     *
     * A segment is a collection when it is immediately followed by a
     * route-parameter segment, or when every subsequent segment is either
     * empty or a route parameter (i.e. it is the final literal segment).
     *
     * @param  int  $index
     * @param  array<int, string>  $segments
     * @return bool
     */
    private function isCollectionSegment(int $index, array $segments): bool
    {
        $next = $segments[$index + 1] ?? null;

        if ($next !== null && str_starts_with($next, '{')) {
            return true;
        }

        // The segment is the final literal segment when every subsequent
        // segment is either empty or a route parameter.
        for ($j = $index + 1; $j < count($segments); $j++) {
            if ($segments[$j] !== '' && !str_starts_with($segments[$j], '{')) {
                return false;
            }
        }

        return true;
    }
}
