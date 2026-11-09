<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Migration\ImportManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BlockImportWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            !$request->isMethodSafe() &&
            !$request->is('admin/imports', 'admin/imports/*') &&
            app(ImportManager::class)->isRunning()
        ) {
            throw new HttpException(503, __('system.imports.write_locked'));
        }

        return $next($request);
    }
}
