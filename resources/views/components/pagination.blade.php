@props(['paginator', 'sizes' => \App\Support\PageSize::SIZES])

@php
    $sizes = collect($sizes)->push($paginator->perPage())->unique()->sort()->values();
@endphp

<nav class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm" aria-label="Pagination">
    <p class="text-muted tabular">Showing {{ number_format($paginator->firstItem() ?? 0) }}–{{ number_format($paginator->lastItem() ?? 0) }} of {{ number_format($paginator->total()) }}</p>
    @if ($paginator->total() > 0)
        <form method="get" class="flex items-center gap-2" data-autosubmit data-page-size>
            @foreach (request()->except(['page', 'perPage']) as $key => $value)
                @foreach ((array) $value as $item)
                    @if (is_scalar($item))
                        <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $item }}" />
                    @endif
                @endforeach
            @endforeach
            <label class="text-muted" for="perPage">Rows per page</label>
            <select class="input w-auto py-1" id="perPage" name="perPage">
                @foreach ($sizes as $size)
                    <option value="{{ $size }}" @selected($size === $paginator->perPage())>{{ number_format($size) }}</option>
                @endforeach
            </select>
        </form>
    @endif
    <div class="flex items-center gap-2">
        @if ($paginator->onFirstPage())
            <span class="btn-secondary btn-sm opacity-50">Previous</span>
        @else
            <a class="btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}">Previous</a>
        @endif
        <span class="text-muted tabular">Page {{ $paginator->currentPage() }} of {{ max($paginator->lastPage(), 1) }}</span>
        @if ($paginator->hasMorePages())
            <a class="btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}">Next</a>
        @else
            <span class="btn-secondary btn-sm opacity-50">Next</span>
        @endif
    </div>
</nav>
