{{-- Certificate PDF. The PDF engine has no flexbox, and DejaVu covers every character people type. --}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <title>{{ $title }}</title>
        <style>
            @page { margin: {{ $margin }}pt; }
            body { margin: 0; color: #111; }
            .cert { line-height: 1.5; font-size: 12pt; }
            .cert-compact { font-size: 11pt; }
            .cert-left { text-align: left; }
            .cert-center { text-align: center; }
            .cert-right { text-align: right; }
            .cert-justify { text-align: justify; }
            .cert-layout { width: 100%; border-collapse: collapse; }
            .cert-layout td { padding: 0; vertical-align: top; }
            .cert-header { margin-bottom: 4pt; }
            .cert-logo-cell { width: 132pt; }
            .cert-company { font-weight: bold; font-size: 14pt; line-height: 1.25; }
            .cert-lines { font-size: 10pt; color: #333; line-height: 1.35; }
            .cert-title { font-weight: bold; line-height: 1.2; }
            .cert-t-md { font-size: 16pt; }
            .cert-t-lg { font-size: 20pt; }
            .cert-t-xl { font-size: 24pt; }
            .cert-wide { letter-spacing: .12em; }
            .cert-p { margin: 0 0 10pt; }
            .cert-s-xs { font-size: 9.5pt; line-height: 1.28; margin-bottom: 6pt; }
            .cert-s-sm { font-size: 10.5pt; }
            .cert-s-md { font-size: {{ $compact ? 11 : 12 }}pt; }
            .cert-s-lg { font-size: 13.5pt; }
            .cert-indent { text-indent: 36pt; }
            .cert-details { border-collapse: collapse; margin: 0 0 10pt; }
            .cert-details th { text-align: left; font-weight: bold; padding: 2pt 16pt 2pt 0; vertical-align: top; white-space: nowrap; }
            .cert-details td { padding: 2pt 0; }
            .cert-signatory-table { margin-top: 4pt; }
            .cert-sig-cell { width: 180pt; }
            .cert-sig-gap { width: 24pt; }
            .cert-signatory-table.cert-center .cert-sig-cell, .cert-signatory-table.cert-right .cert-sig-cell { text-align: center; }
            .cert-sig-label { font-size: 10.5pt; margin-bottom: 28pt; text-align: left; }
            .cert-sig-line { border-top: 1px solid #111; margin-top: 30pt; }
            .cert-sig-label + .cert-sig-line { margin-top: 0; }
            .cert-sig-name { font-weight: bold; text-transform: uppercase; margin-top: 3pt; }
            .cert-sig-title { font-size: 10.5pt; }
            .cert-hr { border: 0; border-top: 1px solid #333; margin: 6pt 0; }
            .cert-footer { font-size: 9pt; color: #444; margin: 0 0 4pt; }
            .cert-pagebreak { height: 0; page-break-after: always; }
            .cert-pagefooter { position: fixed; left: 0; right: 0; bottom: -{{ max($margin - 18, 10) }}pt; font-size: 9pt; color: #444; }
            .cert-void { position: fixed; top: 40%; left: -10%; width: 120%; text-align: center; font-family: 'DejaVu Serif', serif; font-weight: bold; font-size: 140pt; line-height: 1; color: #c8102e; opacity: .18; transform: rotate(-35deg); }
        </style>
    </head>
    <body>
        @if ($void)
            <div class="cert-void">VOID</div>
        @endif
        {!! $body !!}
    </body>
</html>
