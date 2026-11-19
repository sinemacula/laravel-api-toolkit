<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm;

/**
 * Tests for the route path form.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RoutePathForm::class)]
final class RoutePathFormTest extends TestCase
{
    /**
     * Test that the form exposes the path, its parameters, the optional ones it
     * includes and whether it is the full shape.
     *
     * @return void
     */
    public function testExposesThePathItsParametersAndWhetherItIsFull(): void
    {
        $form = new RoutePathForm('/users/{user}/posts/{post}', ['user', 'post'], ['post'], true);

        self::assertSame('/users/{user}/posts/{post}', $form->path);
        self::assertSame(['user', 'post'], $form->parameters);
        self::assertSame(['post'], $form->optional);
        self::assertTrue($form->full);
    }
}
