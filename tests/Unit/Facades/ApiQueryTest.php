<?php

declare(strict_types = 1);

namespace Tests\Unit\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ApiQuery facade.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQuery::class)]
final class ApiQueryTest extends TestCase
{
    /**
     * Test that the facade accessor returns the config-based alias.
     *
     * @return void
     */
    public function testFacadeAccessorReturnsConfigAlias(): void
    {
        $reflection = new \ReflectionMethod(ApiQuery::class, 'getFacadeAccessor');
        $accessor   = $reflection->invoke(null);

        self::assertSame('api.query', $accessor);
    }

    /**
     * Test that the facade accessor returns a custom configured alias.
     *
     * @return void
     */
    public function testFacadeAccessorReturnsCustomConfiguredAlias(): void
    {
        Config::set('api-toolkit.parser.alias', 'custom.alias');

        $reflection = new \ReflectionMethod(ApiQuery::class, 'getFacadeAccessor');
        $accessor   = $reflection->invoke(null);

        self::assertSame('custom.alias', $accessor);
    }

    /**
     * Test that the facade accessor fails when the configured alias is not a
     * string, rather than resolving another binding.
     *
     * @return void
     */
    public function testFacadeAccessorFailsWhenAliasIsNotString(): void
    {
        Config::set('api-toolkit.parser.alias', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.parser.alias setting must be a non-empty string; [null] given.');

        (new \ReflectionMethod(ApiQuery::class, 'getFacadeAccessor'))->invoke(null);
    }

    /**
     * Test that the documented methods are exactly the parser's public methods,
     * each with the parser's parameter count.
     *
     * @return void
     */
    public function testDocumentedMethodsMatchThePublicParserMethods(): void
    {
        $docComment = (string) (new \ReflectionClass(ApiQuery::class))->getDocComment();

        preg_match_all('/@method static .*?(\w+)\((.*)\)$/m', $docComment, $tags, PREG_SET_ORDER);

        $documented = [];

        foreach ($tags as [, $name, $parameters]) {
            $documented[$name] = substr_count($parameters, '$');
        }

        $public = [];

        foreach ((new \ReflectionClass(ApiQueryParser::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }

            $public[$method->getName()] = $method->getNumberOfParameters();
        }

        ksort($documented);
        ksort($public);

        self::assertSame($public, $documented);
    }

    /**
     * Test that the facade parses a request through the bound parser and serves
     * what it parsed.
     *
     * @return void
     */
    public function testFacadeParsesTheRequestAndServesItsFields(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class]);

        ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), ['fields' => ['users' => 'id,name']]));

        self::assertTrue(ApiQuery::isParsed());
        self::assertSame(['id', 'name'], ApiQuery::getFields('users'));
    }
}
