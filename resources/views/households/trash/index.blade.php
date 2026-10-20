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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <h2 id="trash-results-heading" class="h6 mb-0">Dữ liệu đã xóa <span class="text-muted fw-normal">({{ number_format($items->total()) }} mục)</span></h2>
            <span class="small text-muted">Chọn các mục để khôi phục hoặc xóa vĩnh viễn.</span>
        </div>
        <form
            id="trash-bulk-form"
            method="POST"
            action="{{ route('utilities.trash.bulk', [], false) }}"
            class="trash-bulk-toolbar household-bulk-actions d-flex flex-wrap align-items-center gap-2 mb-3"
            data-confirm="Xác nhận thao tác với các mục đã chọn? Nếu chọn xóa vĩnh viễn, dữ liệu sẽ không thể khôi phục."
            data-confirm-title="Xác nhận thao tác thùng rác"
            data-confirm-button="Tiếp tục"
        >
            @csrf
            <input type="hidden" name="operation" id="trash-bulk-operation" value="">
            <span class="trash-selection-status small text-muted me-1" data-trash-selection-count>
                <i class="fa-regular fa-square-check me-1" aria-hidden="true"></i>Chưa chọn mục nào
            </span>
            <div class="trash-bulk-actions d-flex flex-wrap align-items-center gap-2">
                <button class="btn btn-sm btn-outline-success" type="submit" data-trash-operation="restore" disabled>
                    <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Khôi phục đã chọn
                </button>
                <button class="btn btn-sm btn-outline-danger" type="submit" data-trash-operation="force_delete" disabled>
                    <i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>Xóa vĩnh viễn đã chọn
                </button>
            </div>
        </form>

        <div class="table-responsive trash-table-scroll bg-white border rounded-2" tabindex="0" aria-label="Dữ liệu đã xóa, vuốt ngang để xem thêm thông tin">
            <table class="table table-sm table-hover align-middle mb-0 trash-table">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="text-center">
                            <input class="form-check-input trash-checkbox" type="checkbox" id="select-all-trash-items" aria-label="Chọn tất cả mục trên trang">
                        </th>
                        <th scope="col">Loại</th>
                        <th scope="col">Thông tin</th>
                        <th scope="col">Địa bàn</th>
                        <th scope="col">Thời điểm xóa</th>
                        <th scope="col">Người xóa</th>
                        <th scope="col" class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @if ($item->record)
                            @php
                                $record = $item->record;
                                $household = $item->item_type === 'household' ? $record : $record->household;
                                $location = $household?->village?->name
                                    ?? $household?->commune?->name
                                    ?? '—';
                                $recordType = $item->item_type;
                            @endphp
                            <tr>
                                <td class="text-center">
                                    <input
                                        class="form-check-input trash-checkbox trash-item-checkbox"
                                        type="checkbox"
                                        form="trash-bulk-form"
                                        name="{{ $recordType === 'household' ? 'household_ids[]' : 'person_ids[]' }}"
                                        value="{{ $record->id }}"
                                        aria-label="Chọn {{ $recordType === 'household' ? 'phiếu '.$record->household_code : trim($record->last_name.' '.$record->first_name) }}"
                                    >
                                </td>
                                <td>
                                    <span class="trash-type-badge {{ $item->item_type === 'household' ? 'trash-type-household' : 'trash-type-person' }}">
                                        <i class="fa-solid {{ $item->item_type === 'household' ? 'fa-house' : 'fa-user' }} me-1" aria-hidden="true"></i>
                                        {{ $item->item_type === 'household' ? 'Phiếu / hộ' : 'Thành viên' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($item->item_type === 'household')
                                            <div class="trash-record-title">Phiếu {{ $record->household_code }}</div>
                                            <div class="trash-record-subtitle">
                                            {{ trim($record->head_last_name.' '.$record->head_first_name) ?: 'Chưa có tên chủ hộ' }}
                                            · {{ $record->persons_count }} thành viên
                                        </div>
                                    @else
                                            <div class="trash-record-title">{{ trim($record->last_name.' '.$record->first_name) }}</div>
                                            <div class="trash-record-subtitle">Phiếu {{ $household?->household_code ?? '—' }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="trash-location">
                                        {{ $location }}{{ $household?->province ? ', '.$household->province->name : '' }}
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                        <span class="trash-date">{{ $record->deleted_at?->format('d/m/Y') }}</span>
                                        <span class="trash-time">{{ $record->deleted_at?->format('H:i') }}</span>
                                </td>
                                <td>
                                    <span class="trash-deleted-by">{{ $record->deletedBy?->name ?? '—' }}</span>
                                </td>
                                <td class="text-end">
                                        <div class="trash-row-actions">
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('utilities.trash.households.show', $household->id, false) }}" title="Xem chi tiết hộ">
                                                <i class="fa-solid fa-eye me-sm-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Xem hộ</span>
                                        </a>
                                            <form method="POST" action="{{ $item->item_type === 'household' ? route('utilities.trash.households.restore', $record->id, false) : route('utilities.trash.persons.restore', $record->id, false) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="btn btn-sm btn-outline-success" type="submit" title="Khôi phục" aria-label="Khôi phục mục này" data-confirm="Khôi phục mục này?">
                                                    <i class="fa-solid fa-rotate-left me-sm-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Khôi phục</span>
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ $item->item_type === 'household' ? route('utilities.trash.households.destroy', $record->id, false) : route('utilities.trash.persons.destroy', $record->id, false) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa vĩnh viễn" aria-label="Xóa vĩnh viễn mục này" data-confirm="Xóa vĩnh viễn mục này? Thao tác này không thể hoàn tác.">
                                                    <i class="fa-solid fa-trash-can me-sm-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Xóa vĩnh viễn</span>
                                                </button>
                                            </form>
                                        </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="fa-regular fa-folder-open fs-3 d-block mb-2" aria-hidden="true"></i>
                                Không có dữ liệu trong thùng rác phù hợp với bộ lọc.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($items->hasPages())
            <div class="p-3 border-top">
                {{ $items->links('vendor.pagination.bootstrap-5') }}
            </div>
        @endif
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
        min-width: 980px;
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
        color: #1e293b;
        font-weight: 700;
    }

    .trash-record-subtitle {
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

    .trash-date {
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

                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const nextResults = page.getElementById('trash-results');
                if (!nextResults) throw new Error('Không tìm thấy danh sách thùng rác trong phản hồi.');

                results.innerHTML = nextResults.innerHTML;
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
        filterForm.addEventListener('change', scheduleFilterRefresh);

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
                    province.selectedIndex = 0;
                    province.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (commune) {
                    commune.selectedIndex = 0;
                    commune.disabled = true;
                }
                if (village) {
                    village.selectedIndex = 0;
                    village.disabled = true;
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
                selectAll.indeterminate = selectedCount > 0 && selectedCount < items.length;
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
            results.querySelector('#trash-bulk-operation').value = button.dataset.trashOperation;
        });
        updateSelection();
    })();
</script>
@endpush
@endsection
