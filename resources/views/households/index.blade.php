@extends('layouts.app')

@section('title', 'Phiếu điều tra hộ - PCGD-XMC')

@section('content')
<div class="container">


    <div class="household-page-heading d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Phiếu điều tra hộ</h1>
            <p class="text-muted mb-0">Một phiếu có thể gồm nhiều hộ; nhân khẩu được quản lý riêng trong từng hộ.</p>
        </div>
        <div class="household-page-actions d-flex flex-wrap gap-2">
            <!-- request()->query())  là lấy toàn bộ tham số lọc trên url truyền sang route này -->
            @can('export-households')
                <a class="btn btn-outline-success" id="export-households-button" href="{{ route('households.export', request()->query(), false) }}" data-turbo="false">
                    <i class="fa-solid fa-file-export me-1" aria-hidden="true"></i> Xuất dữ liệu
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('households.template', [], false) }}" data-turbo="false">
                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Tải mẫu Excel
                </a>
            @endcan
            @can('import-households')
                <a class="btn btn-outline-primary" href="{{ route('households.import.create', [], false) }}">
                    <i class="fa-solid fa-file-import me-1" aria-hidden="true"></i> Nhập Excel
                </a>
            @endcan
            @can('create-households')
                <a class="btn btn-primary" href="{{ route('households.create', [], false) }}">
                    <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Tạo phiếu điều tra
                </a>
            @endcan
        </div>
    </div>



    <form id="household-filter-form" method="GET" action="{{ route('households.index', [], false) }}" class="household-filter-form bg-white border rounded-2 p-2 mb-3">
        <div class="household-filter-field">
            <label for="household-search" class="visually-hidden">Mã phiếu hoặc chủ hộ</label>
            <input id="household-search" name="q" type="search" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Mã phiếu / Chủ hộ">
        </div>
        <div class="household-filter-field">
            <label for="school-year-filter" class="visually-hidden">Năm học</label>
            <select id="school-year-filter" name="school_year" class="form-select form-select-sm">
                <option value="all" @selected(request('school_year')==='all')>-- Tất cả năm học --</option>
                @php($lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1)
                @for ($year = 2020; $year <= $lastSchoolYear; $year++)
                    @php($schoolYear=$year . '-' . ($year + 1))
                    <option value="{{ $schoolYear }}" @selected(request('school_year')===$schoolYear)>{{ $schoolYear }}</option>
                    @endfor
            </select>
        </div>
        @include('partials.location-picker', [
        'pickerId' => 'household-filter-location',
        'provinces' => $provinces,
        'showVillage' => true,
        'villagePlaceholder' => $villagePlaceholder,
        'inline' => true,
        ])
        <button class="btn btn-sm btn-outline-secondary household-filter-clear" type="button" id="clear-household-filters" title="Xóa bộ lọc" aria-label="Xóa bộ lọc">
            <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i><span>Xóa lọc</span>
        </button>
        <span id="household-filter-status" class="small text-danger" role="status" aria-live="polite"></span>
    </form>

    <div id="household-results" aria-live="polite">
        @can('delete-households')
            <form id="bulk-delete-form" method="POST" action="{{ route('households.bulk-destroy', request()->query(), false) }}" class="household-bulk-actions d-flex align-items-center gap-2 mb-3" data-confirm="Xóa các phiếu đã chọn sẽ xóa cả danh sách thành viên trong hộ. Bạn có chắc không?" data-confirm-title="Xác nhận xóa phiếu">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger" type="submit" id="bulk-delete-button" disabled>
                    <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Xóa các hộ đã chọn
                </button>
            </form>
        @endcan

        <p class="household-table-hint d-md-none mb-2">
            <i class="fa-solid fa-arrows-left-right" aria-hidden="true"></i>
            Vuốt ngang bảng để xem thêm thông tin.
        </p>
        <div class="table-responsive household-table-scroll bg-white border rounded-2">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="text-center">
                            <input class="form-check-input" type="checkbox" id="select-all-households" title="Chọn tất cả hộ trên trang" aria-label="Chọn tất cả hộ trên trang">
                        </th>
                        <th scope="col">Mã phiếu / Hộ</th>
                        <th scope="col">Chủ hộ</th>
                        <th scope="col">Địa bàn</th>
                        <th scope="col">Thành viên</th>
                        <th scope="col" class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($households as $household)
                    <tr data-household-id="{{ $household->id }}">
                        <td class="text-center">
                            <input
                                class="form-check-input household-checkbox"
                                type="checkbox"
                                name="household_ids[]"
                                value="{{ $household->id }}"
                                @can('delete-households') form="bulk-delete-form" @endcan
                                aria-label="Chọn hộ {{ $household->household_code }}"
                            >
                        </td>
                        <td class="household-code-cell" title="{{ $household->household_code }}">
                            <span>{{ $household->household_code }}</span>
                        </td>
                        <td class="household-head-cell" title="{{ trim($household->head_last_name . ' ' . $household->head_first_name) ?: '—' }}">
                            {{ trim($household->head_last_name . ' ' . $household->head_first_name) ?: '—' }}
                        </td>
                        <td>{{ $household->village->name ?? $household->commune->name ?? '—' }}{{ $household->province ? ', ' . $household->province->name : '' }}</td>

                        <!-- đếm số thành viên trong hộ -->
                        <td>{{ $household->persons_count + ($household->head_persons_count === 0 ? 1 : 0) }}</td>

                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('households.show', $household, false) }}" title="Xem chi tiết hộ" aria-label="Xem chi tiết hộ {{ $household->household_code }}">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </a>
                            @can('update-households')
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('households.edit', $household, false) }}" title="Sửa hộ" aria-label="Sửa hộ {{ $household->household_code }}">
                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                </a>
                            @endcan
                            @can('delete-households')
                                <form class="d-inline" method="POST" action="{{ route('households.destroy', $household, false) }}" data-confirm="Xóa hộ này sẽ xóa danh sách nhân khẩu của hộ. Bạn có chắc không?" data-confirm-title="Xác nhận xóa hộ">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa hộ" aria-label="Xóa hộ {{ $household->household_code }}">
                                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">Chưa có phiếu điều tra phù hợp.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($households->hasPages())
            <nav class="d-flex justify-content-end mt-3" aria-label="Phân trang phiếu điều tra">
                <ul class="pagination pagination-sm mb-0 align-items-center">
                    <li class="page-item {{ $households->onFirstPage() ? 'disabled' : '' }}">
                        @if ($households->onFirstPage())
                            <span class="page-link px-3" aria-disabled="true" aria-label="Trang trước">&lsaquo;</span>
                        @else
                            <a class="page-link px-3" href="{{ $households->previousPageUrl() }}" rel="prev" aria-label="Trang trước">&lsaquo;</a>
                        @endif
                    </li>
                    <li class="page-item">
                        <form method="GET" action="{{ route('households.index', [], false) }}" class="d-flex h-100">
                            @foreach (request()->except('page') as $key => $value)
                                @if (is_scalar($value))
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endif
                            @endforeach
                            <label for="household-page-select" class="visually-hidden">Chọn trang phiếu điều tra</label>
                            <select
                                id="household-page-select"
                                name="page"
                                class="form-select form-select-sm rounded-0"
                                style="width: 4rem; min-height: 100%"
                                aria-label="Chọn trang phiếu điều tra"
                                data-household-page-select
                            >
                                @for ($page = 1; $page <= $households->lastPage(); $page++)
                                    <option value="{{ $page }}" @selected($page === $households->currentPage())>{{ $page }}</option>
                                @endfor
                            </select>
                        </form>
                    </li>
                    <li class="page-item {{ $households->hasMorePages() ? '' : 'disabled' }}">
                        @if ($households->hasMorePages())
                            <a class="page-link px-3" href="{{ $households->nextPageUrl() }}" rel="next" aria-label="Trang sau">&rsaquo;</a>
                        @else
                            <span class="page-link px-3" aria-disabled="true" aria-label="Trang sau">&rsaquo;</span>
                        @endif
                    </li>
                </ul>
            </nav>
        @endif

        <section class="member-browser mt-4">
            <div class="member-browser-heading d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div>
                    <h2 class="h5 mb-1">Đối tượng điều tra</h2>
                    <p class="small text-muted mb-0">Chọn hộ ở bảng trên để xem đầy đủ các thành viên.</p>
                </div>
                <div class="member-browser-actions d-flex align-items-center gap-2">
                    <span class="small text-muted" id="member-selection-summary">Chưa chọn hộ</span>
                    @can('delete-households')
                        <form id="bulk-member-delete-form" method="POST" action="{{ route('households.members.bulk-destroy', [], false) }}" data-ajax-bulk-member-delete data-confirm="Xóa các thành viên đã chọn?" data-confirm-title="Xác nhận xóa thành viên">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit" id="bulk-member-delete-button" disabled>
                                <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Xóa thành viên đã chọn
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
            <div class="member-table-shell bg-white border rounded-2">
                <div class="table-responsive member-table-scroll">
                    <table class="table table-hover table-bordered align-middle mb-0 member-table {{ auth()->user()->can('delete-households') ? 'member-table-has-select' : '' }}">
                        <thead class="table-light">
                            <tr>
                                @can('delete-households')
                                    <th class="text-center member-fixed-column member-sticky-column member-sticky-select" rowspan="2">Chọn</th>
                                @endcan
                                <th class="member-fixed-column member-sticky-column member-sticky-index" rowspan="2">STT</th>
                                <th class="member-group member-group-person" colspan="9">Thông tin đối tượng</th>
                                <th class="member-group member-group-education" colspan="17">Thông tin học tập</th>
                                <th class="member-group member-group-support" colspan="13">Khuyết tật và hoàn cảnh</th>
                                <th class="member-fixed-column" rowspan="2">Thao tác</th>
                            </tr>
                            <tr>
                                <th class="member-sticky-column member-sticky-name">Họ và tên</th>
                                <th>Ngày sinh</th>
                                <th>Giới tính</th>
                                <th>Dân tộc</th>
                                <th>Tôn giáo</th>
                                <th>Quan hệ</th>
                                <th>Cha/mẹ</th>
                                <th>Ưu tiên</th>
                                <th>Điện thoại</th>
                                <th>Năm học</th>
                                <th>Khối</th>
                                <th>Lớp</th>
                                <th>Trường</th>
                                <th>Bậc TN</th>
                                <th>Năm TN</th>
                                <th>Bổ túc</th>
                                <th>TN nghề</th>
                                <th>Năm TN nghề</th>
                                <th>Học xong lớp</th>
                                <th>Năm học xong</th>
                                <th>Bỏ học lớp</th>
                                <th>Năm bỏ học</th>
                                <th>Đang XMC</th>
                                <th>Hoàn thành XMC</th>
                                <th>Tái mù</th>
                                <th>Lớp XMC</th>
                                <th>Vận động</th>
                                <th>Nghe/nói</th>
                                <th>Thị giác</th>
                                <th>Tâm thần</th>
                                <th>Trí tuệ</th>
                                <th>Học tập</th>
                                <th>Tự kỷ</th>
                                <th>Khác</th>
                                <th>Giấy KT</th>
                                <th>Khả năng học</th>
                                <th>Hoàn cảnh</th>
                                <th>Chi tiết hoàn cảnh</th>
                                <th>Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody id="member-table-body">
                            @foreach ($households as $household)
                            @foreach ($household->persons as $memberIndex => $person)
                            @php($education = $person->educations->sortByDesc('school_year')->first())
                            @php($disability = $person->disability)
                            <tr class="member-row d-none {{ $person->relationship_with_head === 'Chủ hộ' ? 'member-head-row' : '' }}" data-household-id="{{ $household->id }}" tabindex="0">
                                @can('delete-households')
                                    <td class="text-center member-sticky-column member-sticky-select">
                                        @if ($person->relationship_with_head !== 'Chủ hộ')
                                        <input class="form-check-input member-checkbox" type="checkbox" name="person_ids[]" form="bulk-member-delete-form" value="{{ $person->id }}" aria-label="Chọn {{ $person->first_name }}">
                                        @endif
                                    </td>
                                @endcan
                                <td class="member-sticky-column member-sticky-index">{{ $memberIndex + 1 }}</td>
                                <td class="fw-medium text-nowrap member-sticky-column member-sticky-name">{{ trim($person->last_name . ' ' . $person->first_name) }}</td>
                                <td class="text-nowrap">{{ $person->dob?->format('d/m/Y') ?? $person->dob_str ?? '—' }}</td>
                                <td>{{ ['NAM' => 'Nam', 'NU' => 'Nữ'][$person->gender] ?? '—' }}</td>
                                <td>{{ $person->ethnicity->name ?? '—' }}</td>
                                <td>{{ $person->religion ?: '—' }}</td>
                                <td>
                                    @if ($person->relationship_with_head === 'Chủ hộ')
                                    <span class="member-head-badge">CHỦ HỘ</span>
                                    @else
                                    {{ $person->relationship_with_head ?: '—' }}
                                    @endif
                                </td>
                                <td title="{{ $person->parent_name }}">{{ $person->parent_name ?: '—' }}</td>
                                <td title="{{ $person->priority_type }}">{{ $person->priority_type ?: '—' }}</td>
                                <td>{{ $person->phone ?: '—' }}</td>
                                <td>{{ $education?->school_year ?: '—' }}</td>
                                <td>{{ $education?->academic_block ?: '—' }}</td>
                                <td>{{ $education?->current_class ?: '—' }}</td>
                                <td class="text-nowrap" title="{{ $education?->school?->name ?: $education?->school_code }}">{{ $education?->school?->name ?: $education?->school_code ?: '—' }}</td>
                                <td>{{ $education?->graduation_level ?: '—' }}</td>
                                <td>{{ $education?->graduation_year ?: '—' }}</td>
                                <td>{{ $education?->is_complementary ? 'Có' : 'Không' }}</td>
                                <td>{{ $education?->vocational_grad_level ?: '—' }}</td>
                                <td>{{ $education?->vocational_grad_year ?: '—' }}</td>
                                <td>{{ $education?->finished_class ?: '—' }}</td>
                                <td>{{ $education?->finished_year ?: '—' }}</td>
                                <td>{{ $education?->dropped_class ?: '—' }}</td>
                                <td>{{ $education?->dropped_year ?: '—' }}</td>
                                <td>{{ $education?->literacy_current_class ?: '—' }}</td>
                                <td>{{ $education?->literacy_completed_class ?: '—' }}</td>
                                <td>{{ $education?->literacy_relapse_level ?: '—' }}</td>
                                <td>{{ $education?->learning_capacity ?: '—' }}</td>
                                <td>{{ $disability?->mobility_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->hearing_speech_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->visual_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->mental_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->intellectual_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->learning_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->autism ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->other_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->has_disability_cert ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->can_study ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->special_circumstance ?: '—' }}</td>
                                <td title="{{ $disability?->special_circumstance_detail }}">{{ $disability?->special_circumstance_detail ?: '—' }}</td>
                                <td title="{{ $person->note }}">{{ $person->note ?: '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('households.members.show', [$household, $person->id], false) }}" title="Xem hồ sơ nhân khẩu" aria-label="Xem hồ sơ {{ $person->first_name }}">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </a>
                                    @can('update-households')
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('households.members.edit', [$household, $person->id], false) }}" title="Sửa thành viên" aria-label="Sửa {{ $person->first_name }}">
                                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                            @endforeach
                            <tr id="empty-member-row">
                                <td colspan="{{ auth()->user()->can('delete-households') ? 43 : 42 }}" class="text-center text-muted py-5">Chọn một hoặc nhiều hộ để xem danh sách thành viên.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </div>
</div>

@push('scripts')
<style>
    .household-filter-form {
        display: grid;
        grid-template-columns: minmax(155px, 1.5fr) minmax(120px, 1fr) repeat(3, minmax(105px, 1fr)) auto;
        align-items: center;
        gap: 0.5rem;
    }

    .household-filter-field,
    .location-picker-field {
        min-width: 0;
    }

    .location-picker-inline {
        display: contents;
    }

    .location-picker-inline .form-select {
        min-width: 0;
    }

    .location-picker-status:empty,
    #household-filter-status:empty {
        display: none;
    }

    .location-picker-status:not(:empty) {
        grid-column: 1 / -1;
    }

    .household-filter-clear {
        display: inline-flex;
        width: auto;
        min-width: 2.5rem;
        height: 2.5rem;
        align-items: center;
        justify-self: start;
        padding: .375rem .65rem;
        white-space: nowrap;
    }

    .household-filter-form > #household-filter-status:not(:empty) {
        grid-column: 1 / -1;
    }

    .household-results-loading {
        opacity: 0.55;
        pointer-events: none;
    }

    .household-checkbox,
    #select-all-households,
    .member-checkbox {
        width: 1.35rem;
        height: 1.35rem;
        cursor: pointer;
    }

    .member-table-shell {
        overflow: hidden;
    }

    .household-table-scroll {
        max-height: 24rem;
        overflow-x: auto;
        overflow-y: auto;
    }

    .household-table-scroll thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8f9fa;
        white-space: nowrap;
    }

    .member-table-scroll {
        max-height: 34rem;
        overflow-x: auto;
        overflow-y: auto;
    }

    .member-table {
        min-width: 3000px;
        font-size: 0.78rem;
        --member-select-width: 3rem;
        --member-index-width: 2.25rem;
        --member-name-width: 9rem;
    }

    .member-table th,
    .member-table td {
        padding: 0.35rem 0.45rem;
        vertical-align: middle;
    }

    .member-table thead th {
        white-space: nowrap;
        text-align: center;
        vertical-align: middle;
        border-color: #b7c4d8;
    }

    .member-table thead tr:first-child th {
        position: sticky;
        top: 0;
        z-index: 3;
        height: 2.4rem;
        color: #fff;
    }

    .member-table thead tr:first-child .member-sticky-column {
        z-index: 5;
    }

    .member-table thead tr:nth-child(2) th {
        position: sticky;
        top: 2.4rem;
        z-index: 2;
        background: #e9eef6;
        color: #1f2937;
    }

    .member-table thead tr:nth-child(2) .member-sticky-column {
        z-index: 4;
    }

    .member-table .member-fixed-column {
        background: #2f67c8;
        color: #fff;
    }

    .member-table .member-sticky-column {
        position: sticky;
        z-index: 1;
        background-color: #fff;
        background-clip: padding-box;
        transition: left .18s ease, transform .18s ease, opacity .18s ease;
    }

    .member-table .member-fixed-column.member-sticky-column {
        background-color: #2f67c8;
    }

    .member-table .member-sticky-select {
        left: 0;
        width: var(--member-select-width);
        min-width: var(--member-select-width);
        max-width: var(--member-select-width);
    }

    .member-table .member-sticky-index {
        left: var(--member-select-width);
        width: var(--member-index-width);
        min-width: var(--member-index-width);
        max-width: var(--member-index-width);
    }

    .member-table .member-sticky-name {
        left: calc(var(--member-select-width) + var(--member-index-width));
        width: var(--member-name-width);
        min-width: var(--member-name-width);
        max-width: var(--member-name-width);
        text-align: left;
    }

    .member-table tbody td.member-sticky-name {
        white-space: normal !important;
        overflow: visible;
        overflow-wrap: anywhere;
        text-overflow: clip;
    }

    .member-table:not(.member-table-has-select) .member-sticky-index {
        left: 0;
    }

    .member-table:not(.member-table-has-select) .member-sticky-name {
        left: var(--member-index-width);
    }

    .member-table.member-hide-select .member-sticky-select,
    .member-table.member-hide-index .member-sticky-index {
        transform: translateX(-105%);
        opacity: 0;
        pointer-events: none;
    }

    .member-table.member-hide-select .member-sticky-index {
        left: 0;
    }

    .member-table.member-hide-select .member-sticky-name {
        left: var(--member-index-width);
    }

    .member-table.member-hide-select.member-hide-index .member-sticky-name {
        left: 0;
    }

    .member-table .member-group-person {
        background: #7fca45;
    }

    .member-table .member-group-education {
        background: #13a85d;
    }

    .member-table .member-group-support {
        background: #f1e500;
        color: #1f2937;
    }

    @media (max-width: 575.98px) {
        .member-table {
            --member-select-width: 2.5rem;
            --member-index-width: 1.75rem;
            --member-name-width: 6rem;
            font-size: 0.72rem;
        }

        .member-table th,
        .member-table td {
            padding-right: .25rem;
            padding-left: .25rem;
        }
    }

    .member-table td {
        white-space: nowrap;
        max-width: 13rem;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .member-table tbody tr.member-head-row>td {
        background: #fff3b0 !important;
        border-top: 2px solid #e1b400;
        border-bottom: 2px solid #e1b400;
        font-weight: 600;
    }

    .member-table tbody tr.member-head-row>td:first-child {
        border-left: 4px solid #e1a900;
    }

    .member-table tbody tr.member-group-hover>td {
        background: #e8f3ff !important;
        box-shadow: inset 0 1px 0 #b7d7f5, inset 0 -1px 0 #b7d7f5;
    }

    .member-table tbody tr.member-row.is-row-marked>td {
        background: #cfe8ff !important;
        box-shadow: inset 0 1px 0 #5b9bd5, inset 0 -1px 0 #5b9bd5;
    }

    .member-table tbody tr.member-row.is-row-marked>td:first-child {
        border-left: 4px solid #2878bd;
    }

    .member-head-badge {
        display: inline-block;
        padding: 0.18rem 0.4rem;
        border-radius: 0.2rem;
        background: #e1a900;
        color: #332500;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.02em;
    }

    @media (max-width: 991.98px) {
        .household-filter-form {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 575.98px) {
        .household-filter-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .6rem;
            padding: .75rem !important;
        }

        .household-filter-field:first-child {
            grid-column: 1 / -1;
        }

        .household-filter-form .location-picker-field {
            grid-column: 1 / -1;
        }

        .household-filter-form .location-picker-field .form-select,
        .household-filter-form .household-filter-field .form-control,
        .household-filter-form .household-filter-field .form-select {
            min-height: 2.75rem;
            font-size: .95rem;
        }

        .household-filter-form .household-filter-clear {
            grid-column: 1 / -1;
            width: 100%;
            height: 2.75rem;
            justify-self: stretch;
            padding: .375rem .75rem;
        }

        .household-filter-form .location-picker-status,
        .household-filter-form #household-filter-status {
            grid-column: 1 / -1;
        }

        .household-page-heading {
            align-items: stretch !important;
            gap: .85rem !important;
            margin-bottom: 1rem !important;
        }

        .household-page-heading h1 {
            font-size: 1.35rem;
        }

        .household-page-actions {
            display: grid !important;
            width: 100%;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .household-page-actions .btn {
            min-height: 2.75rem;
            padding: .5rem .55rem;
            white-space: normal;
        }

        .household-page-actions .btn:last-child:nth-child(odd) {
            grid-column: 1 / -1;
        }
    }
</style>
<script>
    (() => {
    const filterForm = document.getElementById('household-filter-form');
    const results = document.getElementById('household-results');
    const filterStatus = document.getElementById('household-filter-status');
    const clearFiltersButton = document.getElementById('clear-household-filters');
    const exportButton = document.getElementById('export-households-button');
    let filterTimer;
    let activeRequest;

    function updateMemberTable() {
        const selectedHouseholdIds = new Set(
            [...results.querySelectorAll('.household-checkbox:checked')].map((checkbox) => checkbox.value),
        );
        const memberRows = results.querySelectorAll('.member-row');
        let visibleMemberCount = 0;

        memberRows.forEach((row) => {
            const isVisible = selectedHouseholdIds.has(row.dataset.householdId);
            row.classList.toggle('d-none', !isVisible);
            visibleMemberCount += isVisible ? 1 : 0;
            if (!isVisible) {
                const checkbox = row.querySelector('.member-checkbox');
                if (checkbox) {
                    checkbox.checked = false;
                }
                row.classList.remove('is-row-marked');
                row.removeAttribute('aria-selected');
            }
        });

        const emptyMemberRow = document.getElementById('empty-member-row');
        const memberSelectionSummary = document.getElementById('member-selection-summary');
        if (emptyMemberRow) emptyMemberRow.classList.toggle('d-none', visibleMemberCount > 0);
        if (memberSelectionSummary) memberSelectionSummary.textContent = selectedHouseholdIds.size ?
            `${selectedHouseholdIds.size} hộ · ${visibleMemberCount} thành viên` :
            'Chưa chọn hộ';
        updateBulkMemberDeleteState();
    }

    function updateBulkDeleteState() {
        const button = document.getElementById('bulk-delete-button');
        if (button) button.disabled = !results.querySelector('.household-checkbox:checked');
    }

    function updateBulkMemberDeleteState() {
        const button = document.getElementById('bulk-member-delete-button');
        if (button) button.disabled = !results.querySelector('.member-checkbox:checked');
    }

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax-bulk-member-delete')) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        if (!await window.appConfirm(form.dataset.confirm, {
            title: form.dataset.confirmTitle,
            confirmText: 'Xóa thành viên',
        })) return;

        const button = form.querySelector('button[type="submit"]');
        const personIds = new FormData(form).getAll('person_ids[]').map(Number);
        if (!button || personIds.length === 0) return;
        button.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                },
                body: JSON.stringify({ person_ids: personIds }),
                credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Không thể xóa thành viên.');

            const deletedIds = new Set(result.deleted_ids.map(String));
            results.querySelectorAll('.member-row').forEach((row) => {
                if (deletedIds.has(row.querySelector('.member-checkbox')?.value)) row.remove();
            });
            updateMemberTable();
            window.showAppNotification('success', result.message);
        } catch (error) {
            button.disabled = false;
            window.showAppNotification('error', error.message || 'Không thể xóa thành viên.');
        }
    }, true);

    function filterUrl() {
        const url = new URL(filterForm.action, window.location.href);
        url.search = '';
        new URLSearchParams(new FormData(filterForm)).forEach((value, key) => {
            if (value) url.searchParams.set(key, value);
        });
        return url;
    }

    function updateClearButton() {
        clearFiltersButton.disabled = ![...new FormData(filterForm).values()].some((value) => String(value).trim());
    }

    async function refreshHouseholds(url = filterUrl(), historyMode = 'replaceState') {
        activeRequest?.abort();
        const requestController = new AbortController();
        activeRequest = requestController;
        results.setAttribute('aria-busy', 'true');
        results.classList.add('household-results-loading');
        filterStatus.textContent = '';

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: requestController.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextResults = page.getElementById('household-results');
            if (!nextResults) throw new Error('Household results were not found in the response.');

            results.innerHTML = nextResults.innerHTML;
            if (historyMode !== 'none') {
                window.history[historyMode]({}, '', `${url.pathname}${url.search}`);
            }
            if (exportButton) {
                const exportUrl = new URL(exportButton.href, window.location.href);
                exportUrl.search = url.search;
                exportUrl.searchParams.delete('page');
                exportButton.href = `${exportUrl.pathname}${exportUrl.search}`;
            }
            updateClearButton();
            updateBulkDeleteState();
            updateMemberTable();
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Không tải được danh sách hộ:', error);
                filterStatus.textContent = 'Không tải được kết quả lọc. Vui lòng thử lại.';
            }
        } finally {
            if (activeRequest === requestController) {
                activeRequest = null;
                results.removeAttribute('aria-busy');
                results.classList.remove('household-results-loading');
            }
        }
    }

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshHouseholds();
    });

    filterForm.addEventListener('input', (event) => {
        if (event.target.name !== 'q') return;
        clearTimeout(filterTimer);
        filterTimer = setTimeout(() => refreshHouseholds(), 300);
        updateClearButton();
    });

    filterForm.addEventListener('change', (event) => {
        if (event.target.matches('select')) refreshHouseholds();
        updateClearButton();
    });

    clearFiltersButton.addEventListener('click', () => {
        clearTimeout(filterTimer);
        filterForm.querySelectorAll('input[name], select[name]').forEach((field) => {
            field.value = '';
        });
        filterForm.querySelector('[data-province]')?.dispatchEvent(new Event('change'));
        updateClearButton();
        refreshHouseholds(new URL(filterForm.action, window.location.href));
    });

    results.addEventListener('change', (event) => {
        if (event.target.matches('[data-household-page-select]')) {
            const pageUrl = new URL(event.target.form.action, window.location.href);
            pageUrl.search = new URLSearchParams(new FormData(event.target.form)).toString();
            refreshHouseholds(pageUrl, 'pushState');
        } else if (event.target.id === 'select-all-households') {
            results.querySelectorAll('.household-checkbox').forEach((checkbox) => {
                checkbox.checked = event.target.checked;
            });
            updateBulkDeleteState();
            updateMemberTable();
        } else if (event.target.matches('.household-checkbox')) {
            updateBulkDeleteState();
            updateMemberTable();
        } else if (event.target.matches('.member-checkbox')) {
            updateBulkMemberDeleteState();
        }
    });

    results.addEventListener('click', (event) => {
        const link = event.target.closest('nav[aria-label="Phân trang phiếu điều tra"] a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        refreshHouseholds(new URL(link.href, window.location.href), 'pushState');
    });

    window.addEventListener('popstate', () => {
        refreshHouseholds(new URL(window.location.href), 'none');
    });

    let lastMemberScrollLeft = 0;
    let memberScrollDirection = 0;
    let memberScrollDistance = 0;
    let hiddenMemberColumns = 0;

    results.addEventListener('scroll', (event) => {
        const scroller = event.target;
        if (!(scroller instanceof HTMLElement) || !scroller.matches('.member-table-scroll')) return;

        const table = scroller.querySelector('.member-table');
        if (!table) return;

        const scrollLeft = scroller.scrollLeft;
        const delta = scrollLeft - lastMemberScrollLeft;
        lastMemberScrollLeft = scrollLeft;

        if (scrollLeft === 0) {
            hiddenMemberColumns = 0;
            memberScrollDistance = 0;
            memberScrollDirection = 0;
        } else if (delta !== 0) {
            const direction = Math.sign(delta);
            if (direction !== memberScrollDirection) {
                memberScrollDirection = direction;
                memberScrollDistance = 0;
            }

            memberScrollDistance += Math.abs(delta);
            const columnSteps = Math.floor(memberScrollDistance / 18);
            if (columnSteps > 0) {
                hiddenMemberColumns = Math.max(
                    0,
                    Math.min(2, hiddenMemberColumns + columnSteps * direction),
                );
                memberScrollDistance %= 18;
            }
        }

        table.classList.toggle('member-hide-select', hiddenMemberColumns >= 1);
        table.classList.toggle('member-hide-index', hiddenMemberColumns >= 2);
    }, true);

    function markMemberRow(row) {
        results.querySelectorAll('.member-row.is-row-marked').forEach((markedRow) => {
            markedRow.classList.remove('is-row-marked');
            markedRow.removeAttribute('aria-selected');
        });
        row.classList.add('is-row-marked');
        row.setAttribute('aria-selected', 'true');
    }

    results.addEventListener('click', (event) => {
        const row = event.target.closest('.member-row');
        if (!row || event.target.closest('a, button, input, select, textarea, label')) return;

        markMemberRow(row);
    });

    results.addEventListener('keydown', (event) => {
        if (!['Enter', ' '].includes(event.key)) return;
        const row = event.target.closest('.member-row');
        if (!row || event.target !== row) return;

        event.preventDefault();
        markMemberRow(row);
    });

    results.addEventListener('mouseover', (event) => {
        const headRow = event.target.closest('.member-head-row');
        if (!headRow || headRow.contains(event.relatedTarget)) return;

        results.querySelectorAll('.member-row').forEach((row) => {
            if (row.dataset.householdId === headRow.dataset.householdId && !row.classList.contains('member-head-row')) {
                row.classList.add('member-group-hover');
            }
        });
    });

    results.addEventListener('mouseout', (event) => {
        if (event.target.closest('.member-head-row') && !event.target.closest('.member-head-row').contains(event.relatedTarget)) {
            results.querySelectorAll('.member-group-hover').forEach((row) => row.classList.remove('member-group-hover'));
        }
    });

    exportButton?.addEventListener('click', async (event) => {
        event.preventDefault();

        const selectedHouseholds = results.querySelectorAll('.household-checkbox:checked');
        const exportButton = event.currentTarget;

        if (exportButton.dataset.exporting === 'true') {
            return;
        }

        const exportUrl = new URL(exportButton.href, window.location.origin);
        exportUrl.searchParams.delete('household_ids[]');
        selectedHouseholds.forEach((checkbox) => {
            exportUrl.searchParams.append('household_ids[]', checkbox.value);
        });

        exportButton.dataset.exporting = 'true';
        exportButton.setAttribute('aria-busy', 'true');
        exportButton.classList.add('disabled');
        exportButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Đang xuất...';

        try {
            const response = await fetch(exportUrl, {
                headers: {
                    Accept: 'application/vnd.ms-excel'
                }
            });
            if (!response.ok) {
                throw new Error(`Export failed with status ${response.status}`);
            }

            const blob = await response.blob();
            const downloadUrl = URL.createObjectURL(blob);
            const downloadLink = document.createElement('a');
            downloadLink.href = downloadUrl;
            downloadLink.download = 'phieu-dieu-tra.xls';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            downloadLink.remove();
            URL.revokeObjectURL(downloadUrl);

            window.showAppNotification('success', 'Đã xuất dữ liệu phiếu điều tra.');
            exportButton.classList.remove('disabled');
            exportButton.removeAttribute('aria-busy');
        } catch (error) {
            console.error(error);
            window.showAppNotification('error', 'Không thể xuất dữ liệu. Vui lòng thử lại.');
            exportButton.classList.remove('disabled');
            exportButton.removeAttribute('aria-busy');
        } finally {
            exportButton.innerHTML = '<i class="fa-solid fa-file-export me-1" aria-hidden="true"></i> Xuất dữ liệu';
            exportButton.dataset.exporting = 'false';
        }
    });

    updateClearButton();
    })();
</script>
@endpush
@endsection