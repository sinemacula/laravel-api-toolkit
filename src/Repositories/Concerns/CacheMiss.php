<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Marker stored in the per-query cache to represent a negatively cached
 * null/miss read.
 *
 * A dedicated marker type lets the cache distinguish "this query was executed
 * and returned nothing" from an absent entry, without colliding with any real
 * repository result (no query result is an instance of this class) and while
 * surviving serialization on non-array cache stores - it is matched via
 * instanceof rather than object identity.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheMiss {}
