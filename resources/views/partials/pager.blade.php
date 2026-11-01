@if ($paginator->hasPages())
    <nav class="row small" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="muted">Newer</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">Newer</a>
        @endif
        <span class="muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Older</a>
        @else
            <span class="muted">Older</span>
        @endif
    </nav>
@endif
