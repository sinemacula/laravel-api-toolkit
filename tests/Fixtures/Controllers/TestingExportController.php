<?php

namespace Tests\Fixtures\Controllers;

use SineMacula\ApiToolkit\Http\Concerns\RespondsWithExport;
use SineMacula\ApiToolkit\Http\Concerns\RespondsWithStream;
use SineMacula\ApiToolkit\Http\RequestCapabilities;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture controller exposing the export-capable controller surface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class TestingExportController extends Controller
{
    use RespondsWithExport;
    use RespondsWithStream;

    /**
     * List users, exporting when the request negotiates an export format.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index(): Response
    {
        $users = User::query()->oldest('id')->get();

        $capabilities = RequestCapabilities::fromRequest(request());

        if ($capabilities->expectsExport()) {
            return $this->exportFromCollection(UserResource::collection($users));
        }

        return $this->respondWithCollection(UserResource::collection($users));
    }
}
