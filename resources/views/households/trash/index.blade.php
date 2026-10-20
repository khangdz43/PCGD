@extends('layouts.app')

@section('title', 'Thùng rác phiếu điều tra - PCGD-XMC')

@section('content')
<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h5 mb-1">Thùng rác - Phiếu điều tra</h1>
            <p class="small text-muted mb-0">Khôi phục dữ liệu đã xóa mềm hoặc xóa vĩnh viễn khỏi hệ thống.</p>
        </div>
        <a href="{{ route('households.index', [], false) }}" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Quay lại phiếu điều tra
        </a>
    </div>

    <form method="GET" action="{{ route('utilities.trash.index', [], false) }}" class="bg-white border rounded-2 p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4 col-xl-3">
                <label for="trash-search" class="form-label small mb-1">Tìm mã phiếu hoặc tên thành viên</label>
                <input id="trash-search" class="form-control form-control-sm" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100">
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <label for="trash-type" class="form-label small mb-1">Loại dữ liệu</label>
                <select id="trash-type" name="type" class="form-select form-select-sm">
                    <option value="all" @selected(($filters['type'] ?? 'all') === 'all')>Tất cả</option>
                    <option value="household" @selected(($filters['type'] ?? '') === 'household')>Phiếu / hộ</option>
                    <option value="person" @selected(($filters['type'] ?? '') === 'person')>Thành viên</option>
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label for="trash-deleted-from" class="form-label small mb-1">Xóa từ ngày</label>
                <input id="trash-deleted-from" class="form-control form-control-sm" type="date" name="deleted_from" value="{{ $filters['deleted_from'] ?? '' }}">
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label for="trash-deleted-to" class="form-label small mb-1">Đến ngày</label>
                <input id="trash-deleted-to" class="form-control form-control-sm" type="date" name="deleted_to" value="{{ $filters['deleted_to'] ?? '' }}">
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
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Lọc
                </button>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('utilities.trash.index', [], false) }}">Xóa lọc</a>
            </div>
        </div>
    </form>

    <div class="bg-white border rounded-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-bottom">
            <h2 class="h6 mb-0">Dữ liệu đã xóa</h2>
            <span class="badge text-bg-light border">{{ number_format($items->total()) }} mục</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
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
                            @endphp
                            <tr>
                                <td>
                                    <span class="badge {{ $item->item_type === 'household' ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                        {{ $item->item_type === 'household' ? 'Phiếu / hộ' : 'Thành viên' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($item->item_type === 'household')
                                        <div class="fw-semibold">Phiếu {{ $record->household_code }}</div>
                                        <div class="small text-muted">
                                            {{ trim($record->head_last_name.' '.$record->head_first_name) ?: 'Chưa có tên chủ hộ' }}
                                            · {{ $record->persons_count }} thành viên
                                        </div>
                                    @else
                                        <div class="fw-semibold">{{ trim($record->last_name.' '.$record->first_name) }}</div>
                                        <div class="small text-muted">Phiếu {{ $household?->household_code ?? '—' }}</div>
                                    @endif
                                </td>
                                <td>{{ $location }}{{ $household?->province ? ', '.$household->province->name : '' }}</td>
                                <td class="text-nowrap">{{ $record->deleted_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $record->deletedBy?->name ?? '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <form class="d-inline" method="POST" action="{{ $item->item_type === 'household' ? route('utilities.trash.households.restore', $record->id, false) : route('utilities.trash.persons.restore', $record->id, false) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-sm btn-outline-success" type="submit" data-confirm="Khôi phục mục này?">
                                            <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Khôi phục
                                        </button>
                                    </form>
                                    <form class="d-inline" method="POST" action="{{ $item->item_type === 'household' ? route('utilities.trash.households.destroy', $record->id, false) : route('utilities.trash.persons.destroy', $record->id, false) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Xóa vĩnh viễn mục này? Thao tác này không thể hoàn tác.">
                                            <i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>Xóa vĩnh viễn
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
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
@endsection
