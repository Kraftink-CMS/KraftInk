@foreach ($themeManifest['assets'][$kind] ?? [] as $asset)
    @if ($kind === 'styles')
        <link rel="stylesheet"
            href="{{ route('site.extensions.assets.show', ['id' => $themeManifest['id'], 'asset' => $asset]) }}">
    @else
        <script
            src="{{ route('site.extensions.assets.show', ['id' => $themeManifest['id'], 'asset' => $asset]) }}"
            defer></script>
    @endif
@endforeach
