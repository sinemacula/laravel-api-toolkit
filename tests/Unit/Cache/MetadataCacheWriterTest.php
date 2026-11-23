<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;
use Tests\Fixtures\Support\UnreachableCacheStore;
use Tests\TestCase;

/**
 * Tests for the MetadataCacheWriter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MetadataCacheWriter::class)]
#[CoversClass(MetadataCacheUnavailableException::class)]
final class MetadataCacheWriterTest extends TestCase
{
    /**
     * Fake exception reporting for each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
    }

    /**
     * Reset the frozen clock after each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Test that rememberMetadata serves a value already stored under the
     * current generation without running the callback.
     *
     * @return void
     */
    public function testRememberMetadataServesAWarmValueWithoutTheCallback(): void
    {
        $key    = self::warmKey();
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn () => 'pre-warmed-value');

        $calls = 0;

        $value = $writer->rememberMetadata($key, static function () use (&$calls, $key): string {
            $calls++;

            return $key;
        }, 60);

        self::assertSame('pre-warmed-value', $value);
        self::assertSame(0, $calls);
    }

    /**
     * Test that readMetadata serves a value another process wrote under the
     * current generation.
     *
     * @return void
     */
    public function testReadMetadataServesAValueWrittenElsewhere(): void
    {
        $key    = 'written-elsewhere-key';
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn () => 'value-from-another-process');

        self::assertSame('value-from-another-process', (new MetadataCacheWriter(new MetadataGeneration))->readMetadata($key));
    }

    /**
     * Test that readMetadata reports the caller's own answer for absence.
     *
     * @return void
     */
    public function testReadMetadataReportsAbsence(): void
    {
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        self::assertNull($writer->readMetadata('never-written-key'));
        self::assertSame([], $writer->readMetadata('never-written-key', []));
    }

    /**
     * Test that readMetadata does not serve a value stored under the bare
     * metadata key.
     *
     * @return void
     */
    public function testReadMetadataIgnoresAValueStoredWithoutTheGeneration(): void
    {
        Cache::memo()->rememberForever('bare-key', fn (): string => 'unversioned');

        self::assertNull((new MetadataCacheWriter(new MetadataGeneration))->readMetadata('bare-key'));
    }

    /**
     * Test that a value stored as an empty array is not read as absence.
     *
     * @return void
     */
    public function testReadMetadataReportsAnEmptyValueRatherThanAbsence(): void
    {
        $key    = 'empty-value-key';
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn (): array => []);

        self::assertSame([], $writer->readMetadata($key, ['absent']));
    }

    /**
     * Test that storeMetadata writes the value under the current generation,
     * replacing whatever the store held under the key.
     *
     * @return void
     */
    public function testStoreMetadataReplacesTheStoredValue(): void
    {
        $writer = $this->writer();

        Cache::memo()->forever($writer->storageKey('stored-key'), 'stale'); // @phpstan-ignore method.notFound

        $writer->storeMetadata('stored-key', ['fresh']);

        self::assertSame(['fresh'], Cache::memo()->get($writer->storageKey('stored-key')));
        self::assertSame(['fresh'], $writer->readMetadata('stored-key'));
    }

    /**
     * Test that storeMetadata stores the value for the configured metadata
     * time-to-live, never forever.
     *
     * @return void
     */
    public function testStoreMetadataWritesForTheConfiguredTtl(): void
    {
        Config::set('api-toolkit.cache.metadata_ttl', '4321');

        $writer     = $this->writer();
        $storageKey = $writer->storageKey('expiring-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('put')->once()->with($storageKey, 'value', 4321)->andReturnTrue();

        Cache::shouldReceive('memo')->andReturn($repository);

        $writer->storeMetadata('expiring-key', 'value');

        Exceptions::assertNothingReported();
    }

    /**
     * Test that a stored entry is gone once the metadata time-to-live lapses.
     *
     * @return void
     */
    public function testAStoredEntryExpiresAfterTheMetadataTtl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00'));
        Config::set('api-toolkit.cache.metadata_ttl', 60);

        $writer     = $this->writer();
        $storageKey = $writer->storageKey('expiring-key');

        $writer->storeMetadata('expiring-key', 'value');

        Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:59'));

        self::assertSame('value', Cache::get($storageKey));

        Carbon::setTestNow(Carbon::parse('2026-11-23 12:01:00'));

        self::assertNull(Cache::get($storageKey));
    }

    /**
     * Test that the entries a retired generation holds age out of the store.
     *
     * @return void
     */
    public function testARetiredGenerationsEntriesExpire(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00'));
        Config::set('api-toolkit.cache.metadata_ttl', 60);

        $generation = new MetadataGeneration;
        $writer     = new MetadataCacheWriter($generation);
        $retired    = $writer->storageKey('retired-key');

        $writer->storeMetadata('retired-key', 'value');
        $generation->advance();

        self::assertSame('value', Cache::get($retired));

        Carbon::setTestNow(Carbon::parse('2026-11-23 12:01:00'));

        self::assertNull(Cache::get($retired));
    }

    /**
     * Provide metadata time-to-live values below a second or not whole.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidMetadataTtlProvider(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'negative' => [-1, '-1'];
        yield 'fraction' => ['1.5', '\'1.5\''];
    }

    /**
     * Test that storeMetadata refuses a metadata time-to-live below a second
     * rather than storing forever or nothing.
     *
     * @param  mixed  $ttl
     * @param  string  $given
     * @return void
     */
    #[DataProvider('invalidMetadataTtlProvider')]
    public function testStoreMetadataRefusesAnInvalidTtl(mixed $ttl, string $given): void
    {
        Config::set('api-toolkit.cache.metadata_ttl', $ttl);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('The api-toolkit.cache.metadata_ttl setting must be an integer of at least 1; [%s] given.', $given));

        $this->writer()->storeMetadata('refused-key', 'value');
    }

    /**
     * Test that storeMetadata reports a write the store rejects.
     *
     * @return void
     */
    public function testStoreMetadataReportsARejectedWrite(): void
    {
        $writer     = $this->writer();
        $storageKey = $writer->storageKey('rejected-store-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('put')->once()->with($storageKey, 'value', \Mockery::type('int'))->andReturnFalse();

        Cache::shouldReceive('memo')->andReturn($repository);

        $writer->storeMetadata('rejected-store-key', 'value');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->context()['key'] === 'rejected-store-key');
    }

    /**
     * Test that storeMetadata reports an unreachable store once, at the
     * generation read.
     *
     * @return void
     */
    public function testStoreMetadataReportsAnUnreachableStore(): void
    {
        $this->useUnreachableCacheStore();

        $this->writer()->storeMetadata('outage-key', 'value');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->context()['operation'] === 'generation');
    }

    /**
     * Test that readMetadata serves a stored value through the decoder.
     *
     * @return void
     */
    public function testReadMetadataServesTheDecodedValue(): void
    {
        $writer = $this->writer();

        $writer->storeMetadata('decoded-key', 'stored');

        self::assertSame('decoded stored', $writer->readMetadata('decoded-key', 'missing', static fn (mixed $stored): string => 'decoded ' . (is_string($stored) ? $stored : '')));
    }

    /**
     * Test that a stored value the decoder rejects reads as a miss.
     *
     * @return void
     */
    public function testReadMetadataReadsAValueTheDecoderRejectsAsAMiss(): void
    {
        $writer = $this->writer();

        $writer->storeMetadata('rejected-shape-key', 'unreadable');

        self::assertSame('missing', $writer->readMetadata('rejected-shape-key', 'missing', static fn (mixed $stored): mixed => null));
    }

    /**
     * Test that the decoder is not given an absent value.
     *
     * @return void
     */
    public function testReadMetadataDoesNotDecodeAnAbsentValue(): void
    {
        self::assertSame('missing', $this->writer()->readMetadata('absent-key', 'missing', static fn (mixed $stored): string => 'decoded'));
    }

    /**
     * Test that rememberMetadata returns the value produced by the callback.
     *
     * @return void
     */
    public function testRememberMetadataReturnsTheCallbackValue(): void
    {
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        $value = $writer->rememberMetadata('ttl-key', fn () => 'expected-value', 3600);

        self::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadata persists the value to the memo store.
     *
     * @return void
     */
    public function testRememberMetadataWritesToTheMemoStore(): void
    {
        $writer = new MetadataCacheWriter(new MetadataGeneration);
        $key    = 'ttl-memo-store-key';

        $writer->rememberMetadata($key, fn () => 'stored-value', 3600);

        self::assertSame('stored-value', Cache::memo()->get($writer->storageKey($key)));
    }

    /**
     * Test that rememberMetadata passes the given time-to-live to the store.
     *
     * @return void
     */
    public function testRememberMetadataPassesTheTtlToTheStore(): void
    {
        $writer     = new MetadataCacheWriter(new MetadataGeneration);
        $storageKey = $writer->storageKey('ttl-passthrough-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('get')
            ->once()
            ->with($storageKey)
            ->andReturnNull();
        $repository->shouldReceive('put')
            ->once()
            ->with($storageKey, 'value', 1234)
            ->andReturnTrue();

        Cache::shouldReceive('memo')
            ->twice()
            ->andReturn($repository);

        $value = $writer->rememberMetadata('ttl-passthrough-key', fn () => 'value', 1234);

        self::assertSame('value', $value);
    }

    /**
     * Test that the stored key carries the current generation.
     *
     * @return void
     */
    public function testStorageKeyIsNamespacedByTheCurrentGeneration(): void
    {
        $generation = new MetadataGeneration;
        $writer     = new MetadataCacheWriter($generation);

        self::assertSame('metadata-key:' . $generation->current(), $writer->storageKey('metadata-key'));

        $advanced = $generation->advance();

        self::assertSame('metadata-key:' . $advanced, $writer->storageKey('metadata-key'));
    }

    /**
     * Test that a value remembered under one generation is not served once the
     * generation has moved on, and the callback runs again.
     *
     * @return void
     */
    public function testAdvancingTheGenerationRetiresRememberedValues(): void
    {
        $generation = new MetadataGeneration;
        $writer     = new MetadataCacheWriter($generation);

        $writer->rememberMetadata('retired-key', fn (): string => 'before', 60);
        $generation->advance();

        self::assertNull($writer->readMetadata('retired-key'));
        self::assertSame('after', $writer->rememberMetadata('retired-key', fn (): string => 'after', 60));
    }

    /**
     * Test that rememberMetadata computes the value once and reports the store
     * failure when the store is unreachable.
     *
     * @return void
     */
    public function testRememberMetadataComputesTheValueWhenTheStoreIsUnreachable(): void
    {
        $this->useUnreachableCacheStore();

        $calls = 0;

        $value = $this->writer()->rememberMetadata('outage-key', static function () use (&$calls): string {
            $calls++;

            return 'computed';
        }, 60);

        self::assertSame('computed', $value);
        self::assertSame(1, $calls);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->getPrevious()?->getMessage() === UnreachableCacheStore::MESSAGE);
    }

    /**
     * Test that readMetadata reports absence when the store is unreachable.
     *
     * @return void
     */
    public function testReadMetadataReportsAbsenceWhenTheStoreIsUnreachable(): void
    {
        $this->useUnreachableCacheStore();

        self::assertSame(['missing'], $this->writer()->readMetadata('outage-key', ['missing']));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(MetadataCacheUnavailableException::class);
    }

    /**
     * Test that a store failure partway through, after the generation resolved,
     * still yields the computed value.
     *
     * @return void
     */
    public function testAFailedWriteAfterAResolvedGenerationStillYieldsTheValue(): void
    {
        $writer     = $this->writer();
        $storageKey = $writer->storageKey('write-failure-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('get')->once()->with($storageKey)->andReturnNull();
        $repository->shouldReceive('put')->once()->andThrow(new \RuntimeException('Write failed.'));

        Cache::shouldReceive('memo')->andReturn($repository);

        self::assertSame('computed', $writer->rememberMetadata('write-failure-key', static fn (): string => 'computed', 60));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->getPrevious()?->getMessage() === 'Write failed.');
    }

    /**
     * Test that a rejected write is reported once a window without bypassing
     * the store, which may still serve its warm entries.
     *
     * @return void
     */
    public function testARejectedWriteIsReportedOnceWithoutBypassingTheStore(): void
    {
        $writer     = $this->writer();
        $storageKey = $writer->storageKey('rejected-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('get')->twice()->with($storageKey)->andReturnNull();
        $repository->shouldReceive('put')->twice()->with($storageKey, \Mockery::any(), 60)->andReturnFalse();
        $repository->shouldReceive('get')->once()->with($writer->storageKey('warm-key'))->andReturn('warm');

        Cache::shouldReceive('memo')->andReturn($repository);

        self::assertSame('computed', $writer->rememberMetadata('rejected-key', static fn (): string => 'computed', 60));
        self::assertSame('again', $writer->rememberMetadata('rejected-key', static fn (): string => 'again', 60));
        self::assertSame('warm', $writer->readMetadata('warm-key'));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->getPrevious() === null && $exception->getCode() === 0);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->context() === [
            'operation' => 'write',
            'store'     => Config::get('cache.default'),
            'key'       => 'rejected-key',
        ]);
    }

    /**
     * Test that a rejected write is reported again once the window ends.
     *
     * @return void
     */
    public function testARejectedWriteIsReportedAgainAfterTheWindow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));

        $writer     = $this->writer();
        $storageKey = $writer->storageKey('rejected-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('get')->times(3)->with($storageKey)->andReturnNull();
        $repository->shouldReceive('put')->times(3)->with($storageKey, \Mockery::any(), 60)->andReturnFalse();

        Cache::shouldReceive('memo')->andReturn($repository);

        $writer->rememberMetadata('rejected-key', static fn (): string => 'first', 60);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:29'));

        $writer->rememberMetadata('rejected-key', static fn (): string => 'second', 60);

        Exceptions::assertReportedCount(1);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:30'));

        $writer->rememberMetadata('rejected-key', static fn (): string => 'third', 60);

        Exceptions::assertReportedCount(2);
    }

    /**
     * Test that the null store's rejected writes are not reported.
     *
     * @return void
     */
    public function testTheNullStoreRejectingWritesIsNotReported(): void
    {
        Config::set('cache.stores.none', ['driver' => 'null']);
        Config::set('cache.default', 'none');

        self::assertSame('computed', $this->writer()->rememberMetadata('null-store-key', static fn (): string => 'computed', 60));

        Exceptions::assertNothingReported();
    }

    /**
     * Test that a time-to-live of zero or less stores nothing and touches no
     * store.
     *
     * @return void
     */
    public function testANonPositiveTtlStoresNothing(): void
    {
        $writer = $this->writer();

        Cache::shouldReceive('memo')->never();

        self::assertSame('zero', $writer->rememberMetadata('ttl-key', static fn (): string => 'zero', 0));
        self::assertSame('negative', $writer->rememberMetadata('ttl-key', static fn (): string => 'negative', -5));

        Exceptions::assertNothingReported();
    }

    /**
     * Test that a failed read after the generation resolved reports absence and
     * bypasses the store.
     *
     * @return void
     */
    public function testAFailedReadAfterAResolvedGenerationReportsAbsence(): void
    {
        $writer     = $this->writer();
        $storageKey = $writer->storageKey('read-failure-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('get')->once()->with($storageKey)->andThrow(new \RuntimeException('Read failed.'));

        Cache::shouldReceive('memo')->andReturn($repository);

        self::assertSame(['missing'], $writer->readMetadata('read-failure-key', ['missing']));
        self::assertSame(['missing'], $writer->readMetadata('read-failure-key', ['missing']));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->getPrevious()?->getMessage() === 'Read failed.'
            && $exception->context()['operation']                                                                                             === 'get');
    }

    /**
     * Test that a failed read inside remember runs the callback once and skips
     * the write, since the store has just been bypassed.
     *
     * @return void
     */
    public function testAFailedReadInsideRememberRunsTheCallbackOnceWithoutWriting(): void
    {
        $writer     = $this->writer();
        $storageKey = $writer->storageKey('remember-read-failure-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('get')->once()->with($storageKey)->andThrow(new \RuntimeException('Read failed.'));
        $repository->shouldReceive('put')->never();

        Cache::shouldReceive('memo')->andReturn($repository);

        $calls = 0;

        $value = $writer->rememberMetadata('remember-read-failure-key', static function () use (&$calls): string {
            $calls++;

            return 'computed';
        }, 60);

        self::assertSame('computed', $value);
        self::assertSame(1, $calls);

        Exceptions::assertReportedCount(1);
    }

    /**
     * Test that a closure given as the missing value is resolved on a miss and
     * on a failed read alike.
     *
     * @return void
     */
    public function testAClosureMissingValueIsResolvedOnAMissAndAFailure(): void
    {
        self::assertSame('resolved', $this->writer()->readMetadata('closure-key', static fn (): string => 'resolved'));

        $this->useUnreachableCacheStore();

        self::assertSame('resolved', $this->writer()->readMetadata('closure-key', static fn (): string => 'resolved'));
        self::assertSame('resolved', $this->writer()->readMetadata('closure-key', static fn (): string => 'resolved'));
    }

    /**
     * Test that the retry window is shared by every writer in the process, as
     * the writer of each per-request application under Octane must share it.
     *
     * @return void
     */
    public function testTheRetryWindowIsSharedByEveryWriterInTheProcess(): void
    {
        $this->useUnreachableCacheStore();

        $this->writer()->readMetadata('shared-window-key');
        $this->writer()->readMetadata('shared-window-key');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->context() === [
            'operation' => 'generation',
            'store'     => 'unreachable',
            'key'       => 'shared-window-key',
        ]);
    }

    /**
     * Test that the generation is read from the store again after the window,
     * so an invalidation made during the outage is picked up.
     *
     * @return void
     */
    public function testTheGenerationIsReadAgainAfterTheWindow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));

        $writer  = $this->writer();
        $healthy = Config::get('cache.default');

        $writer->storageKey('generation-key');

        $this->useUnreachableCacheStore();

        $writer->readMetadata('generation-key');

        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->context()['operation'] === 'get');

        Config::set('cache.default', $healthy);

        Cache::forever(CacheKeys::METADATA_GENERATION->resolveKey(), 'replaced-during-outage');

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:01:00'));

        self::assertSame('generation-key:replaced-during-outage', $writer->storageKey('generation-key'));
    }

    /**
     * Test that an exception from the callback propagates unreported and the
     * callback runs once, since it is not a store failure.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testACallbackExceptionPropagatesWithoutBeingReported(): void
    {
        $calls = 0;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Callback failed.');

        try {
            $this->writer()->rememberMetadata('callback-failure-key', static function () use (&$calls): never {
                $calls++;

                throw new \LogicException('Callback failed.');
            }, 60);
        } finally {
            self::assertSame(1, $calls);

            Exceptions::assertNothingReported();
        }
    }

    /**
     * Test that the store is bypassed for the retry window once it fails.
     *
     * @return void
     */
    public function testTheStoreIsBypassedForTheRetryWindow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        Config::set('api-toolkit.cache.retry_after', 30);

        $this->useUnreachableCacheStore();

        $writer = $this->writer();

        $writer->rememberMetadata('window-key', static fn (): string => 'first', 60);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:29'));

        self::assertSame('second', $writer->rememberMetadata('window-key', static fn (): string => 'second', 60));
        self::assertSame('third', $writer->rememberMetadata('window-key', static fn (): string => 'third', 60));
        self::assertNull($writer->readMetadata('window-key'));

        Exceptions::assertReportedCount(1);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:30'));

        $writer->rememberMetadata('window-key', static fn (): string => 'fourth', 60);

        Exceptions::assertReportedCount(2);
    }

    /**
     * Provide configured retry windows and the window each is read as.
     *
     * @return iterable<string, array{mixed, int}>
     */
    public static function retryWindowProvider(): iterable
    {
        yield 'an integer' => [45, 45];
        yield 'a numeric string, as the environment gives' => ['45', 45];
    }

    /**
     * Test that the configured retry window is read as whole seconds.
     *
     * @param  mixed  $configured
     * @param  int  $expected
     * @return void
     */
    #[DataProvider('retryWindowProvider')]
    public function testTheRetryWindowIsReadFromConfiguration(mixed $configured, int $expected): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        Config::set('api-toolkit.cache.retry_after', $configured);

        $this->useUnreachableCacheStore();

        $writer = $this->writer();

        $writer->readMetadata('configured-window-key');

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00')->addSeconds($expected - 1));

        $writer->readMetadata('configured-window-key');

        Exceptions::assertReportedCount(1);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00')->addSeconds($expected));

        $writer->readMetadata('configured-window-key');

        Exceptions::assertReportedCount(2);
    }

    /**
     * Provide retry windows that are not a whole number of seconds.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidRetryWindowProvider(): iterable
    {
        yield 'a non-numeric string' => ['not-a-number', '\'not-a-number\''];
        yield 'a negative number' => [-5, '-5'];
        yield 'nothing' => [null, 'null'];
    }

    /**
     * Test that an invalid retry window fails rather than being defaulted.
     *
     * @param  mixed  $configured
     * @param  string  $given
     * @return void
     */
    #[DataProvider('invalidRetryWindowProvider')]
    public function testAnInvalidRetryWindowFails(mixed $configured, string $given): void
    {
        Config::set('api-toolkit.cache.retry_after', $configured);

        $this->useUnreachableCacheStore();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('The api-toolkit.cache.retry_after setting must be an integer of at least 0; [%s] given.', $given));

        $this->writer()->readMetadata('configured-window-key');
    }

    /**
     * Test that a zero retry window retries, and reports, on every call.
     *
     * @return void
     */
    public function testAZeroRetryWindowRetriesTheStoreOnEveryCall(): void
    {
        Config::set('api-toolkit.cache.retry_after', 0);

        $this->useUnreachableCacheStore();

        $writer = $this->writer();

        $writer->readMetadata('zero-window-key');
        $writer->readMetadata('zero-window-key');

        Exceptions::assertReportedCount(2);
    }

    /**
     * Test that a lifecycle flush does not end the retry window.
     *
     * @return void
     */
    public function testALifecycleFlushDoesNotEndTheRetryWindow(): void
    {
        $this->useUnreachableCacheStore();

        assert($this->app !== null);

        $writer = $this->app->make(MetadataCacheWriter::class);

        $writer->readMetadata('flush-key');

        $this->app->make(CacheManager::class)->flush();

        $writer->readMetadata('flush-key');

        Exceptions::assertReportedCount(1);
    }

    /**
     * Test that once the retry window ends against a recovered store, values
     * are stored under the shared generation again.
     *
     * @return void
     */
    public function testARecoveredStoreIsUsedAgainAfterTheRetryWindow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));

        $default = Config::get('cache.default');

        $this->useUnreachableCacheStore();

        $writer = $this->writer();

        $writer->rememberMetadata('recovery-key', static fn (): string => 'during-outage', 60);

        Config::set('cache.default', $default);
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:01:00'));

        self::assertSame('recovered', $writer->rememberMetadata('recovery-key', static fn (): string => 'recovered', 60));

        $generation = Cache::get(CacheKeys::METADATA_GENERATION->resolveKey());

        self::assertIsString($generation);
        self::assertSame('recovered', Cache::get('recovery-key:' . $generation));

        Exceptions::assertReportedCount(1);
    }

    /**
     * Return the key the warm-value test stores under.
     *
     * @return string
     */
    private static function warmKey(): string
    {
        return 'warm-cache-key';
    }

    /**
     * Build a writer over a fresh generation.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    private function writer(): MetadataCacheWriter
    {
        return new MetadataCacheWriter(new MetadataGeneration);
    }
}
