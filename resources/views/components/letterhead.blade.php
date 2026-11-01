{{-- The office letterhead for printed pages. Its wording and logo are edited under Branding & theme. --}}
@props(['site'])

@php
    $letterhead = $site->letterhead();
    ['top' => $top, 'name' => $name, 'details' => $details, 'logoId' => $logoId] = $letterhead;
@endphp

<header class="text-[#111418]">
    <div class="flex items-end justify-between gap-6 pb-1.5">
        <div class="min-w-0">
            <p class="text-[13px]">{{ $top }}</p>
            @foreach ($name as $line)
                <p class="text-[15px] font-bold leading-tight">{{ $line }}</p>
            @endforeach
            @foreach ($details as $line)
                <p class="text-[11px]">{{ $line }}</p>
            @endforeach
        </div>
        @if ($logoId)
            <img src="{{ route('files.show', $logoId, false) }}" alt="" class="max-h-20 w-auto max-w-[45%] shrink-0 object-contain" />
        @endif
    </div>
    <div class="border-t-[3px] border-[#111418]"></div>
    @if ($letterhead['section'] !== '')
        <div class="flex justify-end">
            <p class="bg-[#111418] px-6 py-0.5 text-[12px] font-bold text-white [print-color-adjust:exact]">{{ $letterhead['section'] }}</p>
        </div>
    @endif
</header>
