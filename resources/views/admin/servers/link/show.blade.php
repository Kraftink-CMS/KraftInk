@use('App\Kraft\Settings')
@extends('admin.layouts.layout')
@section('title', __('admin.servers.link.title', ['server' => $server->name]))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.servers.deliveries.index', ['server_id' => $server->id]) }}">
        {{ __('admin.servers.deliveries.title') }}</a>
    <a class="btn btn-outline-secondary" href="{{ route('admin.servers.index') }}">{{ __('admin.servers.title') }}</a>
    <a class="btn btn-outline-primary"
        href="{{ route('admin.servers.link.accounts.index', $server) }}">{{ __('admin.servers.accounts.manage') }}</a>
@endsection
@section('content')
    <div class="vstack gap-4">
        @if (session('link.token'))
            <div class="alert alert-warning mb-0">
                <p id="link-token-help">{{ __('admin.servers.link.token_once') }}</p>
                <label class="form-label" for="link-token">{{ __('admin.servers.link.token_title') }}</label>
                <div class="input-group">
                    <input class="form-control font-monospace" id="link-token" value="{{ session('link.token') }}" readonly
                        aria-describedby="link-token-help" autocomplete="off" spellcheck="false">
                    <button class="btn btn-outline-secondary" type="button" data-copy="{{ session('link.token') }}"
                        data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                </div>
            </div>
        @endif
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.servers.link.setup_title') }}</h2>
            </div>
            <div class="card-body">
                <ol class="mb-3 ps-3">
                    <li class="mb-3">
                        <p class="mb-1">{{ __('admin.servers.link.install_step') }}</p>
                        <a href="https://github.com/kraftink-cms/KraftLink/releases/latest" target="_blank"
                            rel="noopener noreferrer">{{ __('admin.servers.link.download') }}</a>
                        <div class="form-text">{{ __('admin.servers.link.requirements') }}</div>
                    </li>
                    <li class="mb-3">{{ __('admin.servers.link.token_step') }}</li>
                    <li class="mb-3">
                        <p>{{ __('admin.servers.link.config_step') }}</p>
                        <label class="form-label" for="link-cms-url">{{ __('admin.servers.link.endpoint') }}</label>
                        <div class="input-group">
                            <input class="form-control font-monospace" id="link-cms-url" value="{{ url('/') }}"
                                aria-describedby="link-cms-url-help" readonly>
                            <button class="btn btn-outline-secondary" type="button" data-copy="{{ url('/') }}"
                                data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                        </div>
                        <div class="form-text" id="link-cms-url-help">{{ __('admin.servers.link.url_help') }}</div>
                    </li>
                    <li class="mb-3">{{ __('admin.servers.link.commands_step') }}</li>
                    <li>{{ __('admin.servers.link.verify_step') }}</li>
                </ol>
                <p class="mb-0">
                    {{ __('admin.servers.link.last_seen', ['date' => $server->last_seen_at?->diffForHumans() ?? __('admin.servers.link.never')]) }}
                </p>
            </div>
        </section>
        <form data-ajax="true" data-redirect-url="response" class="card" method="post"
            action="{{ route('admin.servers.link.token', ['server' => $server]) }}">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.servers.link.token_title') }}</h2>
            </div>
            <div class="card-body">
                <p>{{ __('admin.servers.link.token_help') }}</p>
                @csrf
                <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                <div class="form-check">
                    <input class="form-check-input" id="link-default" type="checkbox" name="default" value="1"
                        aria-describedby="link-default-help"
                        @checked(app(Settings::class)->get('link.default_server') == $server->id)>
                    <label class="form-check-label" for="link-default">{{ __('admin.servers.link.default') }}</label>
                    <div class="form-text" id="link-default-help">{{ __('admin.servers.link.default_help') }}</div>
                </div>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('admin.servers.link.rotate') }}</button>
            </div>
        </form>
        @if ($server->enabled)
            <form data-ajax="true" data-redirect-url="response" class="card" method="post"
                action="{{ route('admin.servers.link.enqueue', ['server' => $server]) }}">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.servers.link.console') }}</h2>
                </div>
                <div class="card-body">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="command">{{ __('admin.servers.link.command') }}</label>
                        <input class="form-control" id="command" name="command" value="{{ old('command') }}"
                            aria-describedby="link-command-help" maxlength="2048" required>
                        <div class="form-text" id="link-command-help">{{ __('admin.servers.link.command_help') }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="link-player">{{ __('admin.servers.link.player') }}</label>
                        <input class="form-control" id="link-player" name="player" maxlength="64" value="{{ old('player') }}"
                            aria-describedby="link-player-help" autocomplete="off" spellcheck="false">
                        <div class="form-text" id="link-player-help">{{ __('admin.servers.link.player_help') }}</div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" id="link-requires-online" type="checkbox" name="requires_online"
                            value="1" @checked(old('requires_online'))>
                        <label class="form-check-label"
                            for="link-requires-online">{{ __('admin.servers.link.requires_online') }}</label>
                    </div>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">{{ __('admin.servers.link.enqueue') }}</button>
                </div>
            </form>
        @else
            <div class="alert alert-secondary mb-0">
                {{ __('admin.servers.transport.command_disabled') }}
            </div>
        @endif
        <div id="admin-link-commands" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
            <section class="card">
                <x-tables.controls :paginator="$commands" container-class="card-body" />
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="text-nowrap">
                            <tr>
                                <th data-table-sort="command">{{ __('admin.servers.link.command') }}</th>
                                <th data-table-sort="status">{{ __('admin.servers.link.status') }}</th>
                                <th data-table-sort="attempts">{{ __('admin.servers.link.attempts') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($commands as $command)
                                <tr>
                                    <td>{{ $command->command }}</td>
                                    <td>{{ $command->status }}</td>
                                    <td>{{ $command->attempts }}</td>
                                </tr>
                            @empty
                                <x-tables.empty :colspan="3" :message="__('admin.servers.deliveries.empty')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    {{ $commands->links() }}
                </div>
            </section>
        </div>
    </div>
@endsection
