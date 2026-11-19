<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\ReferencedEnumCollector;
use Tests\Fixtures\Enums\SerialisedStatus;
use Tests\Fixtures\Enums\TicketPriority;
use Tests\Fixtures\Enums\UserStatus;
use Tests\TestCase;

/**
 * Tests for the collector of the enums the API references across audiences.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ReferencedEnumCollector::class)]
final class ReferencedEnumCollectorTest extends TestCase
{
    /**
     * Test that the enums every audience references are collected, each once.
     *
     * @return void
     */
    public function testCollectsTheEnumsEveryAudienceReferencesOnce(): void
    {
        Config::set('api-toolkit.openapi.audiences', ['public' => [], 'internal' => []]);

        $this->router()->get('statuses/{status}', static fn (UserStatus $status): string => $status->value)->notDocumentedIn('internal'); // @phpstan-ignore method.notFound
        $this->router()->get('priorities/{priority}', static fn (TicketPriority $priority): string => $priority->value);
        $this->router()->get('serialised/{status}', static fn (SerialisedStatus $status): string => $status->value)->notDocumentedIn('public'); // @phpstan-ignore method.notFound

        self::assertSame([UserStatus::class, TicketPriority::class, SerialisedStatus::class], $this->collector()->collect());
    }

    /**
     * Test that the default audience's enums are collected when no audience is
     * configured.
     *
     * @return void
     */
    public function testCollectsTheDefaultAudienceWhenNoneIsConfigured(): void
    {
        Config::set('api-toolkit.openapi.audiences', []);

        $this->router()->get('statuses/{status}', static fn (UserStatus $status): string => $status->value);

        self::assertSame([UserStatus::class], $this->collector()->collect());
    }

    /**
     * Resolve the collector from the container.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\ReferencedEnumCollector
     */
    private function collector(): ReferencedEnumCollector
    {
        assert($this->app !== null);

        return $this->app->make(ReferencedEnumCollector::class);
    }

    /**
     * Resolve the application router.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }
}
