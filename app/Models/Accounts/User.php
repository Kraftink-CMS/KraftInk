<?php

namespace App\Models\Accounts;

use Database\Factories\UserFactory;
use App\Models\Audit\AuditEntry;
use App\Models\Content\ContentComment;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentReaction;
use App\Models\Imports\ImportCredential;
use App\Models\Media\MediaAsset;
use App\Models\Notifications\CommunityNoticeRead;
use App\Models\Wallet\WalletAccount;
use App\Models\Servers\LinkAccountOperation;
use App\Models\Servers\LinkAccountToken;
use App\Models\Notifications\NotificationDelivery;
use App\Models\Notifications\NotificationPreference;
use App\Events\ResolvingUserRoles;
use App\Kraft\Auth\PermissionAccess;
use App\Kraft\LocalePolicy;
use App\Models\OAuth\Discord\RoleConnection;
use App\Models\OAuth\Discord\RoleRevocation;
use App\Models\OAuth\OAuthAccount;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * @mixin Builder<static>
 *
 * @method static Builder<static> members()
 * @method static Builder<static> services()
 *
 * @property int $id
 * @property string $username
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $local_login_enabled
 * @property bool $registration_pending
 * @property bool $force_password_reset
 * @property bool $import_locked
 * @property int $auth_session_version
 * @property Carbon|null $oauth_recovery_required_at
 * @property string|null $two_factor_secret
 * @property array|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property string|null $remember_token
 * @property bool $banned_permanently
 * @property Carbon|null $banned_until
 * @property string $locale
 * @property string|null $appearance
 * @property string|null $avatar_path
 * @property string|null $last_login_ip
 * @property Carbon|null $last_login_at
 * @property Carbon|null $anonymized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $account_type
 * @property int|null $service_created_by
 * @property Carbon|null $service_disabled_at
 * @property array|null $service_scopes
 * @property string|null $service_description
 * @property-read Collection<int, Role> $roles
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    protected $table = 'users';

    use HasFactory, Notifiable;

    protected $fillable = ['username', 'email', 'password', 'locale'];

    protected $hidden = [
        'username_key',
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'avatar_path',
        'last_login_ip',
        'service_scopes',
        'service_description',
        'service_created_by',
        'service_disabled_at',
    ];

    protected $attributes = ['local_login_enabled' => true, 'account_type' => 'human'];

    private ?array $apiPermissionScopes = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'banned_until' => 'datetime',
            'banned_permanently' => 'boolean',
            'last_login_at' => 'datetime',
            'oauth_recovery_required_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'force_password_reset' => 'boolean',
            'local_login_enabled' => 'boolean',
            'registration_pending' => 'boolean',
            'import_locked' => 'boolean',
            'anonymized_at' => 'datetime',
            'auth_session_version' => 'integer',
            'service_scopes' => 'array',
            'service_disabled_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->using(RoleUser::class);
    }

    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OAuthAccount::class);
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    public function hasLocalPassword(): bool
    {
        return $this->local_login_enabled && filled($this->password);
    }

    public function gameIdentities(): HasMany
    {
        return $this->hasMany(GameIdentity::class);
    }

    public function apiAccessTokens(): HasMany
    {
        return $this->hasMany(ApiAccessToken::class);
    }

    public function bans(): HasMany
    {
        return $this->hasMany(UserBan::class);
    }

    public function discordRoleConnections(): HasMany
    {
        return $this->hasMany(RoleConnection::class);
    }

    public function discordRoleRevocations(): HasMany
    {
        return $this->hasMany(RoleRevocation::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function linkAccountTokens(): HasMany
    {
        return $this->hasMany(LinkAccountToken::class, 'actor_id');
    }

    public function linkAccountOperations(): HasMany
    {
        return $this->hasMany(LinkAccountOperation::class);
    }

    public function contentEntries(): HasMany
    {
        return $this->hasMany(ContentEntry::class, 'author_id');
    }

    public function contentComments(): HasMany
    {
        return $this->hasMany(ContentComment::class);
    }

    public function contentReactions(): HasMany
    {
        return $this->hasMany(ContentReaction::class);
    }

    public function communityNoticeReads(): HasMany
    {
        return $this->hasMany(CommunityNoticeRead::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'owner_id');
    }

    public function importCredential(): HasOne
    {
        return $this->hasOne(ImportCredential::class);
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class);
    }

    public function isServiceAccount(): bool
    {
        return $this->account_type === 'service';
    }

    public function canAuthenticateAsMember(): bool
    {
        return !$this->isServiceAccount() && !$this->import_locked && !$this->anonymized_at && !$this->isBanned();
    }

    public function scopeMembers(Builder $query): void
    {
        $query->where($query->getModel()->qualifyColumn('account_type'), 'human');
    }

    public function scopeServices(Builder $query): void
    {
        $query->where($query->getModel()->qualifyColumn('account_type'), 'service');
    }

    public function restrictApiPermissions(array $scopes): void
    {
        $this->apiPermissionScopes = $scopes;
    }

    public function apiPermissionScopes(): ?array
    {
        return $this->apiPermissionScopes;
    }

    public function preferredLocale(): string
    {
        return app(LocalePolicy::class)->forUser($this);
    }

    public function sendEmailVerificationNotification(): void
    {
        if ($this->email) {
            parent::sendEmailVerificationNotification();
        }
    }

    public function walletAccount(): HasOne
    {
        return $this->hasOne(WalletAccount::class);
    }

    public function hasPermission(string $permission): bool
    {
        return app(PermissionAccess::class)->allows($this, $permission);
    }

    public function effectivePermissions(): array
    {
        return app(PermissionAccess::class)->permissions($this);
    }

    public function isBanned(): bool
    {
        return $this->banned_permanently || $this->banned_until?->isFuture();
    }

    public function scopeWithoutActiveBan(Builder $query): void
    {
        $until = $query->getModel()->qualifyColumn('banned_until');
        $query
            ->where($query->getModel()->qualifyColumn('banned_permanently'), false)
            ->where(fn (Builder $query) => $query->whereNull($until)->orWhere($until, '<=', now()));
    }

    /** @return Collection<int, Role> */
    public function effectiveRoles(): Collection
    {
        if ($this->isServiceAccount()) {
            return collect();
        }
        $roles = $this->roles;
        $event = new ResolvingUserRoles($this, $roles);
        Event::dispatch($event);

        return $event->roles->unique('id')->sortByDesc('position')->values();
    }
}
