<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('filenest.supported_locales')[app()->getLocale()]['dir'] ?? 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Applied before first paint (not from app.js) so there's no flash of
             the wrong theme while Vue boots. Kept in sync with useTheme.js. --}}
        <script>
            (function () {
                var theme = localStorage.getItem('theme');
                var isDark = theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', isDark);
            })();
        </script>

        <title inertia>{{ \App\Models\Setting::get('site_name', config('app.name', 'FileNest')) }}</title>

        @if($logo = \App\Models\Setting::get('logo_path'))
            <link rel="icon" href="{{ asset('storage/'.$logo) }}">
        @endif

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&family=cairo:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead

        @if($customCss = \App\Models\Setting::get('custom_css'))
            <style>{!! $customCss !!}</style>
        @endif
    </head>
    <body class="font-sans antialiased" data-skin="{{ \App\Models\Setting::get('active_skin', 'indigo') }}">
        @inertia
    </body>
</html>
