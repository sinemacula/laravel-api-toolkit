<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RowGuardProbe;
use Tests\TestCase;

/**
 * Tests for the RowGuardProbe stand-in resource.
 *
 * Every modelled access - property read, isset check, method call, string
 * coercion, and iteration - must flip the internal touched flag and return a
 * value that lets a chained guard keep running.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RowGuardProbe::class)]
final class RowGuardProbeTest extends TestCase
{
    /**
     * Test that a fresh probe reports no interaction.
     *
     * @return void
     */
    public function testUntouchedProbeReportsNoInteraction(): void
    {
        self::assertFalse((new RowGuardProbe)->wasTouched());
    }

    /**
     * Test that a property read records a touch and returns the probe so a
     * chained read keeps running.
     *
     * @return void
     */
    public function testPropertyReadRecordsTouchAndReturnsProbe(): void
    {
        $probe = new RowGuardProbe;

        self::assertSame($probe, $probe->__get('name'));
        self::assertTrue($probe->wasTouched());
    }

    /**
     * Test that an isset check records a touch and reports it present.
     *
     * @return void
     */
    public function testIssetCheckRecordsTouchAndReportsPresent(): void
    {
        $probe = new RowGuardProbe;

        self::assertTrue($probe->__isset('name'));
        self::assertTrue($probe->wasTouched());
    }

    /**
     * Test that a method call records a touch and returns the probe.
     *
     * @return void
     */
    public function testMethodCallRecordsTouchAndReturnsProbe(): void
    {
        $probe = new RowGuardProbe;

        self::assertSame($probe, $probe->__call('someMethod', ['argument']));
        self::assertTrue($probe->wasTouched());
    }

    /**
     * Test that a string coercion records a touch and yields an empty string.
     *
     * @return void
     */
    public function testStringCoercionRecordsTouchAndReturnsEmptyString(): void
    {
        $probe = new RowGuardProbe;

        self::assertSame('', (string) $probe);
        self::assertTrue($probe->wasTouched());
    }

    /**
     * Test that iterating the probe records a touch and yields no items.
     *
     * @return void
     */
    public function testIterationRecordsTouchAndYieldsNoItems(): void
    {
        $probe = new RowGuardProbe;

        self::assertSame([], iterator_to_array($probe));
        self::assertTrue($probe->wasTouched());
    }
}
