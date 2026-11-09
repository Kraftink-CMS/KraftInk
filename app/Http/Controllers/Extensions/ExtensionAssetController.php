<?php

namespace App\Http\Controllers\Extensions;

use App\Http\Controllers\Controller;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\ThemeEditor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExtensionAssetController extends Controller
{
    public function __invoke(
        Request $request,
        ExtensionManager $manager,
        string $id,
        string $asset,
    ): BinaryFileResponse {
        $extension = $manager->all()[$id] ?? null;
        if (!($extension && $extension['valid']) || ($extension['market_revoked'] ?? false)) {
            abort(404);
        }
        if ($extension['type'] === 'plugin') {
            if (!$extension['enabled']) {
                abort(404);
            }
        }
        $allowed = array_merge(
            $extension['assets']['styles'] ?? [],
            $extension['assets']['scripts'] ?? [],
            $extension['assets']['images'] ?? [],
            $extension['assets']['fonts'] ?? [],
            ThemeEditor::assets($extension),
        );
        $icons = function (array $entries) use (&$icons): array {
            $paths = [];
            foreach ($entries as $entry) {
                if (is_array($entry['icon'] ?? null) && isset($entry['icon']['asset'])) {
                    $paths[] = $entry['icon']['asset'];
                }
                if (isset($entry['children'])) {
                    $paths = array_merge($paths, $icons($entry['children']));
                }
            }

            return $paths;
        };
        $allowed = array_merge($allowed, $icons($extension['navigation']['admin'] ?? []));
        if (!(in_array($asset, $allowed, true) && !str_contains($asset, '..') && !str_contains($asset, '\\'))) {
            abort(404);
        }
        $root = realpath($extension['path'].'/public');
        $path = realpath($extension['path'].'/public/'.$asset);
        if (!($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path))) {
            abort(404);
        }
        $type = match (pathinfo($asset, PATHINFO_EXTENSION)) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => null,
        };
        if (!$type) {
            abort(404);
        }

        $response = new BinaryFileResponse(
            $path,
            headers: [
                'Content-Type' => $type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, no-cache',
            ],
            autoEtag: true,
            autoLastModified: false,
        );
        $response->isNotModified($request);

        return $response;
    }
}
