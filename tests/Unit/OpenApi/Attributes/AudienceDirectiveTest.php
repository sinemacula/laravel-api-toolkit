<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\AudienceDirective;

/**
 * Tests for the AudienceDirective base attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AudienceDirective::class)]
final class AudienceDirectiveTest extends TestCase
{
    /**
     * Test that positional audiences are stored as a zero-indexed list.
     *
     * @return void
     */
    public function testStoresPositionalAudiencesAsAList(): void
    {
        $directive = new readonly class ('public', 'internal') extends AudienceDirective {};

        self::assertSame(['public', 'internal'], $directive->audiences);
    }

    /**
     * Test that named audiences are reindexed to a zero-indexed list, so the
     * stored value always satisfies the list<string> contract.
     *
     * @return void
     */
    public function testReindexesNamedAudiencesToAList(): void
    {
        $directive = new readonly class (first: 'public', second: 'internal') extends AudienceDirective {};

        self::assertSame(['public', 'internal'], $directive->audiences);
    }
}
