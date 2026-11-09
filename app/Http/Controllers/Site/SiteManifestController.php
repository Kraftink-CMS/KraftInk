<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Kraft\Favicon;
use Illuminate\Http\JsonResponse;

class SiteManifestController extends Controller
{
    public function __invoke(Favicon $favicon): JsonResponse
    {
        return response()->json($favicon->manifest(), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
