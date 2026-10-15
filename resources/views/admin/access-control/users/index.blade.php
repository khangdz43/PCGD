@extends('layouts.app')

@section('title', 'Quản lý tài khoản')

@push('styles')
<style>
    /* ===== Hierarchy Tree Styles ===== */
    .hierarchy-tree {
        --tree-line-color: #d8dee8;
    }

    /* ===== Tree Node ===== */
    .tree-node {
        margin-bottom: 0;
        background: #fff;
    }

    .tree-node.level-province {
        margin-bottom: .65rem;
        overflow: hidden;
        border: 1px solid #dfe4eb;
        border-radius: .5rem;
    }

    .tree-node.level-commune {
        margin-left: 1.25rem;
        border-left: 1px solid var(--tree-line-color);
    }

    .tree-node.level-village {
        margin-left: 1.25rem;
        border-left: 1px solid var(--tree-line-color);
    }

    /* ===== Tree Header ===== */
    .tree-header {
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: .65rem .8rem;
        cursor: pointer;
        user-select: none;
        transition: background-color .15s;
        position: relative;
    }

    .tree-header:hover {
        background: #f6f8fa;
    }

    .tree-header .toggle-icon {
        width: 1rem;
        height: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        font-size: .65rem;
        transition: transform .18s ease;
        flex-shrink: 0;
    }

    .tree-header[aria-expanded="true"] .toggle-icon {
        transform: rotate(90deg);
    }

    .tree-header .level-icon {
        width: 1.8rem;
        height: 1.8rem;
        border-radius: .35rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .82rem;
        color: #475569;
        background: #f1f4f7;
        flex-shrink: 0;
    }

    .level-icon.commune,
    .level-icon.village {
        background: #f8f9fa;
        color: #64748b;
    }

    .tree-header .node-name {
        font-weight: 600;
        color: #273449;
        font-size: .9rem;
    }

    /* ===== Collapse Body ===== */
    .tree-body {
        max-height: 0;
        overflow: hidden;
        transition: max-height .35s ease;
    }

    .tree-body.open {
        max-height: 10000px;
    }

    .tree-body-inner {
        padding: 0 .8rem .7rem;
    }

    /* ===== User Card ===== */
    .user-card {
        display: grid;
        grid-template-columns: 3rem minmax(0, 2fr) minmax(0, 1.6fr) minmax(0, 1.1fr) auto;
        align-items: center;
        gap: .8rem;
        width: 100%;
        padding: .75rem .8rem;
        border: 1px solid #e5e9ef;
        border-radius: .6rem;
        margin-bottom: .5rem;
        background: #fff;
        min-height: 4.5rem;
        transition: background-color .15s ease, border-color .15s ease;
    }

    .user-card:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
    }

    .user-card-central {
        border-color: #c4b5fd;
        background: #faf8ff;
    }

    .user-card-central:hover {
        border-color: #8b5cf6;
        background: #f5f3ff;
    }

    .account-scope-badge {
        display: inline-block;
        margin-left: .35rem;
        padding: .08rem .4rem;
        border: 1px solid #c4b5fd;
        border-radius: 999px;
        background: #ede9fe;
        color: #5b21b6;
        font-size: .68rem;
        font-weight: 700;
        vertical-align: middle;
    }

    .user-avatar {
        width: 2.25rem;
        height: 2.25rem;
        border-radius: .4rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: .95rem;
        color: #475569;
        background: #eef2f6;
        flex-shrink: 0;
        text-transform: uppercase;
    }

    .user-info {
        min-width: 0;
        width: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
        overflow: hidden;
    }

    .user-name {
        font-weight: 600;
        color: #273449;
        font-size: .9rem;
        line-height: 1.35;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-email {
        font-size: .8rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-meta {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: .5rem;
        flex-wrap: wrap;
        min-width: 0;
        width: 100%;
    }

    .user-role-column {
        min-width: 0;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        gap: .25rem;
        overflow: hidden;
    }

    .user-role-label {
        font-size: .68rem;
        font-weight: 700;
        color: #64748b;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .user-role-badge {
        font-size: .72rem;
        padding: .2rem .6rem;
        border-radius: 999px;
        font-weight: 600;
        background: #f5f6f8;
        color: #475569;
        border: 1px solid #e1e5eb;
    }

    .user-role-empty {
        background: #f8f9fa;
        color: #64748b;
    }

    .user-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid transparent;
        font-size: .72rem;
        line-height: 1;
        padding: .45rem .7rem;
        border-radius: 999px;
    }

    .user-status-active {
        background: #eef6f0;
        color: #356345;
        border-color: #d6e8db;
    }

    .user-status-inactive {
        background: #f1f3f5;
        color: #59636e;
        border-color: #e0e4e8;
    }

    .user-actions {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: .35rem;
        flex-shrink: 0;
        min-width: 5rem;
    }

    .user-actions .btn {
        font-size: .78rem;
        padding: .25rem .65rem;
        border-radius: .4rem;
    }

    /* ===== No Location Section ===== */
    .no-location-section {
        border: 1px solid #dfe4eb;
        border-radius: .5rem;
        background: #fff;
        padding: .8rem;
        margin-bottom: .65rem;
    }

    .no-location-section .section-title {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: .6rem;
    }

    /* ===== Connector Lines ===== */
    .tree-children {
        position: relative;
        padding-left: .5rem;
    }

    .tree-children::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 1rem;
        width: 1px;
        background: var(--tree-line-color);
    }

    /* ===== Search highlight ===== */
    .search-result-count {
        background: #f1f5f9;
        color: #475569;
        padding: .3rem .65rem;
        border-radius: .35rem;
        font-size: .82rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
    }

    /* ===== Expand/Collapse All ===== */
    .tree-controls {
        display: flex;
        gap: .5rem;
    }

    .tree-controls .btn {
        font-size: .8rem;
        padding: .3rem .75rem;
        border-radius: .4rem;
    }

    .user-location-filter {
        display: grid;
        grid-template-columns: minmax(180px, 1.4fr) minmax(160px, 1.1fr) minmax(150px, 1.1fr) minmax(150px, 1.1fr) minmax(150px, 1.1fr) auto;
        gap: .5rem;
        align-items: center;
    }

    .user-location-filter > .location-picker-inline {
        display: contents;
    }

    .user-location-filter .location-picker-field {
        min-width: 0;
        display: flex;
        align-items: stretch;
    }

    .user-location-filter .form-select,
    .user-location-filter .form-control {
        min-width: 0;
        width: 100%;
        height: 2.5rem;
    }

    .user-location-filter [data-clear-user-filter] {
        align-self: stretch;
        white-space: nowrap;
    }

    .user-location-filter .location-picker-status:empty {
        display: none;
    }

    .user-location-filter .location-picker-status:not(:empty) {
        grid-column: 1 / -1;
    }

    .user-management-layout {
        display: grid;
        grid-template-columns: minmax(270px, 320px) minmax(0, 1fr);
        align-items: start;
        margin: 0;
    }

    .user-management-layout > * {
        width: auto;
        max-width: none;
        padding: 0;
    }

    .unit-browser {
        overflow: visible;
    }

    .unit-browser-tree {
        max-height: min(16rem, 55vh);
        overflow-y: auto;
        padding: .5rem;
    }

    .account-list-panel {
        overflow: hidden;
        border: 1px solid #dfe4eb;
        border-radius: .6rem;
        background: #fff;
    }

    .account-list-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        padding: .6rem .8rem;
        border-bottom: 1px solid #e8edf3;
    }

    .account-list-body {
        max-height: min(34rem, 65vh);
        overflow-y: auto;
        padding: .5rem;
        background: #f9fafb;
    }

    .account-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 .5rem;
        table-layout: fixed;
        margin: 0;
    }

    .account-table thead th {
        padding: .65rem .8rem;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .03em;
        text-transform: uppercase;
        color: #64748b;
        background: transparent;
        border: 0;
    }

    .account-table tbody td {
        padding: .8rem;
        vertical-align: top;
        background: #fff;
        border-top: 1px solid #e5e9ef;
        border-bottom: 1px solid #e5e9ef;
    }

    .account-table tbody td:first-child {
        border-left: 1px solid #e5e9ef;
        border-top-left-radius: .6rem;
        border-bottom-left-radius: .6rem;
    }

    .account-table tbody td:last-child {
        border-right: 1px solid #e5e9ef;
        border-top-right-radius: .6rem;
        border-bottom-right-radius: .6rem;
    }

    .account-table tbody .user-row:hover td {
        background: #f8fafc;
    }

    .user-row-central td {
        background: #faf8ff;
    }

    .user-row-central:hover td {
        background: #f5f3ff;
    }

    .user-cell {
        min-width: 0;
    }

    .user-cell-name {
        width: 40%;
    }

    .user-cell-role {
        width: 30%;
    }

    .user-cell-status {
        width: 15%;
    }

    .user-cell-actions {
        width: 15%;
        text-align: right;
    }

    .account-list-body .user-card:last-child {
        margin-bottom: 0;
    }

    .account-location {
        margin-top: .15rem;
        color: #64748b;
        font-size: .76rem;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .unit-tree-node {
        min-width: 0;
    }

    .unit-tree-row {
        display: flex;
        align-items: center;
        gap: .25rem;
        min-width: 0;
    }

    .unit-tree-node[data-unit-type="province"] > .unit-tree-row {
        position: sticky;
        top: -.5rem;
        z-index: 2;
        background: #fff;
        box-shadow: 0 1px 0 #dfe4eb;
    }

    .unit-tree-toggle,
    .unit-tree-leaf {
        display: flex;
        align-items: center;
        gap: .55rem;
        flex: 1 1 auto;
        min-width: 0;
        padding: .5rem .55rem;
        border: 0;
        border-radius: .4rem;
        background: transparent;
        color: #334155;
        text-align: left;
    }

    .unit-tree-toggle:hover,
    .unit-tree-leaf:hover,
    .unit-tree-select:hover {
        background: #f1f5f9;
    }

    .unit-tree-toggle > i {
        width: .8rem;
        flex: 0 0 .8rem;
        color: #64748b;
        font-size: .7rem;
        transition: transform .15s ease;
    }

    .unit-tree-toggle[aria-expanded="true"] > i {
        transform: rotate(90deg);
    }

    .unit-tree-name {
        min-width: 0;
        flex: 1 1 auto;
        overflow-wrap: anywhere;
    }

    .unit-tree-select {
        display: grid;
        min-width: 2.5rem;
        min-height: 2.5rem;
        flex: 0 0 auto;
        place-items: center;
        border: 0;
        border-radius: .4rem;
        background: transparent;
    }

    .unit-tree-count {
        min-width: 1.65rem;
        background: #f1f3f5;
        color: #475569;
        font-size: .72rem;
        font-weight: 600;
    }

    .unit-tree-node.is-active > .unit-tree-row {
        border-radius: .45rem;
        background: #eff6ff;
    }

    .unit-tree-node.is-active > .unit-tree-row .unit-tree-name {
        color: #1d4ed8;
        font-weight: 700;
    }

    .unit-tree-children {
        margin-left: 1rem;
        padding-left: .4rem;
        border-left: 1px solid #dbe3ed;
    }

    .unit-tree-node[data-unit-type="commune"] .unit-tree-toggle,
    .unit-tree-node[data-unit-type="commune"] .unit-tree-leaf {
        font-size: .9rem;
    }

    .unit-tree-node[data-unit-type="village"] .unit-tree-leaf {
        font-size: .86rem;
        color: #475569;
    }

    .unit-tree-loading {
        padding: .45rem .6rem;
        color: #64748b;
        font-size: .82rem;
    }

    @media (max-width: 991.98px) {
        .user-location-filter {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .user-management-layout {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    @media (max-width: 767.98px) {
        .user-location-filter {
            grid-template-columns: 1fr;
        }

        .user-location-filter .location-picker-field:first-child {
            grid-column: auto;
        }

        .user-location-filter .form-control,
        .user-location-filter .location-picker-field,
        .user-location-filter [data-clear-user-filter],
        .user-location-filter .btn-primary {
            width: 100%;
        }

        .unit-browser {
            position: static;
        }

        .unit-browser-tree {
            max-height: min(16rem, 55vh);
        }
    }

    /* ===== Responsive ===== */
    @media (max-width: 768px) {
        .tree-node.level-commune,
        .tree-node.level-village {
            margin-left: .5rem;
        }

        .user-card {
            display: grid;
            grid-template-columns: 2.5rem minmax(0, 1fr) auto;
            grid-template-areas:
                "avatar info actions"
                "avatar meta meta";
            align-items: center;
            column-gap: .65rem;
            row-gap: .45rem;
            width: 100%;
            padding: .7rem;
        }

        .user-avatar {
            grid-area: avatar;
            width: 2.5rem;
            height: 2.5rem;
        }

        .user-info {
            grid-area: info;
        }

        .user-meta {
            grid-area: meta;
            width: 100%;
            min-width: 0;
            margin-top: 0;
            gap: .35rem;
        }

        .user-actions {
            grid-area: actions;
            width: auto;
            justify-content: flex-end;
            margin-top: 0;
        }

        .user-actions .btn {
            display: inline-grid;
            width: 2.35rem;
            height: 2.35rem;
            place-items: center;
            padding: 0;
        }
    }

    @media (max-width: 575.98px) {
        .container > .user-management-header.d-flex.justify-content-between > .btn {
            align-self: flex-start;
            width: auto;
            padding-right: 1rem;
            padding-left: 1rem;
        }

        .tree-node.level-province {
            margin-bottom: .5rem;
        }

        .tree-node.level-commune {
            margin-left: .35rem;
            background: transparent;
        }

        .tree-node.level-commune > .tree-header {
            padding: .55rem .6rem;
        }

        .tree-node.level-commune > .tree-body > .tree-body-inner {
            padding: 0 .5rem .6rem;
        }

        .tree-header {
            gap: .5rem;
            padding: .65rem .75rem;
        }

        .tree-header .level-icon {
            width: 1.8rem;
            height: 1.8rem;
            border-radius: .35rem;
        }

        .tree-header .node-name {
            min-width: 0;
            font-size: .9rem;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .tree-body-inner {
            padding: 0 .65rem .65rem;
        }

        .tree-children {
            padding-left: .45rem;
        }

        .tree-children::before {
            width: 1px;
        }

        .user-card {
            column-gap: .55rem;
            row-gap: .4rem;
            margin-bottom: .4rem;
            padding: .6rem;
            border-radius: .55rem;
            box-shadow: none;
        }

        .user-card:hover {
            transform: none;
        }

        .user-name {
            font-size: .86rem;
        }

        .user-email {
            font-size: .75rem;
        }

        .user-role-badge {
            max-width: 100%;
            padding: .2rem .5rem;
            font-size: .68rem;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .user-meta > .badge {
            white-space: nowrap;
        }

        .user-actions {
            gap: .3rem;
        }

        .user-actions .btn {
            width: 2.2rem;
            height: 2.2rem;
        }

        .no-location-section {
            padding: .75rem;
        }

        .tree-controls .btn {
            min-height: 2.5rem;
            padding: .4rem .5rem;
        }
    }

    @media (max-width: 359.98px) {
        .user-card {
            grid-template-columns: 2rem minmax(0, 1fr) auto;
            column-gap: .45rem;
            padding: .5rem;
        }

        .user-avatar {
            width: 2rem;
            height: 2rem;
            font-size: .8rem;
        }

        .user-actions .btn {
            width: 2rem;
            height: 2rem;
        }
    }
</style>
@endpush

@section('content')
<div class="container">
    <h1 class="visually-hidden">Quản lý tài khoản</h1>

    {{-- Lọc theo địa bàn --}}
    @php
        $actorScopeLevel = $actor->roleScopeLevel();
        $canSelectProvince = $actorScopeLevel === 'global';
        $canSelectCommune = in_array($actorScopeLevel, ['global', 'province'], true);
        $canSelectVillage = in_array($actorScopeLevel, ['global', 'province', 'commune'], true);
    @endphp

    <div class="d-flex justify-content-end align-items-center gap-2 mb-2">
        <a class="btn btn-sm btn-primary" href="{{ route('admin.users.create') }}">
            <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>Tạo tài khoản
        </a>
    </div>

    <form method="GET" action="{{ route('admin.users.index') }}" class="user-location-filter bg-white border rounded-2 p-2 mb-2" id="user-location-filter">
        <label class="visually-hidden" for="user-search">Tìm theo tên hoặc tên đăng nhập</label>
        <input id="user-search" name="q" type="search" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Tìm tên hoặc tên đăng nhập">

        <label class="visually-hidden" for="user-role-filter">Lọc theo nhóm người dùng</label>
        <select id="user-role-filter" name="role_id" class="form-select form-select-sm">
            <option value="">Tất cả nhóm</option>
            @foreach($roleOptions ?? collect() as $roleOption)
                <option value="{{ $roleOption->id }}" @selected((string) ($roleId ?? request('role_id')) === (string) $roleOption->id)>
                    {{ $roleOption->name }}
                </option>
            @endforeach
        </select>

        @include('partials.location-picker', [
            'pickerId' => 'user-list-location',
            'provinces' => $provinces,
            'provinceCode' => $provinceCode,
            'communeCode' => $communeCode,
            'villageCode' => $villageCode,
            'showVillage' => true,
            'inline' => true,
            'hideProvince' => ! $canSelectProvince,
            'hideCommune' => ! $canSelectCommune,
            'hideVillage' => ! $canSelectVillage,
        ])
        <button class="btn btn-sm btn-outline-secondary" type="button" data-clear-user-filter @disabled(! $isFiltering)>
            <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Bỏ lọc
        </button>
    </form>

    <div class="row g-3 user-management-layout">
        @if ($showOrganizationTree)
            <aside class="col-lg-4 col-xl-3" aria-label="Cây đơn vị hành chính">
                @include('admin.access-control.users._organization_tree')
            </aside>
        @endif
        <section class="{{ $showOrganizationTree ? 'col-lg-8 col-xl-9' : 'col-12' }}" aria-label="Danh sách tài khoản">
    <div id="account-tree-results" aria-live="polite" data-ajax-pagination>
        @if ($showOrganizationTree)
            <div class="account-list-panel">
                <div class="account-list-header">
                    <div>
                        <h2 class="h6 mb-1">Tài khoản</h2>
                        <div class="small text-muted">
                            {{ $isFiltering ? 'Trong địa bàn đang chọn' : 'Tất cả địa bàn' }}
                        </div>
                    </div>
                    <span class="badge text-bg-light border">{{ number_format($totalUserCount) }}</span>
                </div>
                <div class="account-list-body">
                    @if($allUsers->isNotEmpty())
                        <table class="account-table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Tài khoản</th>
                                    <th scope="col">Nhóm người dùng</th>
                                    <th scope="col">Trạng thái</th>
                                    <th scope="col" class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($allUsers as $user)
                                    @include('admin.access-control.users._user_card', ['user' => $user, 'tableMode' => true])
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="text-center text-muted py-5">
                            <i class="fa-regular fa-folder-open fs-3 d-block mb-2" aria-hidden="true"></i>
                            Chưa có tài khoản trong địa bàn đang chọn.
                        </div>
                    @endif
                </div>
                @if($allUsers->hasPages())
                    <div class="card-footer bg-white">
                        {{ $allUsers->links('vendor.pagination.bootstrap-5') }}
                    </div>
                @endif
            </div>
        @else
        {{-- Kết quả lọc --}}
        @if($isFiltering)
            <div class="mb-3">
                <span class="search-result-count">
                    <i class="fa-solid fa-filter"></i>
                    {{ $allUsers->count() }} tài khoản trong địa bàn đã chọn
                </span>
            </div>
        @endif

        {{-- Controls --}}
        <div class="d-flex justify-content-end mb-2 tree-controls">

            <button
                type="button"
                class="btn btn-outline-secondary"
                onclick="toggleAllNodes(true)"
            >
                <i class="fa-solid fa-angles-down me-1"></i>
                Mở tất cả
            </button>

            <button
                type="button"
                class="btn btn-outline-secondary"
                onclick="toggleAllNodes(false)"
            >
                <i class="fa-solid fa-angles-up me-1"></i>
                Thu gọn
            </button>

        </div>

        {{-- Hierarchy Tree --}}
        <div class="hierarchy-tree">

        {{-- Không có địa bàn --}}
        @if($noLocationUsers->isNotEmpty())

            <div class="no-location-section">

                <div class="section-title">
                    <i class="fa-solid fa-triangle-exclamation"></i>

                    Chưa gán địa bàn
                    ({{ $noLocationUsers->count() }})
                    </div>
                </div>

                @foreach($noLocationUsers as $user)

                    @include(
                        'admin.access-control.users._user_card',
                        ['user' => $user]
                    )

                @endforeach

            </div>

        @endif


        {{-- Theo Tỉnh --}}
        @forelse($hierarchy as $provinceCode => $province)

            <div class="tree-node level-province">

                {{-- Province Header --}}
                <div
                    class="tree-header"
                    aria-expanded="false"
                    onclick="toggleNode(this)"
                >

                    <span class="toggle-icon">
                        <i class="fa-solid fa-chevron-right"></i>
                    </span>

                    <span class="level-icon province">
                        <i class="fa-solid fa-city"></i>
                    </span>

                    <span class="node-name">
                        {{ $province['name'] }}
                    </span>

                </div>


                {{-- Province Body --}}
                <div class="tree-body">

                    <div class="tree-body-inner">

                        <div class="tree-children">

                            {{-- Users trực tiếp thuộc tỉnh --}}
                            @foreach($province['direct_users'] as $user)

                                @include(
                                    'admin.access-control.users._user_card',
                                    ['user' => $user]
                                )

                            @endforeach


                            {{-- Xã / Phường --}}
                            @foreach($province['communes'] as $communeCode => $commune)

                                <div class="tree-node level-commune">

                                    {{-- Commune Header --}}
                                    <div
                                        class="tree-header"
                                        aria-expanded="false"
                                        onclick="toggleNode(this)"
                                    >

                                        <span class="toggle-icon">
                                            <i class="fa-solid fa-chevron-right"></i>
                                        </span>

                                        <span class="level-icon commune">
                                            <i class="fa-solid fa-building-columns"></i>
                                        </span>

                                        <span class="node-name">
                                            {{ $commune['name'] }}
                                        </span>

                                    </div>


                                    {{-- Commune Body --}}
                                    <div class="tree-body">

                                        <div class="tree-body-inner">

                                            <div class="tree-children">

                                                {{-- Users trực tiếp thuộc xã --}}
                                                @foreach($commune['direct_users'] as $user)

                                                    @include(
                                                        'admin.access-control.users._user_card',
                                                        ['user' => $user]
                                                    )

                                                @endforeach


                                                {{-- Thôn / Bản --}}
                                                @foreach($commune['villages'] as $villageCode => $village)

                                                    <div class="tree-node level-village">

                                                        {{-- Village Header --}}
                                                        <div
                                                            class="tree-header"
                                                            aria-expanded="false"
                                                            onclick="toggleNode(this)"
                                                        >

                                                            <span class="toggle-icon">
                                                                <i class="fa-solid fa-chevron-right"></i>
                                                            </span>

                                                            <span class="level-icon village">
                                                                <i class="fa-solid fa-house-chimney"></i>
                                                            </span>

                                                            <span class="node-name">
                                                                {{ $village['name'] }}
                                                            </span>

                                                        </div>


                                                        {{-- Village Body --}}
                                                        <div class="tree-body">

                                                            <div class="tree-body-inner">

                                                                @foreach($village['users'] as $user)

                                                                    @include(
                                                                        'admin.access-control.users._user_card',
                                                                        ['user' => $user]
                                                                    )

                                                                @endforeach

                                                            </div>

                                                        </div>

                                                    </div>

                                                @endforeach

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            @endforeach

                        </div>

                    </div>

                </div>

            </div>

        @empty

            @if($noLocationUsers->isEmpty())

                <div class="text-center py-5">

                    <i
                        class="fa-regular fa-folder-open text-muted"
                        style="font-size:3rem"
                    ></i>

                    <p class="text-muted mt-3 mb-0">
                        Chưa có tài khoản nào trong phạm vi quản lý.
                    </p>

                </div>

            @endif

    @endforelse
    </div>
        @endif

</div>
    </section>
</div>
@endsection


@push('scripts')
<script>
    (() => {
        const filterForm = document.getElementById('user-location-filter');
        const results = document.getElementById('account-tree-results');
        if (filterForm && results && filterForm.dataset.ajaxReady !== 'true') {
            filterForm.dataset.ajaxReady = 'true';
            let activeRequest;
            let requestVersion = 0;
            const unitTree = document.getElementById('organization-unit-tree');
            const locationPicker = filterForm.querySelector('[data-location-picker]');
            const parseCounts = (attribute) => {
                try {
                    return JSON.parse(unitTree?.dataset[attribute] || '{}');
                } catch (error) {
                    console.error('Không đọc được số tài khoản trong cây đơn vị:', error);
                    return {};
                }
            };
            const unitCounts = {
                province: parseCounts('provinceCounts'),
            };

            const syncClearFilterVisibility = () => {
                const hasFilters = ['q', 'role_id', 'province_code', 'commune_code', 'village_code']
                    .some((key) => {
                        const element = filterForm.elements.namedItem(key);
                        if (!element) return false;
                        const value = element.value ?? '';
                        return String(value).trim() !== '';
                    });

                const clearButton = filterForm.querySelector('[data-clear-user-filter]');
                if (clearButton) {
                    clearButton.disabled = !hasFilters;
                    clearButton.setAttribute('aria-disabled', String(!hasFilters));
                }
                const clearTreeFilter = unitTree?.querySelector('[data-clear-user-filter]');
                if (clearTreeFilter) {
                    clearTreeFilter.disabled = !hasFilters;
                    clearTreeFilter.setAttribute('aria-disabled', String(!hasFilters));
                }
            };

            const selectedFiltersUrl = () => {
                const url = new URL(filterForm.action, window.location.href);
                new FormData(filterForm).forEach((value, key) => {
                    if (typeof value === 'string' && value !== '') {
                        url.searchParams.set(key, value);
                    }
                });
                return url;
            };

            const updateActiveUnit = (url = new URL(window.location.href)) => {
                if (!unitTree) return;
                const scopedRoot = unitTree.querySelector('.unit-tree-node[data-scope-root]');
                const provinceCode = url.searchParams.get('province_code') || scopedRoot?.dataset.provinceCode || '';
                const communeCode = url.searchParams.get('commune_code')
                    || (scopedRoot?.dataset.unitType === 'commune' ? scopedRoot.dataset.unitCode : '');
                const villageCode = url.searchParams.get('village_code') || '';
                unitTree.querySelectorAll('.unit-tree-node').forEach((node) => {
                    const type = node.dataset.unitType;
                    const code = node.dataset.unitCode;
                    const selected = (type === 'province' && code === provinceCode && !communeCode)
                        || (type === 'commune' && code === communeCode && !villageCode)
                        || (type === 'village' && code === villageCode);
                    node.classList.toggle('is-active', selected);
                });
            };

            const revealSelectedUnit = async (url = new URL(window.location.href)) => {
                if (!unitTree) return;
                const provinceCode = url.searchParams.get('province_code');
                const scopedRoot = unitTree.querySelector('.unit-tree-node[data-scope-root]');
                const communeCode = url.searchParams.get('commune_code')
                    || (scopedRoot?.dataset.unitType === 'commune' ? scopedRoot.dataset.unitCode : '');
                const villageCode = url.searchParams.get('village_code');
                const rootType = unitTree.querySelector('.unit-tree-node')?.dataset.unitType || 'province';
                if (rootType === 'commune') {
                    if (!communeCode) return;
                    let node = [...unitTree.querySelectorAll('.unit-tree-node[data-unit-type="commune"]')]
                        .find((item) => item.dataset.unitCode === communeCode);
                    if (!node) return;
                    if (villageCode) {
                        const children = await expandUnitNode(node);
                        node = [...children.querySelectorAll(':scope > .unit-tree-node[data-unit-type="village"]')]
                            .find((item) => item.dataset.unitCode === villageCode);
                    }
                    if (node) {
                        updateActiveUnit(url);
                        node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                    return;
                }
                const scopedProvinceCode = provinceCode || scopedRoot?.dataset.provinceCode;
                if (!scopedProvinceCode) return;

                let node = [...unitTree.querySelectorAll('.unit-tree-node[data-unit-type="province"]')]
                    .find((item) => item.dataset.unitCode === scopedProvinceCode);
                if (!node) return;

                if (communeCode || villageCode) {
                    const children = await expandUnitNode(node);
                    if (communeCode) {
                        node = [...children.querySelectorAll(':scope > .unit-tree-node[data-unit-type="commune"]')]
                            .find((item) => item.dataset.unitCode === communeCode);
                        if (!node) return;
                        if (villageCode) {
                            const villages = await expandUnitNode(node);
                            node = [...villages.querySelectorAll(':scope > .unit-tree-node[data-unit-type="village"]')]
                                .find((item) => item.dataset.unitCode === villageCode);
                        }
                    }
                }

                if (node) {
                    updateActiveUnit(url);
                    node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            };

            const expandUnitNode = async (parent) => {
                if (parent.dataset.treeLoaded !== 'true') await loadUnitChildren(parent);
                const toggle = parent.querySelector(':scope > .unit-tree-row [data-tree-toggle]');
                const children = parent.querySelector(':scope > [data-unit-children]');
                toggle.setAttribute('aria-expanded', 'true');
                children.hidden = false;
                return children;
            };

            const refreshResults = async (url, preserveScroll = false) => {
                const version = ++requestVersion;
                const scrollPosition = preserveScroll ? window.scrollY : null;
                activeRequest?.abort();
                activeRequest = new AbortController();
                results.setAttribute('aria-busy', 'true');

                try {
                    const response = await fetch(url, {
                        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: activeRequest.signal,
                    });
                    if (!response.ok) {
                        throw new Error(`Không thể lọc danh sách tài khoản (HTTP ${response.status}).`);
                    }

                    const document = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const updatedResults = document.getElementById('account-tree-results');
                    if (!updatedResults) {
                        throw new Error('Dữ liệu danh sách tài khoản trả về không hợp lệ.');
                    }
                    if (version !== requestVersion) return;

                    results.innerHTML = updatedResults.innerHTML;
                    syncClearFilterVisibility();
                    window.history.replaceState({}, '', url);
                    updateActiveUnit(url);
                    if (scrollPosition !== null) {
                        window.requestAnimationFrame(() => window.scrollTo({ top: scrollPosition }));
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        window.showAppNotification('error', error.message || 'Không thể lọc danh sách tài khoản.');
                    }
                } finally {
                    if (version === requestVersion) {
                        results.removeAttribute('aria-busy');
                    }
                }
            };

            results.addEventListener('click', (event) => {
                const link = event.target.closest('nav[aria-label="Phân trang"] a[href]');
                if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

                event.preventDefault();
                refreshResults(new URL(link.href, window.location.href), true);
            });

            results.addEventListener('change', (event) => {
                if (!event.target.matches('[data-pagination-select]')) return;

                const form = event.target.form;
                if (!form) return;
                const url = new URL(form.action, window.location.href);
                new FormData(form).forEach((value, key) => {
                    if (typeof value === 'string' && value !== '') {
                        url.searchParams.set(key, value);
                    }
                });
                refreshResults(url, true);
            });

            const createUnitNode = (type, unit, provinceCode, communeCode = '') => {
                const node = document.createElement('section');
                node.className = 'unit-tree-node';
                node.dataset.unitType = type;
                node.dataset.unitCode = String(unit.code);
                node.dataset.unitName = unit.name;
                node.dataset.provinceCode = provinceCode;
                if (communeCode) node.dataset.communeCode = communeCode;

                const row = document.createElement('div');
                row.className = 'unit-tree-row';
                if (type === 'village') {
                    const selectButton = document.createElement('button');
                    selectButton.type = 'button';
                    selectButton.className = 'unit-tree-leaf';
                    selectButton.dataset.unitSelect = '';
                    selectButton.setAttribute('aria-label', `Lọc tài khoản theo ${unit.name}`);
                    const name = document.createElement('span');
                    name.className = 'unit-tree-name';
                    name.textContent = unit.name;
                    selectButton.append(name);
                    row.append(selectButton);
                } else {
                    const toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'unit-tree-toggle';
                    toggle.dataset.treeToggle = '';
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.setAttribute('aria-label', `Mở ${type === 'province' ? 'xã/phường thuộc ' : 'thôn/bản thuộc '}${unit.name}`);
                    const chevron = document.createElement('i');
                    chevron.className = 'fa-solid fa-chevron-right';
                    chevron.setAttribute('aria-hidden', 'true');
                    const name = document.createElement('span');
                    name.className = 'unit-tree-name';
                    name.textContent = unit.name;
                    toggle.append(chevron, name);

                    const selectButton = document.createElement('button');
                    selectButton.type = 'button';
                    selectButton.className = 'unit-tree-select';
                    selectButton.dataset.unitSelect = '';
                    selectButton.setAttribute('aria-label', `Lọc tài khoản theo ${unit.name}`);
                    const count = document.createElement('span');
                    count.className = 'badge unit-tree-count';
                    count.textContent = Number(
                        unit.account_count ?? unitCounts[type]?.[unit.code] ?? 0
                    ).toLocaleString('vi-VN');
                    selectButton.append(count);
                    row.append(toggle, selectButton);
                }

                node.append(row);
                if (type !== 'village') {
                    const children = document.createElement('div');
                    children.className = 'unit-tree-children';
                    children.dataset.unitChildren = '';
                    children.hidden = true;
                    node.append(children);
                }
                return node;
            };

            const loadUnitChildren = async (node) => {
                const type = node.dataset.unitType;
                const code = node.dataset.unitCode;
                const endpoint = type === 'province'
                    ? `${unitTree.dataset.provincesUrl}/${encodeURIComponent(code)}/communes`
                    : `${unitTree.dataset.communesUrl}/${encodeURIComponent(code)}/villages`;
                const url = new URL(endpoint, window.location.href);
                url.searchParams.set('include_account_counts', '1');
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`Không thể tải đơn vị cấp dưới (HTTP ${response.status}).`);
                }
                const units = await response.json();
                if (!Array.isArray(units)) throw new Error('Danh sách đơn vị trả về không hợp lệ.');
                const children = node.querySelector(':scope > [data-unit-children]');
                const nextType = type === 'province' ? 'commune' : 'village';
                units.forEach((unit) => {
                    children.append(createUnitNode(
                        nextType,
                        unit,
                        type === 'province' ? code : node.dataset.provinceCode,
                        type === 'commune' ? code : unit.code,
                    ));
                });
                if (units.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'unit-tree-loading';
                    empty.textContent = 'Không có đơn vị cấp dưới.';
                    children.append(empty);
                }
                node.dataset.treeLoaded = 'true';
            };

            syncClearFilterVisibility();

            if (unitTree && !unitTree.dataset.ajaxReady) {
                unitTree.dataset.ajaxReady = 'true';
                unitTree.addEventListener('click', async (event) => {
                    const clearButton = event.target.closest('[data-clear-user-filter]');
                    if (clearButton) {
                        filterForm.querySelector('[name="q"]').value = '';
                        filterForm.querySelector('[data-province]').value = '';
                        const commune = filterForm.querySelector('[data-commune]');
                        commune.replaceChildren(new Option('-- Chọn Xã/Phường --', ''));
                        commune.disabled = true;
                        const village = filterForm.querySelector('[data-village]');
                        village.replaceChildren(new Option(village.dataset.placeholder, ''));
                        village.disabled = true;
                        updateActiveUnit(new URL(filterForm.action, window.location.href));
                        await refreshResults(new URL(filterForm.action, window.location.href));
                        return;
                    }

                    const toggle = event.target.closest('[data-tree-toggle]');
                    if (toggle) {
                        const node = toggle.closest('.unit-tree-node');
                        const children = node.querySelector(':scope > [data-unit-children]');
                        const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                        if (isExpanded) {
                            toggle.setAttribute('aria-expanded', 'false');
                            children.hidden = true;
                            return;
                        }

                        try {
                            if (node.dataset.treeLoaded !== 'true') {
                                await loadUnitChildren(node);
                            }
                            toggle.setAttribute('aria-expanded', 'true');
                            children.hidden = false;
                        } catch (error) {
                            children.replaceChildren();
                            window.showAppNotification('error', error.message || 'Không thể tải cây đơn vị.');
                        }
                        return;
                    }

                    const selectButton = event.target.closest('[data-unit-select]');
                    if (!selectButton || !locationPicker?.selectLocation) return;
                    const node = selectButton.closest('.unit-tree-node');
                    const type = node.dataset.unitType;
                    const provinceCode = type === 'province' ? node.dataset.unitCode : node.dataset.provinceCode;
                    const communeCode = type === 'commune' ? node.dataset.unitCode : (type === 'village' ? node.dataset.communeCode : '');
                    const villageCode = type === 'village' ? node.dataset.unitCode : '';
                    try {
                        await locationPicker.selectLocation(provinceCode, communeCode, villageCode);
                        await refreshResults(selectedFiltersUrl());
                        node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    } catch (error) {
                        window.showAppNotification('error', error.message || 'Không thể lọc tài khoản theo đơn vị.');
                    }
                });
                const initialUrl = selectedFiltersUrl();
                updateActiveUnit(initialUrl);
                revealSelectedUnit(initialUrl).catch((error) => {
                    window.showAppNotification('error', error.message || 'Không thể mở đơn vị đang lọc.');
                });
            }

            filterForm.addEventListener('change', (event) => {
                if (event.target.matches('[name="role_id"]')) {
                    event.preventDefault();
                    syncClearFilterVisibility();
                    filterForm.requestSubmit();
                    return;
                }

                if (event.target.matches('[data-province], [data-commune], [data-village]')) {
                    syncClearFilterVisibility();
                    const url = selectedFiltersUrl();
                    refreshResults(url);
                    updateActiveUnit(url);
                    revealSelectedUnit(url).catch((error) => {
                        window.showAppNotification('error', error.message || 'Không thể mở đơn vị đang lọc.');
                    });
                }
            });

            let searchTimer;
            filterForm.querySelector('[name="q"]').addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(() => refreshResults(selectedFiltersUrl()), 250);
            });

            filterForm.addEventListener('click', (event) => {
                const clearLink = event.target.closest('[data-clear-user-filter]');
                if (!clearLink || event.defaultPrevented || event.button !== 0
                    || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

                event.preventDefault();
                filterForm.querySelector('[name="q"]').value = '';
                const roleSelect = filterForm.querySelector('[name="role_id"]');
                if (roleSelect) roleSelect.value = '';
                const provinceSelect = filterForm.querySelector('[data-province]');
                if (provinceSelect) provinceSelect.value = '';
                const commune = filterForm.querySelector('[data-commune]');
                if (commune) {
                    commune.replaceChildren(new Option('-- Chọn Xã/Phường --', ''));
                    commune.disabled = true;
                }

                const village = filterForm.querySelector('[data-village]');
                if (village) {
                    village.replaceChildren(new Option(village.dataset.placeholder, ''));
                    village.disabled = true;
                }
                syncClearFilterVisibility();
                refreshResults(new URL(filterForm.action, window.location.href));
            });
        }

        window.toggleNode = (header) => {
            const body = header.nextElementSibling;
            const isOpen = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', !isOpen);
            body.classList.toggle('open', !isOpen);
        };

        window.toggleAllNodes = (expand) => {
            document.querySelectorAll('.tree-header').forEach((header) => {
                header.setAttribute('aria-expanded', expand);
                header.nextElementSibling.classList.toggle('open', expand);
            });
        };
    })();
</script>
@endpush