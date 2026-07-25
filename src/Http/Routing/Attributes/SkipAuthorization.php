<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing\Attributes;

/**
 * Exclude a single resource action from the authorization guard.
 *
 * Placed on a public action method of an AuthorizedController subclass, this
 * marker leaves that action ungated by the resource authorization middleware,
 * mirroring an entry in the AuthorizesResource except list without naming the
 * method away from its definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class SkipAuthorization {}
