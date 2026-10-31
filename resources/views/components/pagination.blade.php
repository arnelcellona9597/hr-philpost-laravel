@props(['paginator'])

<nav class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm" aria-label="Pagination">
    <p class="text-muted tabular">Showing {{ number_format($paginator->firstItem() ?? 0) }}–{{ number_format($paginator->lastItem() ?? 0) }} of {{ number_format($paginator->total()) }}</p>
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
