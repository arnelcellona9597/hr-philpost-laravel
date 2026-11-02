@php
    use App\Support\CertificateBlocks;

    $content = $contract->content;
@endphp

<x-layouts.print :title="$contract->control_number.' — '.$contract->employee_name" :paper="$content['paperSize']" :back="route('contracts.show', $contract)" :margin="CertificateBlocks::printPageMargin($content['margin'])">
    <x-certificates.certificate-sheet :paper-size="$content['paperSize']" :margin="$content['margin']" :void="$contract->isVoid()" print>{!! $body !!}</x-certificates.certificate-sheet>
</x-layouts.print>
