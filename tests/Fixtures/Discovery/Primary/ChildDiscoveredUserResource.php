<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Primary;

/**
 * Fixture child of an attributed resource without its own ForModel binding;
 * class attributes are not inherited, so discovery must ignore it silently.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ChildDiscoveredUserResource extends DiscoveredUserResource {}
