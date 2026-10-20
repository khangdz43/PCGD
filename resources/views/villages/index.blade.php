@extends('layouts.app')

@section('title', 'Danh sách thôn/xóm - PCGD-XMC')

@section('content')
<div class="container">
    <h1 class="visually-hidden">Danh sách thôn/xóm</h1>
    <!-- check cái truyền từ controller success í -->
    <form id="village-filter-form" method="GET" action="{{ route('villages.index', [], false) }}" class="directory-filter-form bg-white border rounded-2 p-2 mb-2">
        <div class="directory-filter-field">
            <label for="village-search" class="visually-hidden">Mã hoặc tên thôn/xóm</label>
            <input id="village-search" name="q" type="search" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Mã / Tên thôn, xóm">
        </div>
        @include('partials.location-picker', [
        'pickerId' => 'village-filter-location',
        'provinces' => $provinces,
        'provinceCode' => $provinceCode,
        'communeCode' => $communeCode,
        'inline' => true,
        ])
        <button class="btn btn-sm btn-outline-secondary directory-filter-clear" type="button" id="clear-village-filters" title="Xóa bộ lọc" aria-label="Xóa bộ lọc">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
        <a class="btn btn-sm btn-primary directory-filter-add" href="{{ route('villages.create', [], false) }}">
            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Thêm thôn/xóm
        </a>
        <span id="village-filter-status" class="small text-danger" role="status" aria-live="polite"></span>
    </form>

    <div id="village-results" aria-live="polite">
        <div class="table-responsive bg-white border rounded-2">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Mã thôn/xóm</th>
                        <th scope="col">Tên thôn/xóm</th>
                        <th scope="col">Tỉnh/Thành phố</th>
                        <th scope="col">Xã/Phường</th>
                        <th scope="col" class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($villages as $village)
                    <tr>
                        <td class="text-nowrap">{{ $village->code }}</td>
                        <td>{{ $village->name }}</td>
                        <td>{{ $village->commune->province->name ?? '—' }}</td>
                        <td>{{ $village->commune->name ?? '—' }}</td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('villages.edit', $village->code, false) }}" title="Sửa" aria-label="Sửa {{ $village->name }}">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                            </a>
                            <form class="d-inline village-delete-form" method="POST" action="{{ route('villages.destroy', $village->code, false) }}" data-village-name="{{ $village->name }}">
                                {{-- sinh csrdf chống tấn công --}}
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa" aria-label="Xóa {{ $village->name }}">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">Chưa có thôn/xóm phù hợp.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-2" data-turbo="false">{{ $villages->links('pagination::bootstrap-5') }}</div>
    </div>
</div>

@push('scripts')
<style>
    .directory-filter-form {
        display: grid;
        grid-template-columns: minmax(140px, 1.4fr) repeat(2, minmax(120px, 1fr)) auto auto;
        align-items: center;
        gap: 0.5rem;
    }

    .directory-filter-field,
    .directory-filter-form .location-picker-field {
        min-width: 0;
    }

    .directory-filter-form .location-picker-inline {
        display: contents;
    }

    .directory-filter-clear {
        width: 2rem;
        height: 2rem;
        padding: 0;
    }

    .directory-filter-add {
        white-space: nowrap;
    }

    .directory-filter-form [id$="-filter-status"]:empty {
        display: none;
    }

    .directory-filter-form [id$="-filter-status"]:not(:empty) {
        grid-column: 1 / -1;
    }

    #village-results.directory-results-loading {
        pointer-events: none;
    }

    @media (max-width: 767.98px) {
        .directory-filter-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .directory-filter-add {
            width: 100%;
        }
    }
</style>
<script>
    (() => {
    const villageFilterForm = document.getElementById('village-filter-form');
    const villageResults = document.getElementById('village-results');
    const villageFilterStatus = document.getElementById('village-filter-status');
    const clearVillageFilters = document.getElementById('clear-village-filters');
    let villageFilterTimer;
    let villageFilterRequest;

    function villageFilterUrl() {
        const url = new URL(villageFilterForm.action, window.location.href);
        url.search = '';
        new URLSearchParams(new FormData(villageFilterForm)).forEach((value, key) => {
            if (value) url.searchParams.set(key, value);
        });
        return url;
    }

    function updateVillageClearButton() {
        clearVillageFilters.disabled = ![...new FormData(villageFilterForm).values()].some((value) => String(value).trim());
    }

    async function refreshVillages(url = villageFilterUrl()) {
        const scrollPosition = window.scrollY;
        villageFilterRequest?.abort();
        const requestController = new AbortController();
        villageFilterRequest = requestController;
        villageResults.setAttribute('aria-busy', 'true');
        villageResults.classList.add('directory-results-loading');
        villageFilterStatus.textContent = '';

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: requestController.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextResults = page.getElementById('village-results');
            if (!nextResults) throw new Error('Village results were not found in the response.');

            villageResults.innerHTML = nextResults.innerHTML;
            window.history.replaceState({}, '', `${url.pathname}${url.search}`);
            updateVillageClearButton();
            window.requestAnimationFrame(() => window.scrollTo(0, scrollPosition));
            return true;
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Không tải được danh sách thôn/xóm:', error);
                villageFilterStatus.textContent = 'Không tải được kết quả lọc. Vui lòng thử lại.';
            }
            return false;
        } finally {
            if (villageFilterRequest === requestController) {
                villageFilterRequest = null;
                villageResults.removeAttribute('aria-busy');
                villageResults.classList.remove('directory-results-loading');
            }
        }
    }

    villageFilterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshVillages();
    });

    villageFilterForm.addEventListener('input', (event) => {
        if (event.target.name !== 'q') return;
        clearTimeout(villageFilterTimer);
        villageFilterTimer = setTimeout(() => refreshVillages(), 300);
        updateVillageClearButton();
    });

    villageFilterForm.addEventListener('change', (event) => {
        if (event.target.matches('select')) refreshVillages();
        updateVillageClearButton();
    });

    clearVillageFilters.addEventListener('click', () => {
        villageFilterForm.querySelectorAll('input[name], select[name]').forEach((field) => field.value = '');
        villageFilterForm.querySelector('[data-province]').dispatchEvent(new Event('change', {
            bubbles: true
        }));
    });

    villageResults.addEventListener('click', (event) => {
        const pageLink = event.target.closest('.pagination a');
        if (pageLink) {
            event.preventDefault();
            refreshVillages(new URL(pageLink.href, window.location.href));
        }
    });

    villageResults.addEventListener('change', (event) => {
        const pageSelect = event.target.closest('nav[aria-label="Phân trang"] select[data-pagination-select]');
        if (!pageSelect) return;
        event.preventDefault();

        const pageForm = pageSelect.form;
        const url = new URL(pageForm.action, window.location.href);
        new URLSearchParams(new FormData(pageForm)).forEach((value, key) => {
            if (value) url.searchParams.set(key, value);
        });
        refreshVillages(url);
    });

    villageResults.addEventListener('submit', async (event) => {
        const form = event.target.closest('.village-delete-form');
        if (!form) return;

        event.preventDefault();
        if (!await window.appConfirm(`Bạn có chắc muốn xóa thôn/xóm "${form.dataset.villageName}"?`, {
            title: 'Xác nhận xóa thôn/xóm',
            confirmText: 'Xóa thôn/xóm',
        })) return;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        form.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
            });
            const isJsonResponse = response.headers.get('content-type')?.includes('application/json');
            const result = isJsonResponse ? await response.json() : {};
            if (!response.ok) {
                const validationMessage = Object.values(result.errors || {}).flat()[0];
                throw new Error(validationMessage || result.message || 'Không thể xóa thôn/xóm. Vui lòng thử lại.');
            }
            if (!isJsonResponse) {
                throw new Error('Máy chủ trả về phản hồi không hợp lệ. Vui lòng thử lại.');
            }

            const row = form.closest('tr');
            const remainingRows = row.parentElement.querySelectorAll('tr:not(.d-none)');
            const refreshUrl = new URL(window.location.href);
            const currentPage = Number(refreshUrl.searchParams.get('page') || 1);
            if (remainingRows.length === 1 && currentPage > 1) {
                refreshUrl.searchParams.set('page', String(currentPage - 1));
            }

            row.remove();
            window.showAppNotification('success', result.message || 'Đã xóa thôn/xóm thành công!');
            await refreshVillages(refreshUrl);
        } catch (error) {
            console.error('Không xóa được thôn/xóm:', error);
            window.showAppNotification('error', error.message || 'Không thể xóa thôn/xóm. Vui lòng thử lại.');
            submitButton.disabled = false;
            form.removeAttribute('aria-busy');
        }
    });

    updateVillageClearButton();
    })();
</script>
@endpush
@endsection