@use('App\Kraft\Site\Seo')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ app(Seo::class)->formatTitle(__('system.maintenance.title')) }}</title>
    @include('partials.layout.favicons')
    @vite(['resources/scss/app.scss'])
</head>
<body class="bg-body-tertiary">
    <main class="container py-5">
        <div class="row justify-content-center py-md-5">
            <div class="col-md-8 col-lg-6">
                <section class="card">
                    <div class="card-body p-4 p-md-5">
                        <p class="text-secondary mb-2">{{ config('app.name') }}</p>
                        <h1 class="h3 mb-3">{{ __('system.maintenance.heading') }}</h1>
                        <div class="mb-4">
                            {!! nl2br(e($message)) !!}
                        </div>
                        <a class="btn btn-outline-secondary"
                            href="{{ url('/login') }}">{{ __('common.labels.login') }}</a>
                    </div>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
