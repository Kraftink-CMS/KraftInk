<?php

namespace App\Console\Commands;

use App\Exceptions\OperationBusy;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Setup\Installation;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAdminCommand extends Command
{
    protected $signature = 'kraft:admin:create {username} {email}';

    protected $description = 'Create a new administrator using a password entered in the terminal';

    public function handle(Installation $installation, AuthPolicy $policy, OperationLock $lock): int
    {
        if (!$installation->installed()) {
            $this->error('Finish installing KraftInk before creating an administrator.');

            return self::FAILURE;
        }
        if (!$policy->allowsLocal()) {
            $this->error('Password sign-in is disabled in the site settings.');

            return self::FAILURE;
        }
        if (!$this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the password.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'username' => AccountIdentity::normalizeUsername(trim($this->argument('username'))),
            'email' => mb_strtolower(trim($this->argument('email'))),
        ], [
            'username' => AccountIdentity::usernameRules(),
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = $this->secret('Password', false);
        $credentials = Validator::make([
            'password' => $password,
            'password_confirmation' => $this->secret('Confirm password', false),
        ], ['password' => ['required', 'confirmed', $policy->password()]]);
        if ($credentials->fails()) {
            foreach ($credentials->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $created = $lock->shared(fn (): bool => DB::transaction(function () use ($validator, $password): bool {
                $role = Role::query()->where('system_key', 'admin')->lockForUpdate()->first();
                if ($role === null || !array_intersect(['*', 'admin.access'], $role->effectivePermissions())) {
                    return false;
                }

                $user = User::query()->create($validator->validated() + [
                    'password' => $password,
                    'locale' => app()->getLocale(),
                ]);
                $user->roles()->attach($role);
                Audit::record('user.created', ['id' => $user->id, 'via' => 'console']);

                return true;
            }, 3));
        } catch (UniqueConstraintViolationException) {
            $this->error('An account with that username or email already exists.');

            return self::FAILURE;
        } catch (OperationBusy) {
            $this->error('Wait for the current site operation to finish, then run this command again.');

            return self::FAILURE;
        }

        if (!$created) {
            $this->error('The built-in administrator role does not grant access to the administration panel.');

            return self::FAILURE;
        }

        $this->info('Administrator created. The site’s sign-in requirements still apply.');

        return self::SUCCESS;
    }
}
