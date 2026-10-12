@if ($paginator->hasPages())
    <nav class="d-flex justify-content-end mt-3" aria-label="Phân trang">
        <ul class="pagination pagination-sm mb-0 align-items-center">
            <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                @if ($paginator->onFirstPage())
                    <span class="page-link px-3" aria-disabled="true" aria-label="Trang trước">&lsaquo;</span>
                @else
                    <a class="page-link px-3" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Trang trước">&lsaquo;</a>
                @endif
            </li>
            <li class="page-item">
                <form method="GET" action="{{ request()->url() }}" class="d-flex h-100">
                    @foreach (request()->except('page') as $key => $value)
                        @if (is_scalar($value))
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <label for="pagination-page-{{ $paginator->getPageName() }}" class="visually-hidden">Chọn trang</label>
                    <select
                        id="pagination-page-{{ $paginator->getPageName() }}"
                        name="{{ $paginator->getPageName() }}"
                        class="form-select form-select-sm rounded-0"
                        style="width: 4rem; min-height: 100%"
                        aria-label="Chọn trang"
                        data-pagination-select
                    >
                        @for ($page = 1; $page <= $paginator->lastPage(); $page++)
                            <option value="{{ $page }}" @selected($page === $paginator->currentPage())>{{ $page }}</option>
                        @endfor
                    </select>
                </form>
            </li>
            <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                @if ($paginator->hasMorePages())
                    <a class="page-link px-3" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Trang sau">&rsaquo;</a>
                @else
                    <span class="page-link px-3" aria-disabled="true" aria-label="Trang sau">&rsaquo;</span>
                @endif
            </li>
        </ul>
    </nav>
@endif