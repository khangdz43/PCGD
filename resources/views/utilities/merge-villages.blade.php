@extends('layouts.app')

@section('title', 'Gộp thôn - PCGD-XMC')

@section('content')
<div class="container merge-villages-page">
    @if ($villages->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body py-5 text-center">
                <i class="fa-solid fa-map-location-dot text-muted fs-2 mb-3" aria-hidden="true"></i>
                <h2 class="h5">Chưa có thôn trong địa bàn quản lý</h2>
                <p class="text-muted mb-0">Danh sách thôn sẽ hiển thị tại đây khi địa bàn có dữ liệu.</p>
            </div>
        </div>
    @else
        <form class="row g-2 align-items-stretch merge-workspace" id="merge-villages-form"
            method="POST" action="{{ route('utilities.villages.merge.store') }}"
            data-confirm="Các thôn nguồn sẽ bị xóa khỏi danh mục. Toàn bộ hộ và nhân khẩu được chuyển sang thôn đích. Bạn có chắc chắn muốn tiếp tục?"
            data-confirm-title="Xác nhận gộp thôn"
            data-confirm-button="Tiến hành gộp">
            @csrf
            <div class="col-12 col-xl-7 merge-source-column">
                <section class="card merge-card shadow-sm">
                    <div class="card-header bg-white">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <h2 class="h5 mb-1">1. Chọn thôn nguồn</h2>
                                <p class="small text-muted mb-0">Có thể chọn nhiều thôn cần gộp.</p>
                            </div>
                            <span class="badge text-bg-light border" id="merge-source-count" aria-live="polite">Đã chọn 0</span>
                        </div>
                        <div class="input-group input-group-sm mt-3">
                            <span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
                            <label class="visually-hidden" for="merge-village-search">Tìm thôn nguồn</label>
                            <input id="merge-village-search" class="form-control" type="search" placeholder="Tìm theo mã hoặc tên thôn">
                            <button class="btn btn-outline-secondary" type="button" id="merge-select-visible">Chọn kết quả</button>
                        </div>
                    </div>
                    <div class="merge-source-list">
                        @foreach ($villages->groupBy(fn ($village) => $village->commune_code) as $communeCode => $communeVillages)
                            @php
                                $commune = $communeVillages->first()->commune;
                                $locationLabel = ($commune->province->name ?? '') . ' · ' . ($commune->name ?? $communeCode);
                            @endphp
                            <div class="merge-commune-group">
                                <div class="merge-commune-heading">
                                    <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                    <span>{{ $locationLabel }}</span>
                                </div>
                                @foreach ($communeVillages as $village)
                                    <label class="merge-village-option"
                                        data-search="{{ mb_strtolower($village->code . ' ' . $village->name . ' ' . $locationLabel) }}">
                                        <input class="form-check-input merge-source-checkbox" type="checkbox"
                                            value="{{ $village->code }}"
                                            name="source_codes[]"
                                            @checked(in_array($village->code, (array) old('source_codes', []), true))
                                            data-name="{{ $village->name }}"
                                            data-commune="{{ $commune->name ?? $communeCode }}"
                                            data-households="{{ $village->households_count }}">
                                        <span class="merge-village-details">
                                            <span class="merge-village-name">{{ $village->name }}</span>
                                            <span class="merge-village-meta">Mã {{ $village->code }} · {{ number_format($village->households_count) }} phiếu</span>
                                        </span>
                                        <span class="badge rounded-pill text-bg-light border">Nguồn</span>
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                        <p class="merge-no-results text-muted text-center py-4 mb-0 d-none" id="merge-no-results">Không tìm thấy thôn phù hợp.</p>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-5 merge-summary-column">
                <section class="card merge-card shadow-sm">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-1">2. Chọn thôn đích</h2>
                        <p class="small text-muted mb-0">Toàn bộ dữ liệu nguồn sẽ thuộc về thôn đích sau khi triển khai.</p>
                    </div>
                    <div class="card-body">
                        <label class="form-label fw-semibold" for="merge-target">Thôn đích <span class="text-danger">*</span></label>
                        <select class="form-select" id="merge-target" name="target_code" required>
                            <option value="">-- Chọn thôn nhận dữ liệu --</option>
                            @foreach ($villages as $village)
                                <option value="{{ $village->code }}" @selected(old('target_code') === $village->code) data-name="{{ $village->name }}" data-commune="{{ $village->commune->name ?? $village->commune_code }}">
                                    {{ $village->name }} · {{ $village->commune->name ?? $village->commune_code }} ({{ $village->code }})
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Thôn đích không thể đồng thời là thôn nguồn.</div>

                        <div class="merge-target-info mt-3">
                            <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
                            <p class="small mb-0">Thôn đích phải là thôn đang tồn tại trong địa bàn được phép quản lý.</p>
                        </div>
                    </div>
                </section>

                <section class="card merge-card shadow-sm mt-2" aria-labelledby="merge-preview-title">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0" id="merge-preview-title">3. Xem trước</h2>
                    </div>
                    <div class="card-body">
                        <div id="merge-preview" aria-live="polite">
                            <div class="merge-preview-flow">
                                <div class="merge-preview-stage">
                                    <div class="merge-preview-stage-heading">
                                        <span class="merge-preview-step"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i></span>
                                        <span>Thôn nguồn</span>
                                        <span class="merge-preview-count" id="merge-preview-source-count">0 thôn</span>
                                    </div>
                                    <ul class="merge-preview-sources list-unstyled mb-0" id="merge-preview-sources"></ul>
                                </div>
                                <div class="merge-preview-connector" aria-hidden="true">
                                    <span></span>
                                    <i class="fa-solid fa-arrow-down"></i>
                                    <span></span>
                                </div>
                                <div class="merge-preview-stage merge-preview-destination">
                                    <div class="merge-preview-stage-heading">
                                        <span class="merge-preview-step"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                                        <span>Thôn đích</span>
                                        <span class="merge-preview-destination-badge">Nhận dữ liệu</span>
                                    </div>
                                    <strong class="merge-preview-target" id="merge-preview-target">Chưa chọn thôn đích</strong>
                                    <span class="merge-preview-target-meta" id="merge-preview-target-meta">Chọn một thôn đích ở danh sách bên cạnh</span>
                                </div>
                            </div>
                            <div class="merge-preview-total">
                                <span class="merge-preview-total-icon"><i class="fa-solid fa-file-lines" aria-hidden="true"></i></span>
                                <span>Phiếu thuộc các thôn nguồn</span>
                                <strong id="merge-preview-households">0 phiếu</strong>
                            </div>
                            <p class="merge-preview-empty small text-muted mt-2 mb-0" id="merge-preview-empty">Chọn thôn nguồn và thôn đích để xem luồng gộp.</p>
                        </div>
                        <div class="merge-preview-actions">
                            <button class="btn btn-primary w-100 mt-3" id="merge-submit-button" type="submit" disabled>
                                <i class="fa-solid fa-code-merge me-1" aria-hidden="true"></i>Tiến hành gộp
                            </button>
                            <p class="small text-muted text-center mt-2 mb-0" id="merge-action-status" role="status" aria-live="polite"></p>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<style>
    .merge-villages-page {
        display: flex;
        flex-direction: column;
        gap: .65rem;
        padding-top: .4rem;
        padding-bottom: .5rem;
    }

    .merge-workspace {
        min-height: 0;
    }

    .merge-card {
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: .7rem;
    }

    .merge-card .card-header {
        padding: .65rem .8rem;
        border-bottom-color: #e2e8f0;
    }

    .merge-card .card-header h2 {
        font-size: 1rem;
    }

    .merge-card .card-header p {
        font-size: .75rem;
    }

    .merge-card .card-body {
        padding: .7rem .8rem;
    }

    .merge-card .form-select {
        min-height: 2.25rem;
        padding-top: .35rem;
        padding-bottom: .35rem;
        font-size: .85rem;
    }

    .merge-card .form-text {
        font-size: .7rem;
    }

    .merge-card .btn {
        min-height: 2.1rem;
        padding-top: .35rem;
        padding-bottom: .35rem;
        font-size: .8rem;
    }

    .merge-source-list {
        max-height: min(17rem, 45vh);
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .merge-source-column > .merge-card {
        align-self: flex-start;
        width: 100%;
    }

    .merge-commune-group + .merge-commune-group {
        border-top: 1px solid #e2e8f0;
    }

    .merge-commune-heading {
        display: flex;
        align-items: center;
        gap: .4rem;
        padding: .4rem .8rem;
        background: #f8fafc;
        color: #475569;
        font-size: .72rem;
        font-weight: 700;
    }

    .merge-village-option {
        display: flex;
        align-items: center;
        gap: .55rem;
        min-height: 3rem;
        padding: .35rem .8rem;
        border-top: 1px solid #f1f5f9;
        cursor: pointer;
    }

    .merge-village-option:hover {
        background: #f8fafc;
    }

    .merge-village-option.d-none {
        display: none !important;
    }

    .merge-village-option .form-check-input {
        width: 1rem;
        height: 1rem;
        margin: 0;
        flex: 0 0 auto;
    }

    .merge-village-details {
        display: grid;
        min-width: 0;
        flex: 1 1 auto;
    }

    .merge-village-name {
        overflow-wrap: anywhere;
        color: #1e293b;
        font-size: .85rem;
        font-weight: 600;
    }

    .merge-village-meta {
        color: #64748b;
        font-size: .68rem;
    }

    .merge-target-info {
        display: flex;
        gap: .45rem;
        padding: .5rem;
        border-radius: .5rem;
        background: #f1f5f9;
        color: #475569;
        font-size: .75rem;
    }

    .merge-preview-flow {
        display: flex;
        flex-direction: column;
        gap: .35rem;
    }

    .merge-preview-stage {
        min-width: 0;
        padding: .65rem;
        border: 1px solid #dbeafe;
        border-radius: .6rem;
        background: #f8fbff;
    }

    .merge-preview-stage-heading {
        display: flex;
        align-items: center;
        gap: .4rem;
        margin-bottom: .45rem;
        color: #475569;
        font-size: .75rem;
        font-weight: 700;
    }

    .merge-preview-step,
    .merge-preview-total-icon {
        display: inline-grid;
        width: 1.6rem;
        height: 1.6rem;
        flex: 0 0 1.6rem;
        place-items: center;
        border-radius: .45rem;
        background: #dbeafe;
        color: #1d4ed8;
    }

    .merge-preview-sources {
        display: grid;
        height: 10rem;
        flex: 0 0 10rem;
        gap: .3rem;
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .merge-preview-sources li {
        display: flex;
        align-items: center;
        gap: .4rem;
        min-width: 0;
        padding: .3rem .45rem;
        border: 1px solid #e2e8f0;
        border-radius: .4rem;
        background: #fff;
        color: #334155;
        font-size: .7rem;
    }

    .merge-preview-sources li::before {
        color: #64748b;
        content: "\f1ad";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
    }

    .merge-preview-count {
        margin-left: auto;
        padding: .15rem .4rem;
        border-radius: 2rem;
        background: #e2e8f0;
        color: #334155;
        font-size: .65rem;
        white-space: nowrap;
    }

    .merge-preview-connector {
        display: flex;
        height: 1.25rem;
        align-items: center;
        justify-content: center;
        gap: .3rem;
        color: #2563eb;
    }

    .merge-preview-connector span {
        width: 1.1rem;
        height: 1px;
        background: #93c5fd;
    }

    .merge-preview-connector i {
        font-size: .8rem;
    }

    .merge-preview-destination {
        border-color: #a7f3d0;
        background: #f0fdf4;
    }

    .merge-preview-destination .merge-preview-step {
        background: #dcfce7;
        color: #15803d;
    }

    .merge-preview-destination-badge {
        margin-left: auto;
        padding: .15rem .4rem;
        border-radius: 2rem;
        background: #dcfce7;
        color: #166534;
        font-size: .62rem;
        white-space: nowrap;
    }

    .merge-preview-target,
    .merge-preview-target-meta {
        display: block;
        overflow-wrap: anywhere;
    }

    .merge-preview-target {
        color: #166534;
        font-size: .9rem;
    }

    .merge-preview-target-meta {
        margin-top: .15rem;
        color: #64748b;
        font-size: .68rem;
    }

    .merge-preview-total {
        display: flex;
        align-items: center;
        gap: .45rem;
        margin-top: .65rem;
        padding: .5rem .6rem;
        border-radius: .5rem;
        background: #f1f5f9;
        color: #475569;
        font-size: .72rem;
    }

    .merge-preview-total strong {
        margin-left: auto;
        color: #0f172a;
        white-space: nowrap;
    }

    .merge-preview-total-icon {
        background: #e2e8f0;
        color: #475569;
    }

    #merge-submit-button {
        min-height: 2.5rem;
        font-weight: 700;
    }

    .merge-preview-actions {
        flex: 0 0 auto;
        padding-top: .35rem;
        border-top: 1px solid #e2e8f0;
    }

    .merge-preview-actions .btn {
        margin-top: .35rem !important;
    }

    #merge-action-status {
        min-height: 1.25rem;
    }

    @media (min-width: 992px) {
        .merge-villages-page {
            height: calc(100dvh - 10.75rem);
            min-height: 0;
        }

        .merge-workspace {
            display: grid;
            flex: 1 1 auto;
            grid-template-columns: minmax(17rem, .65fr) minmax(0, 1.35fr);
            margin: 0;
        }

        .merge-workspace > .merge-source-column {
            display: flex;
            min-width: 0;
            min-height: 0;
            flex-direction: column;
            width: auto;
            padding: 0;
        }

        .merge-workspace > .merge-summary-column {
            display: grid;
            min-width: 0;
            min-height: 0;
            grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr);
            align-items: start;
            gap: .5rem;
            width: auto;
            padding: 0;
        }

        .merge-summary-column > .merge-card {
            min-width: 0;
            margin: 0 !important;
        }

        .merge-summary-column > .merge-card[aria-labelledby="merge-preview-title"] {
            display: flex;
            min-height: 0;
            height: 100%;
            flex-direction: column;
        }

        .merge-summary-column > .merge-card[aria-labelledby="merge-preview-title"] > .card-body {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .merge-summary-column > .merge-card[aria-labelledby="merge-preview-title"] > .card-body > #merge-preview {
            min-height: 0;
            flex: 1 1 auto;
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-width: none;
        }

        .merge-summary-column > .merge-card[aria-labelledby="merge-preview-title"] > .card-body > #merge-preview::-webkit-scrollbar {
            display: none;
        }

        .merge-preview-actions {
            width: 100%;
        }

        .merge-preview-sources {
            max-height: 10rem;
        }

    }

    @media (max-width: 991.98px) {
        .merge-villages-page {
            min-height: calc(100dvh - 9rem);
        }

        .merge-workspace {
            row-gap: .5rem;
        }

    }

    @media (max-width: 575.98px) {
        .merge-villages-page {
            min-height: calc(100dvh - 8rem);
        }

    }

    @media (min-width: 992px) and (max-height: 700px) {
        .merge-villages-page {
            height: calc(100dvh - 9.5rem);
            min-height: 0;
            gap: .4rem;
        }

        .merge-card .card-header {
            padding: .5rem .7rem;
        }

        .merge-card .card-body {
            padding: .5rem .7rem;
        }

        .merge-village-option {
            min-height: 2.6rem;
            padding-top: .2rem;
            padding-bottom: .2rem;
        }
    }
</style>
<script>
    (() => {
        const sourceCheckboxes = [...document.querySelectorAll('.merge-source-checkbox')];
        const sourceCount = document.getElementById('merge-source-count');
        const targetSelect = document.getElementById('merge-target');
        const searchInput = document.getElementById('merge-village-search');
        const selectVisibleButton = document.getElementById('merge-select-visible');
        const previewEmpty = document.getElementById('merge-preview-empty');
        const mergeButton = document.getElementById('merge-submit-button');
        const mergeActionStatus = document.getElementById('merge-action-status');

        if (!sourceCheckboxes.length || !targetSelect || !mergeButton) return;

        const selectedSources = () => sourceCheckboxes.filter((checkbox) => checkbox.checked);

        function refresh() {
            const selectedCodes = new Set(selectedSources().map((checkbox) => checkbox.value));
            if (selectedCodes.has(targetSelect.value)) {
                targetSelect.value = '';
            }

            [...targetSelect.options].forEach((option) => {
                option.hidden = option.value !== '' && selectedCodes.has(option.value);
            });

            const targetCode = targetSelect.value;
            const targetOption = targetSelect.selectedOptions[0];

            sourceCheckboxes.forEach((checkbox) => {
                const isTarget = checkbox.value === targetCode;
                checkbox.disabled = isTarget;
                checkbox.closest('.merge-village-option').classList.toggle('opacity-50', isTarget);
            });
            const selected = selectedSources();
            sourceCount.textContent = `Đã chọn ${selected.length}`;
            const householdCount = selected.reduce(
                (total, checkbox) => total + Number(checkbox.dataset.households || 0),
                0
            );
            document.getElementById('merge-preview-source-count').textContent = `${selected.length} thôn`;
            document.getElementById('merge-preview-target').textContent = targetOption?.dataset.name || 'Chưa chọn thôn đích';
            document.getElementById('merge-preview-target-meta').textContent = targetCode
                ? `${targetOption.dataset.commune} · Mã ${targetCode}`
                : 'Chọn một thôn đích ở danh sách bên cạnh';
            document.getElementById('merge-preview-households').textContent = `${householdCount.toLocaleString('vi-VN')} phiếu`;
            previewEmpty.classList.toggle('d-none', selected.length > 0 && Boolean(targetCode));
            if (selected.length === 0 && targetCode) {
                previewEmpty.textContent = 'Chọn ít nhất một thôn nguồn để bắt đầu gộp.';
            } else if (selected.length > 0 && !targetCode) {
                previewEmpty.textContent = 'Chọn thôn đích để hoàn tất sơ đồ gộp.';
            } else {
                previewEmpty.textContent = 'Chọn thôn nguồn và thôn đích để xem luồng gộp.';
            }
            mergeButton.disabled = selected.length === 0 || !targetCode;
            mergeActionStatus.textContent = '';

            const sourceList = document.getElementById('merge-preview-sources');
            const selectedItems = selected.map((checkbox) => {
                const item = document.createElement('li');
                item.textContent = `${checkbox.dataset.name} · ${checkbox.dataset.commune} (${checkbox.value})`;
                return item;
            });
            if (!selectedItems.length) {
                const emptyItem = document.createElement('li');
                emptyItem.className = 'text-muted';
                emptyItem.textContent = 'Chưa chọn thôn nguồn';
                selectedItems.push(emptyItem);
            }
            sourceList.replaceChildren(...selectedItems);
        }

        function filterSources() {
            const search = searchInput.value.trim().toLocaleLowerCase('vi');
            let visibleCount = 0;

            document.querySelectorAll('.merge-village-option').forEach((option) => {
                const matches = option.dataset.search.includes(search);
                option.classList.toggle('d-none', !matches);
                if (matches) visibleCount += 1;
            });
            document.querySelectorAll('.merge-commune-group').forEach((group) => {
                const hasVisible = [...group.querySelectorAll('.merge-village-option')]
                    .some((option) => !option.classList.contains('d-none'));
                group.classList.toggle('d-none', !hasVisible);
            });
            document.getElementById('merge-no-results').classList.toggle('d-none', visibleCount > 0);
        }

        searchInput.addEventListener('input', filterSources);
        sourceCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', refresh));
        targetSelect.addEventListener('change', refresh);
        selectVisibleButton.addEventListener('click', () => {
            document.querySelectorAll('.merge-village-option:not(.d-none) .merge-source-checkbox:not(:disabled)')
                .forEach((checkbox) => { checkbox.checked = true; });
            refresh();
        });
        refresh();
    })();
</script>
@endpush
