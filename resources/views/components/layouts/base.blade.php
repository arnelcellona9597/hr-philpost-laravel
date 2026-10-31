@props(['title'])

<!doctype html>
<html lang="en" @if ($explicitTheme) data-theme="{{ $explicitTheme }}" @endif style="--brand-primary:{{ $palette['primary'] }};--brand-secondary:{{ $palette['secondary'] }};--brand-accent:{{ $palette['accent'] }};--brand-success:{{ $palette['success'] }};--brand-warning:{{ $palette['warning'] }};--brand-danger:{{ $palette['danger'] }};--brand-on-primary:{{ $onPrimary }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="robots" content="noindex, nofollow" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title }} · {{ $company }}</title>
        @if ($logoId)
            <link rel="icon" href="{{ url("/files/{$logoId}") }}" />
        @else
            <link rel="icon" href="data:," />
        @endif
        @unless ($explicitTheme)
            <script>
                ;(function () {
                    var mq = matchMedia('(prefers-color-scheme: dark)')
                    document.documentElement.dataset.theme = mq.matches ? 'dark' : 'light'
                    // Follow the device live until the user picks a theme (which sets the hr-theme cookie).
                    mq.addEventListener('change', function (e) {
                        if (/(^|; )hr-theme=(light|dark)/.test(document.cookie)) return
                        document.documentElement.dataset.theme = e.matches ? 'dark' : 'light'
                        window.dispatchEvent(new CustomEvent('hr:theme'))
                    })
                })()
            </script>
        @endunless
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-dvh antialiased">
        {{ $slot }}
    </body>
</html>
