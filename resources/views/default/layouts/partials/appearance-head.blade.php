@if ($appearance['choice'] === 'auto')
    <script>
        document.documentElement.dataset.bsTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' :
            'light';
    </script>
@endif
