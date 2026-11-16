<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture string-backed enum cast on a ticket's priority.
 *
 * No resource references it, so only a route key can reach its component.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum TicketPriority: string
{
    case LOW  = 'low';
    case HIGH = 'high';
}
