<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createAccounts();
        $this->createInfrastructure();
        $this->createContent();
        $this->createServers();
        $this->createAuditAndImports();
        $this->createWallet();
    }

    public function down(): void
    {
        foreach (
            [
                'wallet_entries',
                'wallet_transactions',
                'wallet_accounts',
                'site_redirects',
                'import_credentials',
                'import_records',
                'import_runs',
                'audit_webhook_deliveries',
                'audit_logs',
                'link_account_operations',
                'link_account_tokens',
                'server_command_attempts',
                'link_commands',
                'servers',
                'notification_deliveries',
                'notification_preferences',
                'community_notice_reads',
                'community_notices',
                'navigation_link_role',
                'navigation_links',
                'slides',
                'content_publications',
                'content_attachments',
                'content_reactions',
                'content_comment_revisions',
                'content_comments',
                'content_revisions',
                'content_entry_role',
                'content_announcements',
                'content_entry_image',
                'content_entries',
                'content_images',
                'media_assets',
                'file_deletions',
                'remote_feed_states',
                'extension_installations',
                'settings',
                'failed_jobs',
                'job_batches',
                'jobs',
                'cache_locks',
                'cache',
                'discord_role_revocations',
                'discord_role_connections',
                'user_bans',
                'api_access_tokens',
                'game_identities',
                'passkeys',
                'oauth_accounts',
                'role_user',
                'roles',
                'sessions',
                'password_reset_tokens',
                'users',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }

    private function createAccounts(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        Schema::create('users', function (Blueprint $table) use ($driver) {
            $table->id();
            $username = $table->string('username', 60);
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $username->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            }
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->boolean('local_login_enabled')->default(true);
            $table->boolean('registration_pending')->default(false);
            $table->boolean('force_password_reset')->default(false);
            $table->boolean('import_locked')->default(false);
            $table->unsignedBigInteger('auth_session_version')->default(0);
            $table->timestamp('oauth_recovery_required_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
            $table->rememberToken();
            $table->boolean('banned_permanently')->default(false);
            $table->timestamp('banned_until')->nullable();
            $table->string('locale', 12)->default('fr');
            $table->string('appearance', 16)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->foreignId('service_created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->string('account_type', 16)->default('human')->index();
            $table->json('service_scopes')->nullable();
            $table->text('service_description')->nullable();
            $table->timestamp('service_disabled_at')->nullable();
            if ($driver === 'sqlite') {
                $table->unique([new Expression('LOWER(username)')], 'users_username_unique');
            } elseif ($driver === 'pgsql') {
                $table->string('username_key', 60)->storedAs('LOWER(username)')->unique('users_username_unique');
            } else {
                $table->unique('username', 'users_username_unique');
            }
            $table->index('created_at');
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('system_key', 20)->nullable()->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->string('text_color', 7)->nullable();
            $table->string('icon', 60)->nullable();
            $table->json('translations')->nullable();
            $table->integer('position')->default(0);
            $table->string('permission_profile', 20)->nullable();
            $table->json('permissions');
            $table->json('permission_overrides')->nullable();
            $table->timestamps();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });
        Schema::create('oauth_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('subject', 190);
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
        });
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_hash', 64)->unique();
            $table->string('rp_id', 253);
            $table->string('name', 80)->nullable();
            $table->text('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('game_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 40);
            $table->string('uuid', 64);
            $table->string('username', 60);
            $table->json('metadata')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['game', 'uuid']);
            $table->unique(['user_id', 'game']);
        });
        Schema::create('api_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->string('security_stamp', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });
        Schema::create('user_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('discord_role_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('oauth_account_id')->nullable();
            $table->string('provider', 50);
            $table->string('application_id', 32);
            $table->string('subject', 32);
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->text('client_credentials');
            $table->json('fields');
            $table->string('metadata_hash', 64)->nullable();
            $table->string('last_error', 60)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consented_at');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_sync_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
            $table->unique(['application_id', 'subject']);
        });
        Schema::create('discord_role_revocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('connection_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('application_id', 32);
            $table->string('subject_hash', 64);
            $table->text('credentials')->nullable();
            $table->string('reason', 60);
            $table->string('state', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 60)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'next_attempt_at']);
        });
    }

    private function createInfrastructure(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('finished_at')->nullable();
            $table->integer('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at']);
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value')->nullable();
        });
        Schema::create('extension_installations', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('type', 20);
            $table->string('version', 64);
            $table->string('manifest_hash', 64);
            $table->json('market_provenance')->nullable();
            $table->boolean('enabled')->default(false);
            $table->uuid('retired_uuid')->nullable();
            $table->timestamps();
        });
        Schema::create('remote_feed_states', function (Blueprint $table) {
            $table->id();
            $table->string('issuer_id', 50);
            $table->string('channel', 20);
            $table->json('document');
            $table->json('auth_context');
            $table->timestamps();
            $table->unique(['issuer_id', 'channel']);
        });
        Schema::create('file_deletions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('prefix', 191);
            $table->string('path', 512);
            $table->json('references');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamps();
            $table->index(['available_at', 'id']);
        });
    }

    private function createContent(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path')->unique();
            $table->string('hash', 64)->unique();
            $table->string('original_name');
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('alt')->default('');
            $table->json('translations')->nullable();
            $table->timestamps();
        });
        Schema::create('content_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path')->unique();
            $table->string('hash', 64);
            $table->string('original_name');
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->unique(['uploader_id', 'hash']);
        });
        Schema::create('content_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignUuid('cover_image_id')->nullable()->constrained('content_images')->nullOnDelete();
            $table->string('kind');
            $table->string('locale', 12);
            $table->string('slug');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->boolean('is_pinned')->default(false)->index();
            $table->boolean('comments_enabled')->default(false);
            $table->boolean('reactions_enabled')->default(false);
            $table->boolean('comments_moderated')->default(false);
            $table->string('visibility', 20)->default('public')->index();
            $table->boolean('roles_restricted')->default(false);
            $table->string('required_permission', 100)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['kind', 'locale', 'slug']);
            $table->index(['kind', 'locale', 'published_at', 'is_pinned'], 'content_entries_publication_index');
            $table->index(['kind', 'deleted_at', 'id'], 'content_entries_trash_index');
        });
        Schema::create('content_entry_image', function (Blueprint $table) {
            $table->foreignId('content_entry_id')->constrained('content_entries')->cascadeOnDelete();
            $table->foreignUuid('content_image_id')->constrained('content_images')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->primary(['content_entry_id', 'content_image_id']);
            $table->index(['content_image_id', 'active', 'content_entry_id'], 'content_entry_image_reference_index');
        });
        Schema::create('content_announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('content_entry_id')->constrained('content_entries')->cascadeOnDelete();
            $table->uuid('configuration_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('message_id', 30)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
        Schema::create('content_entry_role', function (Blueprint $table) {
            $table->foreignId('content_entry_id')->constrained('content_entries')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['content_entry_id', 'role_id']);
        });
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['content_entry_id', 'id']);
        });
        Schema::create('content_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->string('state', 20)->default('published');
            $table->softDeletes();
            $table->timestamps();
            $table->index(['content_entry_id', 'state', 'created_at']);
            $table->index(['deleted_at', 'id'], 'content_comments_trash_index');
        });
        Schema::create('content_comment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->longText('body');
            $table->string('state', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['content_comment_id', 'id']);
        });
        Schema::create('content_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['content_entry_id', 'user_id']);
        });
        Schema::create('content_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path')->unique();
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->softDeletes();
            $table->timestamps();
            $table->index(['content_entry_id', 'deleted_at', 'id'], 'content_attachments_retention_index');
        });
        Schema::create('content_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('content_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });
        Schema::create('slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('alt', 255);
            $table->string('url', 1000)->nullable();
            $table->string('link_label', 100)->nullable();
            $table->json('translations')->nullable();
            $table->string('locale', 12)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'locale', 'position']);
        });
        Schema::create('navigation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('navigation_links')->nullOnDelete();
            $table->foreignId('content_id')->nullable()->constrained('content_entries')->nullOnDelete();
            $table->string('location', 40);
            $table->json('labels');
            $table->string('icon', 60)->nullable();
            $table->string('destination_type', 12)->default('url');
            $table->string('url', 500);
            $table->string('route_name', 160)->nullable();
            $table->string('visibility', 16)->default('all');
            $table->string('permission', 100)->nullable();
            $table->boolean('roles_restricted')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->boolean('new_tab')->default(false);
            $table->timestamps();
            $table->index(['location', 'enabled', 'position']);
        });
        Schema::create('navigation_link_role', function (Blueprint $table) {
            $table->foreignId('navigation_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['navigation_link_id', 'role_id']);
        });
        Schema::create('community_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('audience_id')->nullable();
            $table->string('title', 160);
            $table->text('body');
            $table->json('translations')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('audience', 16)->default('all');
            $table->timestamp('published_at')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['audience', 'audience_id', 'published_at']);
        });
        Schema::create('community_notice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('community_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['notice_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 120);
            $table->string('channel', 20);
            $table->boolean('enabled');
            $table->unique(['user_id', 'type', 'channel']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('notice_id')->nullable()->constrained('community_notices')->nullOnDelete();
            $table->string('owner', 50);
            $table->string('type', 120);
            $table->string('resource', 160)->nullable()->index();
            $table->string('channel', 20);
            $table->string('configuration_id', 80)->nullable();
            $table->json('data');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['owner', 'user_id']);
        });
    }

    private function createServers(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('translations')->nullable();
            $table->string('game');
            $table->string('host');
            $table->unsignedInteger('port');
            $table->boolean('show_port')->default(true);
            $table->boolean('show_players')->default(true);
            $table->string('connection');
            $table->text('secret')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->text('transport_options')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('status')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('link_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('command', 2048);
            $table->string('player_uuid', 64)->nullable();
            $table->boolean('requires_online')->default(false);
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'status', 'available_at']);
        });
        Schema::create('server_command_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('command_id', 191);
            $table->string('checksum', 64);
            $table->string('state', 20);
            $table->timestamps();
            $table->unique(['server_id', 'command_id']);
        });
        Schema::create('link_account_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->string('security_stamp', 64);
            $table->json('scopes');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'revoked_at', 'expires_at']);
        });
        Schema::create('link_account_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('token_id')->nullable()->constrained('link_account_tokens')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation_key', 120)->unique();
            $table->string('payload_hash', 64);
            $table->string('kind', 20);
            $table->json('result');
            $table->timestamp('created_at');
        });
    }

    private function createAuditAndImports(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });
        Schema::create('audit_webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('audit_id')->nullable()->unique()->constrained('audit_logs')->nullOnDelete();
            $table->uuid('configuration_id');
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 40)->nullable();
            $table->timestamp('available_at')->index();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
        Schema::create('import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->string('status')->default('prepared');
            $table->json('plan');
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('import_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->string('source_table', 100);
            $table->string('source_id', 120);
            $table->string('target_table', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('checksum', 64);
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'source_table', 'source_id'], 'import_record_identity');
            $table->index(['target_table', 'target_id', 'created_at'], 'import_record_target_activity');
            $table->foreign('run_id')->references('id')->on('import_runs')->cascadeOnDelete();
        });
        Schema::create('import_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('run_id')->index();
            $table->string('source');
            $table->longText('payload');
            $table->timestamps();
        });
        Schema::create('site_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source', 500)->unique();
            $table->string('destination', 1000);
            $table->unsignedSmallInteger('status')->default(301);
            $table->boolean('enabled')->default(true);
            $table->boolean('automatic')->default(false);
            $table->timestamps();
        });
    }

    private function createWallet(): void
    {
        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->string('currency', 3);
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation_key', 140)->unique();
            $table->string('payload_hash', 64);
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reason', 160);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->timestamp('created_at');
            $table->unique(['transaction_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });
        DB::table('settings')->insert(['key' => 'wallet.currency', 'value' => '"EUR"']);
    }
};
