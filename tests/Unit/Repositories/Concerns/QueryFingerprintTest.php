<?php

namespace Tests\Unit\Repositories\Concerns;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\QueryFingerprint;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Tag;
use Tests\TestCase;

/**
 * Tests for the QueryFingerprint helper.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryFingerprint::class)]
class QueryFingerprintTest extends TestCase
{
    /**
     * Test that an identical query yields a stable fingerprint.
     *
     * @return void
     */
    public function testIdenticalQueriesYieldStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->where('name', 'php'));
        $second = QueryFingerprint::for(Tag::query()->where('name', 'php'));

        static::assertSame($first, $second);
    }

    /**
     * Test that differing SQL yields a distinct fingerprint.
     *
     * @return void
     */
    public function testDifferingSqlYieldsDistinctFingerprint(): void
    {
        $unfiltered = QueryFingerprint::for(Tag::query());
        $filtered   = QueryFingerprint::for(Tag::query()->where('name', 'php'));

        static::assertNotSame($unfiltered, $filtered);
    }

    /**
     * Test that differing bindings yield a distinct fingerprint.
     *
     * @return void
     */
    public function testDifferingBindingsYieldDistinctFingerprint(): void
    {
        $one = QueryFingerprint::for(Tag::query()->where('id', 1));
        $two = QueryFingerprint::for(Tag::query()->where('id', 2));

        static::assertNotSame($one, $two);
    }

    /**
     * Test that a Carbon binding produces a stable fingerprint across
     * equivalent instances.
     *
     * @return void
     */
    public function testCarbonBindingProducesStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse('2026-01-01 00:00:00')));
        $second = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse('2026-01-01 00:00:00')));

        static::assertSame($first, $second);
    }

    /**
     * Test that distinct Carbon bindings yield distinct fingerprints.
     *
     * @return void
     */
    public function testDistinctCarbonBindingsYieldDistinctFingerprints(): void
    {
        $january = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse('2026-01-01 00:00:00')));
        $july    = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse('2026-07-01 00:00:00')));

        static::assertNotSame($january, $july);
    }

    /**
     * Test that an enum binding yields a stable fingerprint.
     *
     * @return void
     */
    public function testEnumBindingYieldsStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->where('name', UserStatus::ACTIVE->value));
        $second = QueryFingerprint::for(Tag::query()->where('name', UserStatus::ACTIVE));

        static::assertSame($first, $second);
    }
}
