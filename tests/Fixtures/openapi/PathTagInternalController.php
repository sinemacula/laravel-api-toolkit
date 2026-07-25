<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\NotDocumentedIn;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture authorized controller for the tag resource, excluded from public.
 *
 * The tag resource references no other resource, so it is a reachable leaf, and
 * the class-level exclusion keeps it out of the public audience while a
 * blocklist audience such as internal still documents it. The exporter tests
 * use it to prove that scoping a route out of an audience also drops its schema
 * from that audience's document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[NotDocumentedIn('public')]
final class PathTagInternalController extends AuthorizedController
{
    /** @var string */
    public const string RESOURCE_MODEL = Tag::class;

    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Show a single resource.
     *
     * @return void
     */
    public function show(): void {}
}
