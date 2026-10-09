@if ($paginator->hasPages())
<nav class="d-flex justify-items-center justify-content-between">
    <div class="d-flex justify-content-between flex-fill d-sm-none">
        <ul class="pagination">
            @if ($paginator->onFirstPage())
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">Trước</span>
            </li>
            @else
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Trước</a>
            </li>
            @endif

            @if ($paginator->hasMorePages())
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Sau</a>
            </li>
            @else
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link">Sau</span>
            </li>
            @endif
        </ul>
    </div>

    <div class="d-none flex-sm-fill d-sm-flex align-items-sm-center justify-content-sm-between">
        <div>
            <p class="small text-muted mb-0">
                Hiển thị
                <span class="fw-semibold">{{ $paginator->firstItem() }}</span>
                đến
                <span class="fw-semibold">{{ $paginator->lastItem() }}</span>
                trên tổng số
                <span class="fw-semibold">{{ $paginator->total() }}</span>
                kết quả
            </p>
        </div>

        <div>
            <ul class="pagination">
                @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="Trang trước">
                    <span class="page-link" aria-hidden="true">&lsaquo;</span>
                </li>
                @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Trang trước">&lsaquo;</a>
                </li>
                @endif

                @php
                    $lastPage = $paginator->lastPage();
                    $currentPage = $paginator->currentPage();
                    if ($lastPage <= 4) {
                        $visiblePages = range(1, $lastPage);
                    } else {
                        $startPage = max(1, $currentPage - 1);
                        $endPage = min($lastPage, $currentPage === 1 ? 2 : $currentPage);
                        $visiblePageNumbers = array_values(array_unique([
                            ...range($startPage, $endPage),
                            ...range($lastPage - 1, $lastPage),
                        ]));
                        sort($visiblePageNumbers);

                        $visiblePages = [];
                        $previousPage = null;
                        foreach ($visiblePageNumbers as $pageNumber) {
                            if ($previousPage !== null && $pageNumber > $previousPage + 1) {
                                $visiblePages[] = '...';
                            }
                            $visiblePages[] = $pageNumber;
                            $previousPage = $pageNumber;
                        }
                    }
                @endphp
                @foreach ($visiblePages as $page)
                @if (is_string($page))
                <li class="page-item disabled" aria-hidden="true"><span class="page-link">{{ $page }}</span></li>
                @else
                @php($url = $paginator->url($page))
                @if ($page == $paginator->currentPage())
                <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                @else
                <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
                @endif
                @endif
                @endforeach

                @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Trang sau">&rsaquo;</a>
                </li>
                @else
                <li class="page-item disabled" aria-disabled="true" aria-label="Trang sau">
                    <span class="page-link" aria-hidden="true">&rsaquo;</span>
                </li>
                @endif
            </ul>
        </div>
    </div>
</nav>
@endif