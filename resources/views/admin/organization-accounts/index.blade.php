@extends('layouts.app')

@section('title', 'Cây đơn vị và thống kê tài khoản')

@push('styles')
<style>
    .organization-tree {
        max-height: 72vh;
        overflow-y: auto;
    }

    .organization-tree details {
        margin: .2rem 0;
    }

    .organization-tree summary {
        display: flex;
        align-items: center;
        gap: .45rem;
        padding: .5rem .6rem;
        border-radius: .4rem;
        color: #334155;
        cursor: pointer;
        list-style: none;
    }

    .organization-tree summary::-webkit-details-marker {
        display: none;
    }

    .organization-tree summary::before {
        width: 1rem;
        content: '\f0da';
        color: #64748b;
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        transition: transform .15s;
    }

    .organization-tree details[open] > summary::before {
        transform: rotate(90deg);
    }

    .organization-tree .unit-link {
        display: flex;
        align-items: center;
        gap: .5rem;
        min-width: 0;
        padding: .5rem .6rem;
        border-radius: .4rem;
        color: #334155;
        text-decoration: none;
    }

    .organization-tree .unit-link:hover,
    .organization-tree .unit-link.active {
        background: #e7f1ff;
        color: #084298;
    }

    .organization-tree .unit-link.active {
        font-weight: 700;
    }

    .organization-tree .unit-name {
        flex: 1;
        min-width: 0;
    }

    .organization-tree .unit-children {
        margin-left: .7rem;
        padding-left: .65rem;
        border-left: 1px solid #dbe3ed;
    }

    .organization-tree .commune-unit {
        margin-left: .8rem;
    }

    .organization-tree .village-unit {
        margin-left: .8rem;
    }

    .account-stat {
        height: 100%;
    }
</style>
@endpush

@section('content')
<div class="container">
    <div class="mb-4">
        <h1 class="h3 mb-1"><i class="fa-solid fa-sitemap text-primary me-2"></i>Cây đơn vị và thống kê tài khoản</h1>
        <p class="text-muted mb-0">Theo dõi tài khoản theo địa bàn từ tỉnh đến xã, thôn.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card account-stat border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Tài khoản có địa bàn</div>
                    <div class="fs-3 fw-bold">{{ number_format($totalAccounts) }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card account-stat border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Đang hoạt động</div>
                    <div class="fs-3 fw-bold text-success">{{ number_format($activeAccounts) }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card account-stat border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Đã khóa</div>
                    <div class="fs-3 fw-bold text-danger">{{ number_format($inactiveAccounts) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4 col-xl-3">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa-solid fa-diagram-project me-2 text-primary"></i>Đơn vị hành chính
                </div>
                <div class="card-body p-2 organization-tree">
                    @forelse($provinces as $province)
                        @php
                            $provinceSelected = $selectedType === 'province' && $selectedCode === $province->code;
                            $provinceExpanded = $provinceSelected || ($selectedUnit instanceof \App\Models\Commune && $selectedUnit->province_code === $province->code)
                                || ($selectedUnit instanceof \App\Models\Village && $selectedUnit->commune?->province_code === $province->code);
                        @endphp
                        <details {{ $provinceExpanded ? 'open' : '' }}>
                            <summary>
                                <a class="unit-link {{ $provinceSelected ? 'active' : '' }} flex-grow-1"
                                   href="{{ route('admin.organization-accounts.index', ['unit_type' => 'province', 'unit_code' => $province->code]) }}">
                                    <i class="fa-solid fa-city text-primary"></i>
                                    <span class="unit-name">{{ $province->name }}</span>
                                    <span class="badge text-bg-primary">{{ $provinceCounts[$province->code] ?? 0 }}</span>
                                </a>
                            </summary>
                            <div class="unit-children">
                                @foreach($province->communes as $commune)
                                    @php
                                        $communeSelected = $selectedType === 'commune' && $selectedCode === $commune->code;
                                        $communeExpanded = $communeSelected || ($selectedUnit instanceof \App\Models\Village && $selectedUnit->commune_code === $commune->code);
                                    @endphp
                                    <details {{ $communeExpanded ? 'open' : '' }}>
                                        <summary>
                                            <a class="unit-link {{ $communeSelected ? 'active' : '' }} flex-grow-1"
                                               href="{{ route('admin.organization-accounts.index', ['unit_type' => 'commune', 'unit_code' => $commune->code]) }}">
                                                <i class="fa-solid fa-building-columns text-info"></i>
                                                <span class="unit-name">{{ $commune->name }}</span>
                                                <span class="badge text-bg-info">{{ $communeCounts[$commune->code] ?? 0 }}</span>
                                            </a>
                                        </summary>
                                        <div class="unit-children">
                                            @foreach($commune->villages as $village)
                                                <a class="unit-link village-unit {{ $selectedType === 'village' && $selectedCode === $village->code ? 'active' : '' }}"
                                                   href="{{ route('admin.organization-accounts.index', ['unit_type' => 'village', 'unit_code' => $village->code]) }}">
                                                    <i class="fa-solid fa-house text-success"></i>
                                                    <span class="unit-name">{{ $village->name }}</span>
                                                    <span class="badge text-bg-success">{{ $villageCounts[$village->code] ?? 0 }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <div class="text-muted small p-2">Chưa có dữ liệu đơn vị hành chính.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-xl-9">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold"><i class="fa-solid fa-users me-2 text-primary"></i>{{ $unitName }}</div>
                        @if($selectedUnit)
                            <div class="small text-muted">
                                {{ number_format($unitTotalAccounts) }}
                                {{ $selectedType === 'village' ? 'tài khoản thuộc đơn vị này' : 'tài khoản trong đơn vị và các cấp dưới' }}
                            </div>
                        @endif
                    </div>
                    @if($selectedUnit)
                        <span class="badge {{ $unitActiveAccounts === $unitTotalAccounts ? 'text-bg-success' : 'text-bg-primary' }}">
                            {{ number_format($unitActiveAccounts) }} đang hoạt động
                        </span>
                    @endif
                </div>
                @if($selectedUnit)
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tên đăng nhập</th>
                                    <th>Họ và tên</th>
                                    <th>Nhóm người dùng</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($unitAccounts as $account)
                                    <tr>
                                        <td class="text-nowrap">{{ $account->username ?: '—' }}</td>
                                        <td>{{ $account->name }}</td>
                                        <td>
                                            @forelse($account->roles as $role)
                                                <span class="badge text-bg-light border text-dark">{{ $role->name }}</span>
                                            @empty
                                                <span class="text-muted">Chưa gán nhóm</span>
                                            @endforelse
                                        </td>
                                        <td>
                                            @if($account->is_active)
                                                <span class="badge text-bg-success">Hoạt động</span>
                                            @else
                                                <span class="badge text-bg-secondary">Đã khóa</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Đơn vị này chưa có tài khoản.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($unitAccounts->hasPages())
                        <div class="card-footer bg-white">{{ $unitAccounts->links('vendor.pagination.bootstrap-5') }}</div>
                    @endif
                @else
                    <div class="card-body text-center text-muted py-5">Chưa có đơn vị để hiển thị tài khoản.</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
