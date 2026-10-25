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
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;
use Tests\Fixtures\Support\UnreachableCacheStore;
use Tests\TestCase;

/**
 * Tests for the MetadataCacheWriter chokepoint.
 *
 * The metadata cache is optional: a store that throws or rejects a write is
 * reported once and bypassed for the retry window, so values are computed
 * without the cache rather than failing the caller.
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
     * Test that rememberMetadataForever returns the value produced by the
     * callback.
     *
     * @return void
     */
    public function testRememberMetadataForeverReturnsTheCallbackValue(): void
    {
        // Arrange
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        // Act
        $value = $writer->rememberMetadataForever('test-key', fn () => 'expected-value');

        // Assert
        self::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadataForever persists the value to the memo store.
     *
     * @return void
     */
    public function testRememberMetadataForeverWritesToTheMemoStore(): void
    {
        // Arrange
        $writer = new MetadataCacheWriter(new MetadataGeneration);
        $key    = 'memo-store-key';

        // Act
        $writer->rememberMetadataForever($key, fn () => 'stored-value');

        // Assert
        self::assertSame('stored-value', Cache::memo()->get($writer->storageKey($key)));
    }

    /**
     * Test that rememberMetadataForever serves a value another process already
     * stored under the current generation without running the callback.
     *
     * @return void
     */
    public function testRememberMetadataForeverServesAWarmValueWithoutTheCallback(): void
    {
        $key    = self::warmKey();
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn () => 'pre-warmed-value');

        $calls = 0;

        $value = $writer->rememberMetadataForever($key, static function () use (&$calls, $key): string {
            $calls++;

            return $key;
        });

        self::assertSame('pre-warmed-value', $value);
        self::assertSame(0, $calls);
    }

    /**
     * Test that readMetadata serves a value another process wrote under the
     * current generation, which is what lets every worker share the store.
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
     * metadata key, since only the generation-namespaced key is current.
     *
     * @return void
     */
    public function testReadMetadataIgnoresAValueStoredWithoutTheGeneration(): void
    {
        Cache::memo()->rememberForever('bare-key', fn (): string => 'unversioned');

        self::assertNull((new MetadataCacheWriter(new MetadataGeneration))->readMetadata('bare-key'));
    }

    /**
     * Test that a value stored as an empty array reads back as itself rather
     * than as absence.
     *
     * An empty listing is a real answer about a table, so reading it as a miss
     * would send every reader back to the connection for something already
     * settled.
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
     * Test that rememberMetadata returns the value produced by the callback.
     *
     * @return void
     */
    public function testRememberMetadataReturnsTheCallbackValue(): void
    {
        // Arrange
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        // Act
        $value = $writer->rememberMetadata('ttl-key', fn () => 'expected-value', 3600);

        // Assert
        self::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadata persists the value to the memo store.
     *
     * @return void
     */
    public function testRememberMetadataWritesToTheMemoStore(): void
    {
        // Arrange
        $writer = new MetadataCacheWriter(new MetadataGeneration);
        $key    = 'ttl-memo-store-key';

        // Act
        $writer->rememberMetadata($key, fn () => 'stored-value', 3600);

        // Assert
        self::assertSame('stored-value', Cache::memo()->get($writer->storageKey($key)));
    }

    /**
     * Test that rememberMetadata passes the given time-to-live through to the
     * underlying store rather than storing the value forever.
     *
     * @return void
     */
    public function testRememberMetadataPassesTheTtlToTheStore(): void
    {
        // Arrange
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

        // Act
        $value = $writer->rememberMetadata('ttl-passthrough-key', fn () => 'value', 1234);

        // Assert
        self::assertSame('value', $value);
    }

    /**
     * Test that the stored key carries the current generation, so the same
     * metadata key lands somewhere else once the generation moves on.
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

        $writer->rememberMetadataForever('retired-key', fn (): string => 'before');
        $generation->advance();

        self::assertNull($writer->readMetadata('retired-key'));
        self::assertSame('after', $writer->rememberMetadataForever('retired-key', fn (): string => 'after'));
    }

    /**
     * Test that rememberMetadataForever computes the value once and reports the
     * store failure when the store is unreachable.
     *
     * @return void
     */
    public function testRememberMetadataForeverComputesTheValueWhenTheStoreIsUnreachable(): void
    {
        $this->useUnreachableCacheStore();

        $calls = 0;

        $value = $this->writer()->rememberMetadataForever('outage-key', static function () use (&$calls): string {
            $calls++;

            return 'computed';
        });

        self::assertSame('computed', $value);
        self::assertSame(1, $calls);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->getPrevious()?->getMessage() === UnreachableCacheStore::MESSAGE);
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
        Exceptions::assertReported(MetadataCacheUnavailableException::class);
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
        $repository->shouldReceive('forever')->once()->andThrow(new \RuntimeException('Write failed.'));

        Cache::shouldReceive('memo')->andReturn($repository);

        self::assertSame('computed', $writer->rememberMetadataForever('write-failure-key', static fn (): string => 'computed'));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (MetadataCacheUnavailableException $exception): bool => $exception->getPrevious()?->getMessage() === 'Write failed.');
    }

    /**
     * Test that a write the store rejects is reported once a window without
     * bypassing the store, which may still serve its warm entries.
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
        $repository->shouldReceive('forever')->times(3)->with($storageKey, \Mockery::any())->andReturnFalse();

        Cache::shouldReceive('memo')->andReturn($repository);

        $writer->rememberMetadataForever('rejected-key', static fn (): string => 'first');

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:29'));

        $writer->rememberMetadataForever('rejected-key', static fn (): string => 'second');

        Exceptions::assertReportedCount(1);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:30'));

        $writer->rememberMetadataForever('rejected-key', static fn (): string => 'third');

        Exceptions::assertReportedCount(2);
    }

    /**
     * Test that the null store's rejected writes are not reported, since it
     * rejects every write by design.
     *
     * @return void
     */
    public function testTheNullStoreRejectingWritesIsNotReported(): void
    {
        Config::set('cache.stores.none', ['driver' => 'null']);
        Config::set('cache.default', 'none');

        self::assertSame('computed', $this->writer()->rememberMetadataForever('null-store-key', static fn (): string => 'computed'));

        Exceptions::assertNothingReported();
    }

    /**
     * Test that a time-to-live of zero or less stores nothing and touches no
     * store, rather than reading as a rejected write.
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
        $repository->shouldReceive('forever')->never();

        Cache::shouldReceive('memo')->andReturn($repository);

        $calls = 0;

        $value = $writer->rememberMetadataForever('remember-read-failure-key', static function () use (&$calls): string {
            $calls++;

            return 'computed';
        });

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
            $this->writer()->rememberMetadataForever('callback-failure-key', static function () use (&$calls): never {
                $calls++;

                throw new \LogicException('Callback failed.');
            });
        } finally {
            self::assertSame(1, $calls);

            Exceptions::assertNothingReported();
        }
    }

    /**
     * Test that the store is bypassed for the retry window once it fails, so an
     * outage is reported once rather than on every call.
     *
     * @return void
     */
    public function testTheStoreIsBypassedForTheRetryWindow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        Config::set('api-toolkit.cache.retry_after', 30);

        $this->useUnreachableCacheStore();

        $writer = $this->writer();

        $writer->rememberMetadataForever('window-key', static fn (): string => 'first');

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:29'));

        self::assertSame('second', $writer->rememberMetadataForever('window-key', static fn (): string => 'second'));
        self::assertSame('third', $writer->rememberMetadata('window-key', static fn (): string => 'third', 60));
        self::assertNull($writer->readMetadata('window-key'));

        Exceptions::assertReportedCount(1);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:30'));

        $writer->rememberMetadataForever('window-key', static fn (): string => 'fourth');

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
        yield 'a non-numeric string' => ['not-a-number', MetadataCacheWriter::DEFAULT_RETRY_AFTER];
        yield 'a negative number' => [-5, MetadataCacheWriter::DEFAULT_RETRY_AFTER];
        yield 'nothing' => [null, MetadataCacheWriter::DEFAULT_RETRY_AFTER];
    }

    /**
     * Test that the configured retry window is read as whole seconds, falling
     * back to the default when it is not a non-negative number.
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
     * Test that a lifecycle flush does not end the retry window, so a
     * long-lived worker does not retry and re-report on every request.
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

        $writer->rememberMetadataForever('recovery-key', static fn (): string => 'during-outage');

        Config::set('cache.default', $default);
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:01:00'));

        self::assertSame('recovered', $writer->rememberMetadataForever('recovery-key', static fn (): string => 'recovered'));

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
