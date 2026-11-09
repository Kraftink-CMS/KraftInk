<?php

use App\Http\Middleware\KraftContext;
use App\Http\Middleware\Permission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\AjaxForm;
use App\Http\Middleware\BlockImportWrites;
use App\Http\Middleware\MaintenanceBypass;
use App\Http\Middleware\OperationGuard;
use App\Http\Middleware\PrepareInstallation;
use App\Http\Middleware\RequireAdminTwoFactor;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\ResolveRedirects;
use App\Http\Middleware\SessionVersion;
use App\Http\Middleware\SystemContext;
use App\Http\Middleware\TrustProxies;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/admin.php'],
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [OperationGuard::class]);
        $middleware->api(prepend: [OperationGuard::class]);
        $middleware->trimStrings(
            except: ['*password*', '*secret*', '*token*', 'api_key', '*.api_key', 'site_key', 'credentials.*'],
        );
        $middleware->web(
            append: [
                PrepareInstallation::class,
                AjaxForm::class,
                AuthenticateSession::class,
                KraftContext::class,
                SessionVersion::class,
                SystemContext::class,
                RequireAdminTwoFactor::class,
                ResolveRedirects::class,
            ],
        );
        $middleware->api(append: [AjaxForm::class, SystemContext::class]);
        $middleware->appendToPriorityList(
            ShareErrorsFromSession::class,
            PrepareInstallation::class,
        );
        $middleware->appendToPriorityList(
            PrepareInstallation::class,
            AjaxForm::class,
        );
        $middleware->append(TrustProxies::class);
        $middleware->append(PrepareInstallation::class);
        $middleware->append(BlockImportWrites::class);
        $middleware->alias([
            'permission' => Permission::class,
            'capability' => RequireCapability::class,
            'maintenance.bypass' => MaintenanceBypass::class,
            'operation' => OperationGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'new_password',
            'new_password_confirmation',
            'current_password',
            'mail_password',
            'db_password',
            'source_password',
            'transport_secret',
            'client_secret',
            'providers',
            'preview_token',
            'token',
            'webhook_url',
            'webhook_secret',
            'captcha_secret',
            'captcha_response',
            'g-recaptcha-response',
            'h-captcha-response',
            'cf-turnstile-response',
            'gateways',
            'credentials',
            'callback_secret',
            'api_key',
            'site_key',
            'api_token',
            'code',
            'otp',
            'bot_token',
        ]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(
            fn (
                Response $response,
                Throwable $exception,
                Request $request,
            ) => AjaxForm::envelope($request, $response, true),
        );
    })
    ->create();

if ($storage = getenv('LARAVEL_STORAGE_PATH')) {
    $app->useStoragePath($storage);
}

return $app;
