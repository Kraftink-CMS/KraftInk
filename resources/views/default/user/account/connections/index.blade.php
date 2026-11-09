@extends('default.layouts.layout')
@section('title', __('site.account.connections.title'))
@section('content')
    <div class="page-heading">
        <h1>{{ __('site.account.connections.title') }}</h1>
        <p>{{ __('site.account.connections.help') }}</p>
    </div>
    <div class="d-flex flex-column gap-4">
        @if ($capabilities->canManageDiscordRoles)
            <section class="card">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">{{ __('admin.integrations.discord.title') }}</h2>
                    <p>{{ __('admin.integrations.discord.account_intro') }}</p>
                    <a class="btn btn-outline-primary"
                        href="{{ route('account.discord.roles.index') }}">{{ __('admin.integrations.discord.title') }}</a>
                </div>
            </section>
        @endif
        @foreach ($connectionOptions as $connection)
            <section class="card">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">{{ $connection['name'] }}</h2>
                    <p>{{ $connection['description'] }}</p>
                    <form data-ajax="true" data-redirect-url="response" method="post"
                        @if (!$connection['isLinked']) data-external-redirect="oauth" @endif
                        action="{{ url('/account/connections/' . $connection['id']) }}">
                        @csrf
                        @if ($connection['isLinked'])
                            @method('DELETE')
                        @endif
                        @include('partials.forms.sensitive-fields', [
                            'user' => auth()->user(),
                            'formKey' => 'connection_' . $connection['id'],
                        ])
                        <button class="btn btn-outline-primary"
                            type="submit">{{ $connection['isLinked'] ? __('site.account.connections.disconnect') : __('site.account.connections.connect') }}</button>
                    </form>
                </div>
            </section>
        @endforeach
    </div>
@endsection
