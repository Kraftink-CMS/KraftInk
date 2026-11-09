<?php

namespace App\Http\Controllers\Admin\Settings;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kraft\System\TableQuery;
use App\Models\Setting;
use App\Models\OAuth\OAuthAccount;
use App\Http\Controllers\Controller;
use App\Jobs\SendOAuthRecovery;
use App\Kraft\Audit;
use App\Kraft\Auth\ApiTokens;
use App\Kraft\OAuth\OAuthChanges;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

class OAuthSettingsController extends Controller
{
    public function index(OAuthRegistry $registry, Settings $settings): View
    {
        return view('admin.settings.oauth.index', [
            'providers' => TableQuery::paginate(
                collect($registry->all())->map(fn ($id) => ['id' => $id, 'name' => $registry->metadata($id)['name']]),
                ['name'],
                pageName: 'providers_page',
                search: ['name', 'id'],
            ),
            'adapters' => $registry->adapters(),
            'settings' => $settings->all(),
            'registry' => $registry,
            'linkedCounts' => OAuthAccount::query()
                ->selectRaw('provider, COUNT(*) as total')
                ->groupBy('provider')
                ->pluck('total', 'provider'),
            'recoveryCount' => User::members()
                ->whereNotNull('oauth_recovery_required_at')
                ->whereNull('anonymized_at')
                ->count(),
        ]);
    }

    public function store(
        Request $request,
        OAuthRegistry $registry,
        Settings $settings,
        Secrets $secrets,
    ): RedirectResponse|View|JsonResponse {
        $data = $request->validate([
            'providers' => 'prohibited',
            'provider' => ['required', Rule::in($registry->all())],
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|max:2000',
            'enabled' => 'required|boolean',
        ]);
        $data['client_id'] = trim((string) ($data['client_id'] ?? ''));
        $id = $data['provider'];
        if ($data['enabled'] && !$registry->callbackAllowed($id)) {
            throw ValidationException::withMessages(['enabled' => __('admin.integrations.oauth.credentials.https_required')]);
        }
        if ($settings->get('oauth.'.$id.'.enabled', false) && !$request->boolean('enabled')) {
            return $this->review($request, ['operation' => 'provider', 'provider' => $id]);
        }
        app(SensitiveAction::class)->confirm($request);
        $fields = [];
        foreach ($registry->metadata($id)['fields'] ?? [] as $key => $field) {
            if (!$request->has($key)) {
                $request->merge([$key => $settings->get('oauth.'.$id.'.'.$key, $field['default'] ?? null)]);
            }
            $fields[$key] = $field['rules'] ?? ['nullable', 'string', 'max:255'];
        }
        $extra = $request->validate($fields);
        if (
            $request->boolean('enabled') &&
            ((($registry->metadata($id)['credentials']['client_id'] ?? true) && $data['client_id'] === '') ||
                (($registry->metadata($id)['credentials']['client_secret'] ?? true) &&
                    empty($data['client_secret']) &&
                    $secrets->get('oauth.'.$id.'.client_secret') === ''))
        ) {
            throw ValidationException::withMessages([
                'client_secret' => __('admin.integrations.oauth.credentials.missing'),
            ]);
        }
        $previousId = (string) $settings->get('oauth.'.$id.'.client_id', '');
        if (
            $previousId !== '' &&
            $previousId !== $data['client_id'] &&
            OAuthAccount::query()->where('provider', $id)->exists()
        ) {
            throw ValidationException::withMessages(['client_id' => __('admin.integrations.oauth.application_change')]);
        }
        if (
            in_array($id, $registry->enabled(), true) &&
            ($previousId !== $data['client_id'] ||
                !empty($data['client_secret']) ||
                collect($extra)->contains(
                    fn ($value, $key) => $value !==
                        $settings->get(
                            'oauth.'.$id.'.'.$key,
                            $registry->metadata($id)['fields'][$key]['default'] ?? null,
                        ),
                ))
        ) {
            app(OAuthChanges::class)->assertCredentialChange($request->user(), $id);
        }
        DB::transaction(function () use ($settings, $secrets, $id, $data, $extra, $request) {
            Setting::query()->firstOrCreate(['key' => 'oauth.revision'], ['value' => 0]);
            $revision = (int) Setting::query()->where('key', 'oauth.revision')->lockForUpdate()->value('value');
            $settings->set('oauth.'.$id.'.client_id', trim($data['client_id']));
            $settings->set('oauth.'.$id.'.enabled', $request->boolean('enabled'));
            if (!empty($data['client_secret'])) {
                $secrets->set('oauth.'.$id.'.client_secret', $data['client_secret']);
            }
            foreach ($extra as $key => $value) {
                $settings->set('oauth.'.$id.'.'.$key, $value);
            }
            $settings->set('oauth.revision', $revision + 1);
            Audit::record('oauth.provider_configured', [
                'provider' => $id,
                'enabled' => $request->boolean('enabled'),
            ]);
        }, 3);

        return redirect()->route('admin.settings.oauth.index')->with('status', __('admin.integrations.oauth.saved'));
    }

    /**
     * @throws JsonException
     */
    public function preview(Request $request, OAuthRegistry $registry): View|JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', Rule::in(['provider', 'global'])],
            'provider' => ['required_if:operation,provider', 'nullable', Rule::in($registry->all())],
            'enabled' => 'required_if:operation,global|nullable|boolean',
        ]);

        return $this->review($request, $data);
    }

    public function reviewPage(Request $request, string $id): Response
    {
        $intent = $request->session()->pull('oauth.review.'.$id);
        if (
            !is_array($intent) ||
            ($intent['actor'] ?? null) !== $request->user()->id ||
            !is_string($intent['stamp'] ?? null) ||
            !hash_equals(app(ApiTokens::class)->stamp($request->user()), $intent['stamp']) ||
            !is_int($intent['at'] ?? null) ||
            $intent['at'] < time() - 600 ||
            $intent['at'] > time() + 30 ||
            !is_array($intent['proposal'] ?? null)
        ) {
            throw new NotFoundHttpException();
        }

        return response($this->review($request, $intent['proposal']))->header('Cache-Control', 'no-store, private');
    }

    private function review(Request $request, array $proposal): View|JsonResponse
    {
        $changes = app(OAuthChanges::class);
        $plan = $changes->plan($proposal);
        if (!$request->isMethodSafe() && $request->expectsJson() && $request->header('X-Kraft-Form') === 'true') {
            $id = (string) Str::uuid();
            $request->session()->flash('oauth.review.'.$id, [
                'actor' => $request->user()->id,
                'stamp' => app(ApiTokens::class)->stamp($request->user()),
                'at' => time(),
                'proposal' => $proposal,
            ]);

            return response()->json([
                'success' => true,
                'data' => ['redirect_url' => route('admin.settings.oauth.review', $id), 'message' => ''],
            ]);
        }

        return view('admin.settings.oauth.preview', [
            'plan' => $plan,
            'token' => $changes->token($plan, $request->user()),
            'registry' => app(OAuthRegistry::class),
        ]);
    }

    public function apply(Request $request, OAuthChanges $changes): RedirectResponse
    {
        $data = $request->validate([
            'preview_token' => 'required|string|max:20000',
            'confirmed' => 'accepted',
        ]);
        app(SensitiveAction::class)->confirm($request);
        $plan = $changes->apply($data['preview_token'], $request->user());
        if (in_array($request->user()->id, $plan['revoked'], true)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('admin.integrations.oauth.changed_sign_in'));
        }

        return redirect()->route('admin.settings.oauth.index')->with('status', __('admin.integrations.oauth.saved'));
    }

    public function add(Request $request, OAuthRegistry $registry, Settings $settings): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        if (!$request->filled('id')) {
            $id = substr(Str::slug((string) $request->input('name')), 0, 40);
            if (!preg_match('/^[a-z]/', $id)) {
                $id = 'connection-'.$id;
            }
            if (in_array($id, $registry->all(), true)) {
                $id .= '-'.Str::lower(Str::random(5));
            }
            $request->merge(['id' => $id]);
        }
        $data = $request->validate([
            'id' => 'required|regex:/^[a-z][a-z0-9-]{1,49}$/D',
            'name' => 'required|string|max:80',
            'adapter' => ['required', Rule::in($registry->adapters())],
        ]);
        $retired = $settings->get('oauth.retired', []);
        if (
            in_array($data['id'], $registry->all(), true) ||
            (isset($retired[$data['id']]) && $retired[$data['id']] !== $data['adapter'])
        ) {
            throw ValidationException::withMessages(['id' => __('admin.integrations.oauth.retired')]);
        }
        DB::transaction(function () use ($settings, $data) {
            Setting::query()->firstOrCreate(['key' => 'oauth.revision'], ['value' => 0]);
            $revision = (int) Setting::query()->where('key', 'oauth.revision')->lockForUpdate()->value('value');
            $providers = Setting::query()->where('key', 'oauth.providers')->value('value') ?? [];
            if (isset($providers[$data['id']]) || isset(config('oauth.providers')[$data['id']])) {
                throw ValidationException::withMessages(['id' => __('admin.integrations.oauth.retired')]);
            }
            $providers[$data['id']] = ['name' => $data['name'], 'adapter' => $data['adapter']];
            $settings->set('oauth.providers', $providers);
            $settings->set('oauth.revision', $revision + 1);
            Audit::record('oauth.provider_added', ['provider' => $data['id'], 'adapter' => $data['adapter']]);
        }, 3);

        return redirect()->route('admin.settings.oauth.index')->with('status', __('admin.integrations.oauth.saved'));
    }

    public function retry(Request $request): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $ids = User::members()
            ->whereNotNull('oauth_recovery_required_at')
            ->whereNull('anonymized_at')
            ->where('import_locked', false)
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->all();
        SendOAuthRecovery::requestMany($ids);

        return back()->with('status', __('admin.integrations.oauth.recovery_retried'));
    }
}
