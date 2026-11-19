<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Walks a paginated collection by the links each page gives, as a client does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait WalksPaginationLinks
{
    /**
     * Follow every next link from the first page, then every prev link back,
     * and return the field's values on each page.
     *
     * Both walks must cross the same pages, and every link must carry the kept
     * parameters unchanged.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $first
     * @param  string  $field
     * @param  array<string, mixed>  $kept
     * @return array<int, array<int, mixed>>
     */
    protected function walk(TestResponse $first, string $field, array $kept = []): array
    {
        $first->assertOk();

        [$forward, $end] = $this->followLinks($first, 'next', $field, $kept);
        [$back]          = $this->followLinks($end, 'prev', $field, $kept);

        static::assertSame($forward, array_reverse($back));

        return $forward;
    }

    /**
     * Follow the given link from page to page until it runs out, returning the
     * field's values per page and the page the walk ended on.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $page
     * @param  'next'|'prev'  $link
     * @param  string  $field
     * @param  array<string, mixed>  $kept
     * @return array{array<int, array<int, mixed>>, \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>}
     */
    private function followLinks(TestResponse $page, string $link, string $field, array $kept): array
    {
        $pages = [array_column((array) $page->json('data'), $field)];

        while (is_string($url = $page->json('links.' . $link))) {

            static::assertLessThan(10, count($pages), 'The walk did not end.');

            $query = (string) parse_url($url, PHP_URL_QUERY);

            parse_str($query, $parameters);

            foreach ($kept as $name => $value) {
                static::assertSame($value, $parameters[$name] ?? null, sprintf('The %s link dropped "%s".', $link, $name));
            }

            $page = $this->getJson((string) parse_url($url, PHP_URL_PATH) . '?' . $query);

            $page->assertOk();

            $pages[] = array_column((array) $page->json('data'), $field);
        }

        return [$pages, $page];
    }
}
