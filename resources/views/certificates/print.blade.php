@php
    use App\Support\CertificateBlocks;

    $content = $certificate->content;
@endphp

<x-layouts.print :title="$certificate->control_number.' — '.$certificate->employee_name" :paper="$content['paperSize']" :back="route('certificates.show', $certificate)" :margin="(CertificateBlocks::MARGIN_POINTS[$content['margin']] ?? 72).'pt'">
    <x-certificates.certificate-sheet :paper-size="$content['paperSize']" :margin="$content['margin']" :void="$certificate->isVoid()" print>{!! $body !!}</x-certificates.certificate-sheet>
</x-layouts.print>
