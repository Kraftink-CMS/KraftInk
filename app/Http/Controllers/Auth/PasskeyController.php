<?php

namespace App\Http\Controllers\Auth;

use App\Kraft\Audit;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Auth\LoginManager;
use App\Kraft\Auth\Passkeys;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\Passkey;
use App\Models\Accounts\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PasskeyController
{
    public function loginOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        return response()->json(['publicKey' => $passkeys->begin($request)])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request, Passkeys $passkeys, LoginManager $login): RedirectResponse
    {
        $options = $passkeys->options($request);
        $data = $request->validate(['credentials' => 'required|string|json|max:65536']);
        $credential = $passkeys->response($data['credentials']);
        $hash = hash('sha256', $credential->rawId);
        $ownerId = Passkey::query()->where('credential_hash', $hash)->where('rp_id', $options->rpId)->value('user_id');

        return DB::transaction(function () use ($request, $passkeys, $login, $options, $credential, $hash, $ownerId) {
            $user = $ownerId ? User::members()->whereKey($ownerId)->lockForUpdate()->first() : null;
            if (!$user || $user->import_locked || $user->anonymized_at || $user->isBanned() || $user->force_password_reset) {
                $passkeys->invalid();
            }
            $key = $user->passkeys()
                ->where('credential_hash', $hash)
                ->where('rp_id', $options->rpId)
                ->lockForUpdate()
                ->first();
            if (!$key) {
                $passkeys->invalid();
            }
            $record = $passkeys->verify($key, $credential, $options);
            $key->update(['credential' => $passkeys->serialize($record), 'last_used_at' => now()]);

            return $login->complete($request, $user, $request->boolean('remember'), true, 'passkey');
        }, 3);
    }

    public function enrollmentOptions(Request $request, Passkeys $passkeys, SensitiveAction $confirmation): JsonResponse
    {
        $user = $request->user();
        abort_unless($passkeys->available($request) && !$user->isServiceAccount() && !$user->force_password_reset, 403);
        $confirmation->confirm($request);
        $this->ensureCapacity($user);

        return response()->json(['publicKey' => $passkeys->begin($request, $user)])->header('Cache-Control', 'no-store');
    }

    public function enroll(Request $request, Passkeys $passkeys): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isServiceAccount() || $user->force_password_reset, 403);
        $options = $passkeys->options($request, $user);
        $data = $request->validate([
            'credentials' => 'required|string|json|max:65536',
            'name' => 'nullable|string|max:80',
        ]);
        $credential = $passkeys->response($data['credentials']);
        $record = $passkeys->register($credential, $options);
        try {
            DB::transaction(function () use ($user, $options, $data, $record, $passkeys): void {
                $current = User::members()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (
                    (int) $current->auth_session_version !== (int) $user->auth_session_version ||
                    $current->import_locked || $current->anonymized_at || $current->isBanned() || $current->force_password_reset
                ) {
                    $passkeys->invalid();
                }
                $this->ensureCapacity($current);
                $other = $current->passkeys()->where('rp_id', $options->rp->id)->first();
                if ($other && !hash_equals($passkeys->record($other)->userHandle, $record->userHandle)) {
                    throw ValidationException::withMessages(['credentials' => __('passkeys.expired')]);
                }
                $current->passkeys()->create([
                    'credential_hash' => hash('sha256', $record->publicKeyCredentialId),
                    'rp_id' => $options->rp->id,
                    'name' => $data['name'] ?? null,
                    'credential' => $passkeys->serialize($record),
                ]);
                Audit::record('auth.passkey_added');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['credentials' => __('passkeys.duplicate')]);
        }

        return redirect()->route('account.index')->with('status', __('passkeys.added'));
    }

    public function destroy(
        Request $request,
        string $key,
        SensitiveAction $confirmation,
        Passkeys $passkeys,
        InGameSignInPolicy $inGameSignIn,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($passkeys->enabled() && !$user->isServiceAccount(), 403);
        $confirmation->confirm($request);
        $inGameSignIn->withStableConfiguration(function () use ($user, $key, $passkeys): void {
            abort_unless($passkeys->enabled(), 403);
            DB::transaction(function () use ($user, $key, $passkeys): void {
                $current = User::members()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $passkey = $current->passkeys()->whereKey($key)->lockForUpdate()->firstOrFail();
                if (
                    $passkey->rp_id === Passkeys::host() &&
                    !$passkeys->hasAlternateSignIn($current) &&
                    !$current->passkeys()->whereKeyNot($passkey->id)->where('rp_id', $passkey->rp_id)->exists()
                ) {
                    throw ValidationException::withMessages(['credentials' => __('passkeys.last')]);
                }
                $passkey->delete();
                Audit::record('auth.passkey_removed');
            }, 3);
        });

        return redirect()->route('account.index')->with('status', __('passkeys.removed'));
    }

    private function ensureCapacity(User $user): void
    {
        if ($user->passkeys()->count() >= Passkeys::LIMIT) {
            throw ValidationException::withMessages(['credentials' => __('passkeys.limit')]);
        }
    }
}
