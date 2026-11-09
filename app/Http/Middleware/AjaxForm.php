<?php

namespace App\Http\Middleware;

use App\Exceptions\ConfirmationRequired;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use stdClass;

class AjaxForm
{
    private const array FLASH_KEYS = ['status', 'success', 'error', 'warning', 'errors', '_old_input'];

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::applies($request)) {
            return self::envelope($request, $next($request));
        }
        $session = $request->hasSession() ? $request->session() : null;
        $previous = array_count_values($session?->get('_flash.new', []) ?? []);
        $response = $next($request);
        if (!($response instanceof RedirectResponse) || !$session) {
            return self::envelope($request, $response);
        }
        $current = array_count_values($session->get('_flash.new', []));
        $flashed = array_values(
            array_filter(self::FLASH_KEYS, fn (string $key) => ($current[$key] ?? 0) > ($previous[$key] ?? 0)),
        );
        $errors = [];
        $bag = in_array('errors', $flashed, true) ? $session->get('errors') : null;
        if ($bag instanceof ViewErrorBag) {
            foreach ($bag->getBags() as $messages) {
                $errors = array_merge_recursive($errors, $messages->messages());
            }
        }
        $message = null;
        $type = null;
        foreach (['error', 'warning', 'status', 'success'] as $key) {
            $value = $session->get($key);
            if (
                in_array($key, $flashed, true) &&
                (is_string($value) ||
                    (is_array($value) && array_is_list($value) && array_all($value, fn ($entry) => is_string($entry))))
            ) {
                $message = $value;
                $type = $key;
                break;
            }
        }
        $failed = $errors !== [] || in_array('error', $flashed, true);
        $data = [
            'message' => $message ?? ($errors !== [] ? reset($errors)[0] : __('common.forms.saved')),
            'redirect_url' => $response->getTargetUrl(),
        ];
        $destination = parse_url($response->getTargetUrl());
        if (
            !$failed &&
            $request->routeIs('account.connections.store', 'account.discord.roles.start') &&
            $request->isMethod('post') &&
            ConfirmationRequired::applies($request) &&
            $request->attributes->get('kraft.confirmation.confirmed') &&
            ($destination['scheme'] ?? null) === 'https' &&
            !empty($destination['host']) &&
            !isset($destination['user']) &&
            !isset($destination['pass'])
        ) {
            $data['redirect_external'] = true;
        }
        if ($type === 'warning') {
            $data['type'] = 'warning';
        }
        if ($errors !== []) {
            $data['errors'] = $errors;
        }
        $consumed = $flashed;
        if (!$failed && $request->header('X-Kraft-Redirect') === 'true') {
            $consumed =
                $request->header('X-Kraft-Success-Message') === 'false'
                    ? array_values(array_intersect($flashed, ['status', 'success']))
                    : [];
        }
        if ($consumed !== []) {
            $session->forget($consumed);
            $session->put('_flash.new', array_values(array_diff($session->get('_flash.new', []), $consumed)));
        }
        $headers = $response->headers->all();
        unset($headers['location'], $headers['content-type'], $headers['content-length']);

        return self::envelope($request, response()->json($data, $failed ? 422 : 200, $headers));
    }

    public static function envelope(Request $request, Response $response, bool $exception = false): Response
    {
        if (ConfirmationRequired::applies($request) && $request->attributes->get('kraft.confirmation.confirmed')) {
            $response->headers->set('X-Kraft-Confirmed', 'true');
        }
        if (!self::applies($request) && !($exception && ($request->is('api/*') || $request->expectsJson()))) {
            return $response;
        }
        if ($response->getStatusCode() === 204 && self::applies($request)) {
            $headers = $response->headers->all();
            unset($headers['content-type'], $headers['content-length']);
            $response = response()->json(new stdClass(), 200, $headers);
        }
        if (!($response instanceof JsonResponse)) {
            return $response;
        }
        $data = json_decode($response->getContent());
        if ($exception && $response->getStatusCode() >= 500) {
            $data = ['message' => __('common.forms.error')];
        } elseif (is_object($data) && is_bool($data->success ?? null) && property_exists($data, 'data')) {
            return $response;
        } elseif ($exception && !$response->isSuccessful()) {
            $data = array_filter(
                [
                    'message' => $data->message ?? __('common.forms.error'),
                    'errors' => $data->errors ?? null,
                ],
                fn ($value) => $value !== null,
            );
        }
        $response->setData(['success' => $response->isSuccessful(), 'data' => $data ?? new stdClass()]);
        $response->headers->remove('Content-Length');

        return $response;
    }

    private static function applies(Request $request): bool
    {
        return $request->header('X-Kraft-Form') === 'true' && $request->expectsJson() && !$request->isMethodSafe();
    }
}
