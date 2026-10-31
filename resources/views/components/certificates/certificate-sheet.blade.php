{{-- One certificate on paper at real size. The slot takes the escaped HTML from the certificate renderer. --}}
@props(['paperSize' => 'A4', 'margin' => 'normal', 'void' => false, 'print' => false])

@php
    [$width, $height] = ['A4' => ['210mm', '297mm'], 'Letter' => ['8.5in', '11in'], 'Legal' => ['8.5in', '14in']][$paperSize] ?? ['210mm', '297mm'];
    $padding = ['narrow' => 50, 'normal' => 72, 'wide' => 96][$margin] ?? 72;
@endphp

<div class="cert-frame">
    <article @class(['sheet cert-paper', 'cert-print' => $print]) style="width:{{ $width }};min-height:{{ $height }};padding:{{ $padding }}pt" aria-label="Certificate">
        {{ $slot }}
        @if ($void)
            <div class="cert-void" aria-label="This certificate is void">VOID</div>
        @endif
    </article>
</div>
