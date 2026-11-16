<?php

declare(strict_types = 1);

namespace Tests\Unit\Validation;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Validation\Rejection;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\TestCase;

/**
 * Tests for the toolkit's localised validation messages.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Rejection::class)]
final class RejectionTest extends TestCase
{
    use LocalisesToolkitMessages;

    /**
     * Test that the English line is returned with its replacements.
     *
     * @return void
     */
    public function testMessageReturnsTheEnglishLineWithItsReplacements(): void
    {
        self::assertSame(
            'The "$gt" operator is not permitted on the "name" key for this resource, which accepts $eq, $in.',
            Rejection::message('filters.unpermitted_operator', ['operator' => '$gt', 'key' => 'name', 'accepts' => '$eq, $in']),
        );
    }

    /**
     * Test that the message follows the current locale.
     *
     * @return void
     */
    public function testMessageFollowsTheCurrentLocale(): void
    {
        $this->localiseToolkitMessages(['query.undeclared_key' => 'Der Schlüssel ":key" ist kein zulässiger Abfrageparameter.']);

        self::assertSame('Der Schlüssel "status" ist kein zulässiger Abfrageparameter.', Rejection::message('query.undeclared_key', ['key' => 'status']));
    }

    /**
     * Test that a locale without the line falls back to English.
     *
     * @return void
     */
    public function testMessageFallsBackToEnglishForALocaleWithoutTheLine(): void
    {
        App::setLocale('fr');

        self::assertSame('The search parameter is not permitted for this resource.', Rejection::message('search.not_permitted'));
    }

    /**
     * Test that an override turning the line into an array yields the key.
     *
     * @return void
     */
    public function testMessageReturnsTheKeyForAnArrayLeaf(): void
    {
        Lang::addLines(['validation.search.not_permitted' => ['nested' => 'Nicht erlaubt.']], 'de', 'api-toolkit');
        App::setLocale('de');

        self::assertSame('api-toolkit::validation.search.not_permitted', Rejection::message('search.not_permitted'));
    }

    /**
     * Test that the exception carries the position and the whole message.
     *
     * @return void
     */
    public function testAtCarriesThePositionAndTheWholeMessage(): void
    {
        $exception = Rejection::at('filters.status', 'query.undeclared_key', ['key' => 'status']);

        self::assertSame(
            ['filters.status' => ['The "status" key is not a permitted query parameter for this resource.']],
            $exception->errors(),
        );
    }
}
