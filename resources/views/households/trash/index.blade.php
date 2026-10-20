@extends('layouts.app')

@section('title', 'Thùng rác phiếu điều tra - PCGD-XMC')

@section('content')
<div class="container trash-page">
    <form id="trash-filter-form" method="GET" action="{{ route('utilities.trash.index', [], false) }}" class="trash-filter-form bg-white border rounded-2 p-2 mb-2">
        <div class="trash-filter-field">
            <label for="trash-search" class="visually-hidden">Mã phiếu hoặc tên thành viên</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
                <input id="trash-search" class="form-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Mã phiếu / Tên thành viên">
            </div>
        </div>
        <div class="trash-filter-field">
            <label for="trash-type" class="visually-hidden">Loại dữ liệu</label>
            <select id="trash-type" name="type" class="form-select form-select-sm">
                <option value="all" @selected(($filters['type'] ?? 'all') === 'all')>Tất cả loại dữ liệu</option>
                <option value="household" @selected(($filters['type'] ?? '') === 'household')>Phiếu / hộ</option>
                <option value="person" @selected(($filters['type'] ?? '') === 'person')>Thành viên</option>
            </select>
        </div>
        <div class="trash-filter-field">
            <label for="trash-deleted-from" class="visually-hidden">Xóa từ ngày</label>
            <input id="trash-deleted-from" class="form-control form-control-sm" type="date" name="deleted_from" value="{{ $filters['deleted_from'] ?? '' }}" title="Xóa từ ngày">
        </div>
        <div class="trash-filter-field">
            <label for="trash-deleted-to" class="visually-hidden">Đến ngày</label>
            <input id="trash-deleted-to" class="form-control form-control-sm" type="date" name="deleted_to" value="{{ $filters['deleted_to'] ?? '' }}" title="Đến ngày">
        </div>
        @include('partials.location-picker', [
            'pickerId' => 'trash-location',
            'provinces' => $provinces,
            'provinceCode' => $selectedProvinceCode ?? '',
            'communeCode' => $selectedCommuneCode ?? '',
            'villageCode' => $filters['village_code'] ?? '',
            'initialCommune' => $initialCommune,
            'initialVillages' => $villages,
            'showVillage' => true,
            'inline' => true,
            'hideProvince' => auth()->user()->roleScopeLevel() !== 'global' && !auth()->user()->hasRole(\App\Models\Role::SYSTEM_ADMIN),
            'hideCommune' => in_array(auth()->user()->roleScopeLevel(), ['commune', 'village'], true),
            'hideVillage' => false,
        ])
        <div class="trash-filter-actions">
            <a id="clear-trash-filters" class="btn btn-sm btn-outline-secondary" href="{{ route('utilities.trash.index', [], false) }}">
                <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Xóa lọc
            </a>
        </div>
    </form>

    <div id="trash-results" aria-live="polite">
        @include('households.trash._results')
    </div>
</div>
@push('scripts')
<style>
    .trash-page {
        padding-top: .5rem;
        padding-bottom: 1rem;
    }

    .trash-filter-form {
        display: grid;
        grid-template-columns: minmax(180px, 1.5fr) repeat(6, minmax(120px, 1fr)) auto;
        align-items: center;
        gap: .5rem;
    }

    .trash-filter-field,
    .trash-filter-form .location-picker-field {
        min-width: 0;
    }

    .trash-filter-form .location-picker-inline {
        display: contents;
    }

    .trash-filter-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .trash-filter-form .location-picker-status:empty {
        display: none;
    }

    .trash-table {
        min-width: 1280px;
    }

    .trash-table-scroll {
        max-height: min(16rem, 55vh);
        overflow: auto;
    }

    .trash-table-scroll thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8f9fa;
        white-space: nowrap;
    }

    .household-results-loading {
        opacity: .55;
        pointer-events: none;
    }

    .trash-item-checkbox,
    #select-all-trash-items {
        width: 1.35rem;
        height: 1.35rem;
        cursor: pointer;
    }

    .trash-type-badge {
        display: inline-flex;
        align-items: center;
        padding: .35rem .55rem;
        border-radius: 2rem;
        font-size: .75rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .trash-type-household {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .trash-type-person {
        background: #f1f5f9;
        color: #475569;
    }

    .trash-record-title {
        display: block;
        color: #1e293b;
        font-weight: 700;
    }

    .trash-record-subtitle {
        display: block;
        margin-top: .2rem;
        color: #6c757d;
        font-size: .8rem;
    }

    .trash-location {
        display: flex;
        align-items: flex-start;
        gap: .4rem;
        min-width: 9rem;
        color: #475569;
    }

    .trash-date,
    .trash-time {
        display: block;
        white-space: nowrap;
    }

    .trash-time {
        color: #334155;
        font-weight: 600;
    }

    .trash-time {
        margin-top: .15rem;
        color: #6c757d;
        font-size: .8rem;
    }

    .trash-deleted-by {
        color: #475569;
        white-space: nowrap;
    }

    .trash-row-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .25rem;
    }

    @media (max-width: 767.98px) {
        .trash-filter-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .trash-filter-form > .trash-filter-field:first-child,
        .trash-filter-form > .location-picker-status {
            grid-column: 1 / -1;
        }

        .trash-filter-actions {
            grid-column: 1 / -1;
        }
    }

    @media (min-width: 768px) and (max-width: 1199.98px) {
        .trash-filter-form {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .trash-filter-form > .trash-filter-field:first-child {
            grid-column: span 2;
        }

        .trash-filter-actions {
            grid-column: 1 / -1;
        }
    }
</style>
<script>
    (() => {
        const filterForm = document.getElementById('trash-filter-form');
        const results = document.getElementById('trash-results');
        if (!filterForm || !results || filterForm.dataset.ajaxInitialized === 'true') return;
        filterForm.dataset.ajaxInitialized = 'true';

        let filterTimer;
        let activeRequest;

        function filterUrl() {
            const url = new URL(filterForm.action, window.location.href);
            url.search = '';
            new URLSearchParams(new FormData(filterForm)).forEach((value, key) => {
                if (value) url.searchParams.set(key, value);
            });
            return url;
        }

        async function refreshResults(url = filterUrl(), historyMode = 'replaceState') {
            activeRequest?.abort();
            const requestController = new AbortController();
            activeRequest = requestController;
            results.setAttribute('aria-busy', 'true');
            results.classList.add('household-results-loading');

            try {
                const response = await fetch(url, {
                    headers: {
                        Accept: 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: requestController.signal,
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                if (response.headers.get('X-Trash-Results') !== '1') {
                    throw new Error('Phản hồi không phải danh sách thùng rác.');
                }

                results.innerHTML = await response.text();
                if (historyMode !== 'none') {
                    window.history[historyMode]({}, '', `${url.pathname}${url.search}`);
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Không tải được dữ liệu thùng rác:', error);
                    window.showAppNotification('error', 'Không tải được dữ liệu thùng rác. Vui lòng thử lại.');
                }
            } finally {
                if (activeRequest === requestController) {
                    activeRequest = null;
                    results.removeAttribute('aria-busy');
                    results.classList.remove('household-results-loading');
                }
            }
        }

        function scheduleFilterRefresh() {
            window.clearTimeout(filterTimer);
            filterTimer = window.setTimeout(() => refreshResults(), 300);
        }

        filterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            window.clearTimeout(filterTimer);
            refreshResults();
        });
        filterForm.addEventListener('input', (event) => {
            if (event.target.matches('input[type="search"]')) scheduleFilterRefresh();
        });
        filterForm.addEventListener('change', () => {
            window.clearTimeout(filterTimer);
            refreshResults();
        });

        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            const clearLink = event.target.closest('#clear-trash-filters');
            if (clearLink) {
                event.preventDefault();
                window.clearTimeout(filterTimer);
                filterForm.querySelector('[name="q"]').value = '';
                filterForm.querySelector('[name="type"]').selectedIndex = 0;
                filterForm.querySelector('[name="deleted_from"]').value = '';
                filterForm.querySelector('[name="deleted_to"]').value = '';
                const province = filterForm.querySelector('[data-province]');
                const commune = filterForm.querySelector('[data-commune]');
                const village = filterForm.querySelector('[data-village]');
                if (province) {
                    const provinceField = province.closest('.location-picker-field');
                    if (!provinceField?.hidden) province.selectedIndex = 0;
                }
                if (commune) {
                    const communeField = commune.closest('.location-picker-field');
                    if (!communeField?.hidden) commune.selectedIndex = 0;
                    commune.disabled = !province?.value;
                }
                if (village) {
                    village.selectedIndex = 0;
                    village.disabled = !commune?.value;
                }
                refreshResults(new URL(clearLink.href, window.location.href), 'pushState');
                return;
            }

            const link = event.target.closest('#trash-results .pagination a');
            if (!link) return;
            event.preventDefault();
            refreshResults(new URL(link.href, window.location.href), 'pushState');
        });

        window.addEventListener('popstate', () => {
            refreshResults(new URL(window.location.href), 'none');
        });

        const updateSelection = () => {
            const bulkForm = document.getElementById('trash-bulk-form');
            if (!bulkForm) return;
            const items = [...results.querySelectorAll('.trash-item-checkbox')];
            const selectAll = results.querySelector('#select-all-trash-items');
            const count = bulkForm.querySelector('[data-trash-selection-count]');
            const actionButtons = [...bulkForm.querySelectorAll('[data-trash-operation]')];
            const selectedCount = items.filter((checkbox) => checkbox.checked).length;
            actionButtons.forEach((button) => { button.disabled = selectedCount === 0; });
            count.textContent = selectedCount === 0
                ? 'Chưa chọn mục nào'
                : `Đã chọn ${selectedCount} mục`;
            if (selectAll) {
                selectAll.checked = items.length > 0 && selectedCount === items.length;
                selectAll.indeterminate = false;
            }
        };

        results.addEventListener('change', (event) => {
            if (!(event.target instanceof Element)) return;
            if (event.target.matches('#select-all-trash-items')) {
                results.querySelectorAll('.trash-item-checkbox').forEach((checkbox) => {
                    checkbox.checked = event.target.checked;
                });
                updateSelection();
            } else if (event.target.matches('.trash-item-checkbox')) {
                updateSelection();
            }
        });
        results.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            const button = event.target.closest('[data-trash-operation]');
            if (!button) return;
            const operation = button.dataset.trashOperation;
            const bulkForm = results.querySelector('#trash-bulk-form');
            results.querySelector('#trash-bulk-operation').value = operation;
            bulkForm.dataset.confirm = bulkForm.dataset[`confirm${operation === 'force_delete' ? 'ForceDelete' : 'Restore'}`];
            bulkForm.dataset.confirmButton = bulkForm.dataset[`confirmButton${operation === 'force_delete' ? 'ForceDelete' : 'Restore'}`];
            bulkForm.dataset.confirmVariant = bulkForm.dataset[`confirmVariant${operation === 'force_delete' ? 'ForceDelete' : 'Restore'}`];
        });
        updateSelection();
    })();
</script>
@endpush
@endsection
