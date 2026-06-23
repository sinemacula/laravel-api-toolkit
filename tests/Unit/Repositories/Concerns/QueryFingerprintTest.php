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
final class QueryFingerprintTest extends TestCase
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
     * Test that two queries with identical bindings but differing SQL yield
     * distinct fingerprints, proving the compiled SQL is part of the digest
     * independently of the bindings.
     *
     * @return void
     */
    public function testSameBindingsDifferentSqlYieldDistinctFingerprint(): void
    {
        $equals    = QueryFingerprint::for(Tag::query()->where('name', 'php'));
        $notEquals = QueryFingerprint::for(Tag::query()->where('name', '!=', 'php'));

        static::assertNotSame($equals, $notEquals);
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

    /**
     * Test that the read verb is folded into the fingerprint, so two reads on
     * an identical base builder (e.g. value() vs get()) do not collide.
     *
     * @return void
     */
    public function testDifferingReadVerbYieldsDistinctFingerprint(): void
    {
        $value = QueryFingerprint::for(Tag::query(), 'value', ['name']);
        $get   = QueryFingerprint::for(Tag::query(), 'get');

        static::assertNotSame($value, $get);
    }

    /**
     * Test that the read verb alone discriminates the fingerprint, so two reads
     * with identical arguments but a different verb (e.g. first() vs get()) do
     * not collide.
     *
     * @return void
     */
    public function testDifferingReadVerbWithIdenticalArgumentsYieldsDistinctFingerprint(): void
    {
        $first = QueryFingerprint::for(Tag::query(), 'first');
        $get   = QueryFingerprint::for(Tag::query(), 'get');

        static::assertNotSame($first, $get);
    }

    /**
     * Test that the read verb arguments are folded into the fingerprint, so
     * find(1) and find(2) on an identical base builder do not collide - the
     * by-id read whose constraint is applied at execution time.
     *
     * @return void
     */
    public function testDifferingArgumentsYieldDistinctFingerprint(): void
    {
        $one = QueryFingerprint::for(Tag::query(), 'find', [1]);
        $two = QueryFingerprint::for(Tag::query(), 'find', [2]);

        static::assertNotSame($one, $two);
    }

    /**
     * Test that a differing column projection (the array argument to get())
     * yields a distinct fingerprint, so get(['id','name']) does not collide
     * with get(['id','email']).
     *
     * @return void
     */
    public function testDifferingColumnListYieldsDistinctFingerprint(): void
    {
        $name  = QueryFingerprint::for(Tag::query(), 'get', [['id', 'name']]);
        $email = QueryFingerprint::for(Tag::query(), 'get', [['id', 'email']]);

        static::assertNotSame($name, $email);
    }

    /**
     * Test that an identical verb and arguments on an identical base builder
     * yield a stable fingerprint, so a repeat read still hits the cache.
     *
     * @return void
     */
    public function testIdenticalVerbAndArgumentsYieldStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query(), 'find', [1]);
        $second = QueryFingerprint::for(Tag::query(), 'find', [1]);

        static::assertSame($first, $second);
    }

    /**
     * Test that the registered eager loads are folded into the fingerprint, so
     * with('posts')->get() does not collide with a plain get() (the eager
     * loads are invisible to the compiled base SQL).
     *
     * @return void
     */
    public function testDifferingEagerLoadsYieldDistinctFingerprint(): void
    {
        $eager = QueryFingerprint::for(Tag::query()->with('posts'), 'get');
        $plain = QueryFingerprint::for(Tag::query(), 'get');

        static::assertNotSame($eager, $plain);
    }

    /**
     * Test that the eager load ordering does not affect the fingerprint, so two
     * reads requesting the same relations in a different order still share a
     * cache entry.
     *
     * @return void
     */
    public function testEagerLoadOrderIsIgnored(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->with('posts')->with('articles'), 'get');
        $second = QueryFingerprint::for(Tag::query()->with('articles')->with('posts'), 'get');

        static::assertSame($first, $second);
    }

    /**
     * Test that a DateTime binding is normalised to its ATOM representation, so
     * a query bound with a DateTime and one bound with the equivalent ATOM
     * string resolve to the same fingerprint.
     *
     * @return void
     */
    public function testDateTimeBindingMatchesItsAtomStringRepresentation(): void
    {
        $moment = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        $object = QueryFingerprint::for(Tag::query()->where('created_at', $moment));
        $string = QueryFingerprint::for(Tag::query()->where('created_at', $moment->format(\DateTimeInterface::ATOM)));

        static::assertSame($object, $string);
    }

    /**
     * Test that bindings which cannot be JSON-encoded (e.g. invalid UTF-8) fall
     * back to a stable serialised representation that still distinguishes
     * distinct values.
     *
     * @return void
     */
    public function testNonJsonEncodableBindingsYieldDistinctFingerprints(): void
    {
        $one = QueryFingerprint::for(Tag::query()->where('name', "\xB1\x31"));
        $two = QueryFingerprint::for(Tag::query()->where('name', "\xB2\x32"));

        static::assertNotSame($one, $two);
    }
}
