@use('App\Kraft\Extensions\ThemeEditor')
<meta name="kraft-editor-url" content="{{ url('/vendor/tinymce') }}">
<script type="application/json" id="kraft-editor-appearance">{!! Js::encode(ThemeEditor::configuration($themeManifest ?? null)) !!}</script>
