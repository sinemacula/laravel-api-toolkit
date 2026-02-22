<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ApiQueryParserTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    public function testParserParsesTypicalApiQueryParameters(): void
    {
        $parser = new ApiQueryParser;

        $request = Request::create('/api/users', 'GET', [
            'fields'   => ['user' => 'id,name,email'],
            'counts'   => ['user' => 'posts,comments'],
            'sums'     => ['user' => ['payments' => 'amount,total']],
            'averages' => ['user' => ['reviews' => 'score']],
            'filters'  => '{"active":true}',
            'order'    => 'name:desc,id:asc',
            'page'     => '2',
            'limit'    => '25',
            'cursor'   => 'abc123',
        ]);

        $parser->parse($request);

        static::assertSame(['id', 'name', 'email'], $parser->getFields('user'));
        static::assertSame(['posts', 'comments'], $parser->getCounts('user'));
        static::assertSame(['amount', 'total'], $parser->getSums('user')['payments']);
        static::assertSame(['score'], $parser->getAverages('user')['reviews']);
        static::assertSame(['active' => true], $parser->getFilters());
        static::assertSame(['name' => 'desc', 'id' => 'asc'], $parser->getOrder());
        static::assertSame(2, $parser->getPage());
        static::assertSame(25, $parser->getLimit());
        static::assertSame('abc123', $parser->getCursor());
    }

    public function testParserAppliesDefaultsForPageLimitCursorAndEmptyFilters(): void
    {
        $parser = new ApiQueryParser;

        $parser->parse(Request::create('/api/users', 'GET', [
            'order'   => '',
            'filters' => '[]',
        ]));

        static::assertSame(1, $parser->getPage());
        static::assertNull($parser->getLimit());
        static::assertSame([], $parser->getOrder());
        static::assertSame([], $parser->getFilters());
        static::assertSame('', $parser->getCursor());
    }

    public function testParserValidationRejectsInvalidInputShapes(): void
    {
        $parser = new ApiQueryParser;

        $this->expectException(ValidationException::class);

        $parser->parse(Request::create('/api/users', 'GET', [
            'page'   => 'invalid',
            'fields' => ['user' => ['nested-array-not-allowed']],
        ]));
    }

    public function testParserCanThrowInvalidInputWhenJsonDecodeFailsUnexpectedly(): void
    {
        $parser = new ApiQueryParser;

        FunctionOverrides::throwOnJsonDecode(true);

        $this->expectException(InvalidInputException::class);

        $parser->parse(Request::create('/api/users', 'GET', [
            'filters' => '{"active":true}',
        ]));
    }

    public function testPrivateHelpersHandleCommaSplitNormalizationAndRuleBuilding(): void
    {
        $parser = new ApiQueryParser;

        static::assertSame(['a', 'b'], $this->invokeNonPublic($parser, 'splitAndTrim', 'a, b'));

        static::assertSame([
            'user' => ['id', 'name'],
        ], $this->invokeNonPublic($parser, 'parseCommaSeparatedValues', ['user' => 'id,name']));
        static::assertSame(['id', 'name'], $this->invokeNonPublic($parser, 'parseCommaSeparatedValues', 'id,name'));

        static::assertSame(['x'], $this->invokeNonPublic($parser, 'normalizeFields', 'x'));
        static::assertSame(['x', 'y'], $this->invokeNonPublic($parser, 'normalizeFields', ['x', 'y']));
        static::assertSame([5], $this->invokeNonPublic($parser, 'normalizeFields', 5));

        $order = $this->invokeNonPublic($parser, 'parseOrder', 'name:desc,id');
        static::assertSame(['name' => 'desc', 'id' => 'asc'], $order);

        $rules = $this->invokeNonPublic($parser, 'buildValidationRulesFromParameters', [
            'fields'   => ['user' => 'id,name'],
            'counts'   => ['user' => 'posts'],
            'sums'     => ['user' => ['payments' => 'amount']],
            'averages' => ['user' => ['reviews' => 'score']],
        ]);

        static::assertSame('array', $rules['fields']);
        static::assertSame('array', $rules['counts']);
        static::assertSame('array', $rules['sums']);
        static::assertSame('array', $rules['averages']);

        $rules      = ['fields' => 'string'];
        $method     = new \ReflectionMethod(ApiQueryParser::class, 'applyArrayValidationRules');
        $parameters = [&$rules, ['fields' => 'id,name'], 'fields', ['fields.*' => 'string']];

        $method->setAccessible(true);
        $method->invokeArgs($parser, $parameters);

        static::assertSame(['fields' => 'string'], $rules);
    }

    public function testParserHandlesAggregationParsingWithNonArrayRelationsGracefully(): void
    {
        $parser = new ApiQueryParser;

        $aggregations = $this->invokeNonPublic($parser, 'parseAggregations', [
            'user'    => ['posts' => 'title,total'],
            'invalid' => 'not-an-array',
        ]);

        static::assertSame(['title', 'total'], $aggregations['user']['posts']);
        static::assertArrayNotHasKey('invalid', $aggregations);
    }
}
