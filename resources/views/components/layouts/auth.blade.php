@props(['title', 'subtitle' => null, 'footer' => null])

<x-layouts.base :title="$title">
    <main class="auth-stage relative isolate flex min-h-dvh items-center overflow-hidden">
        <div class="auth-photo" style="background-image: url('{{ asset('images/login-background.jpg') }}')" aria-hidden="true"></div>
        <div class="auth-veil" aria-hidden="true"></div>

        <div class="relative mx-auto grid w-full max-w-6xl items-center gap-12 px-5 py-10 lg:grid-cols-[minmax(0,1fr)_27rem] lg:px-10">
            <section class="auth-brand hidden text-white lg:block" aria-hidden="true">
                <p class="text-xs font-medium uppercase tracking-[0.32em] text-white/70">Human Resource Management</p>
                <h2 class="mt-5 font-serif text-6xl font-light leading-[1.05] tracking-tight">{{ $company }}</h2>
                <span class="auth-rule mt-7 block h-px w-28"></span>
                @if ($tagline)
                    <p class="mt-6 font-serif text-xl font-light italic text-white/85">{{ $tagline }}</p>
                @endif
                <p class="mt-10 max-w-md text-sm leading-relaxed text-white/60">A secure workspace for the records, payroll and documents of the people who deliver for the nation.</p>
            </section>

            <div class="auth-rise w-full max-w-md justify-self-center lg:justify-self-end">
                <div class="mb-6 flex flex-col items-center text-center lg:hidden">
                    @if ($logoId)
                        <span class="mb-3 inline-flex rounded-lg bg-white px-3 py-2 shadow-elev-2"><img src="{{ url("/files/{$logoId}") }}" alt="" class="h-9 w-auto max-w-[220px] object-contain" /></span>
                    @endif
                    <p class="font-serif text-2xl font-light text-white">{{ $company }}</p>
                    @if ($tagline)
                        <p class="mt-1 text-xs text-white/70">{{ $tagline }}</p>
                    @endif
                </div>

                <div class="auth-glass relative rounded-2xl p-6 sm:p-9">
                    @if ($logoId)
                        <span class="mb-6 hidden w-fit rounded-lg bg-white px-3 py-2 shadow-elev-1 lg:inline-flex"><img src="{{ url("/files/{$logoId}") }}" alt="{{ $company }}" class="h-9 w-auto max-w-[220px] object-contain" /></span>
                    @endif
                    <h1 class="font-serif text-3xl font-light tracking-tight">{{ $title }}</h1>
                    @if ($subtitle)
                        <p class="mt-1.5 text-sm text-muted">{{ $subtitle }}</p>
                    @endif
                    <div class="mt-7">{{ $slot }}</div>
                </div>
                <div class="mt-6 text-center text-sm text-white/65">{{ $footer }}</div>
            </div>
        </div>
    </main>
</x-layouts.base>
