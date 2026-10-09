@extends('layouts.app')

@section('title', 'Quản lý tài khoản')

@push('styles')
<style>
    /* ===== Hierarchy Tree Styles ===== */
    .hierarchy-tree {
        --tree-line-color: #d1d9e6;
        --province-color: #2563eb;
        --commune-color: #0891b2;
        --village-color: #059669;
        --user-accent: #6366f1;
    }

    /* ===== Tree Node ===== */
    .tree-node {
        border: 1px solid #e2e8f0;
        border-radius: .75rem;
        margin-bottom: .75rem;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 1px 3px rgb(0 0 0 / 4%);
        transition: box-shadow .2s;
    }

    .tree-node:hover {
        box-shadow: 0 2px 8px rgb(0 0 0 / 8%);
    }

    .tree-node.level-commune {
        margin-left: 1.5rem;
        border-left: 3px solid var(--commune-color);
    }

    .tree-node.level-village {
        margin-left: 1.5rem;
        border-left: 3px solid var(--village-color);
    }

    /* ===== Tree Header ===== */
    .tree-header {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .85rem 1.15rem;
        cursor: pointer;
        user-select: none;
        transition: background .15s;
        position: relative;
    }

    .tree-header:hover {
        background: #f8fafc;
    }

    .tree-header .toggle-icon {
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #f1f5f9;
        color: #475569;
        font-size: .7rem;
        transition: transform .25s ease, background .15s;
        flex-shrink: 0;
    }

    .tree-header[aria-expanded="true"] .toggle-icon {
        transform: rotate(90deg);
        background: #e0e7ff;
        color: var(--province-color);
    }

    .tree-header .level-icon {
        width: 36px;
        height: 36px;
        border-radius: .5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .95rem;
        color: #fff;
        flex-shrink: 0;
    }

    .level-icon.province {
        background: linear-gradient(135deg, #2563eb, #3b82f6);
    }

    .level-icon.commune {
        background: linear-gradient(135deg, #0891b2, #06b6d4);
    }

    .level-icon.village {
        background: linear-gradient(135deg, #059669, #10b981);
    }

    .tree-header .node-name {
        font-weight: 600;
        color: #1e293b;
        font-size: .95rem;
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
        padding: 0 1.15rem 1rem 1.15rem;
    }

    /* ===== User Card ===== */
    .user-card {
        display: flex;
        align-items: center;
        gap: .85rem;
        padding: .75rem 1rem;
        border: 1px solid #e9ecef;
        border-radius: .6rem;
        margin-bottom: .5rem;
        background: #fafbfd;
        transition: all .2s;
    }

    .user-card:hover {
        background: #f0f4ff;
        border-color: #c7d2fe;
        transform: translateX(3px);
    }

    .user-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: .95rem;
        color: #fff;
        flex-shrink: 0;
        text-transform: uppercase;
    }

    .user-card:nth-child(4n+1) .user-avatar {
        background: linear-gradient(135deg, #6366f1, #818cf8);
    }

    .user-card:nth-child(4n+2) .user-avatar {
        background: linear-gradient(135deg, #ec4899, #f472b6);
    }

    .user-card:nth-child(4n+3) .user-avatar {
        background: linear-gradient(135deg, #f59e0b, #fbbf24);
    }

    .user-card:nth-child(4n) .user-avatar {
        background: linear-gradient(135deg, #14b8a6, #2dd4bf);
    }

    .user-info {
        flex: 1;
        min-width: 0;
    }

    .user-name {
        font-weight: 600;
        color: #1e293b;
        font-size: .9rem;
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
        gap: .5rem;
        flex-wrap: wrap;
        flex-shrink: 0;
    }

    .user-role-badge {
        font-size: .72rem;
        padding: .2rem .6rem;
        border-radius: 999px;
        font-weight: 600;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    .user-actions {
        display: flex;
        gap: .35rem;
        flex-shrink: 0;
    }

    .user-actions .btn {
        font-size: .78rem;
        padding: .25rem .65rem;
        border-radius: .4rem;
    }

    /* ===== No Location Section ===== */
    .no-location-section {
        border: 1px dashed #cbd5e1;
        border-radius: .75rem;
        background: #fefce8;
        padding: 1rem 1.25rem;
        margin-bottom: .75rem;
    }

    .no-location-section .section-title {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-weight: 600;
        color: #92400e;
        margin-bottom: .75rem;
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
        width: 2px;
        background: var(--tree-line-color);
        border-radius: 1px;
    }

    /* ===== Search highlight ===== */
    .search-result-count {
        background: #fef3c7;
        color: #92400e;
        padding: .35rem .85rem;
        border-radius: .5rem;
        font-size: .85rem;
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
        grid-template-columns: repeat(3, minmax(0, 1fr)) auto auto;
        gap: .5rem;
        align-items: center;
    }

    .user-location-filter .location-picker-inline {
        display: contents;
    }

    .user-location-filter .location-picker-field {
        min-width: 0;
    }

    .user-location-filter .form-select {
        min-width: 0;
    }

    .user-location-filter .location-picker-status:empty {
        display: none;
    }

    .user-location-filter .location-picker-status:not(:empty) {
        grid-column: 1 / -1;
    }

    @media (max-width: 767.98px) {
        .user-location-filter {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .user-location-filter .location-picker-field:first-child {
            grid-column: 1 / -1;
        }

        .user-location-filter > .btn {
            width: 100%;
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
            width: auto;
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

        .tree-node {
            border-radius: .65rem;
            margin-bottom: .55rem;
        }

        .tree-node.level-commune {
            margin-left: .35rem;
            border-top: 0;
            border-right: 0;
            border-bottom: 0;
            border-radius: 0;
            box-shadow: none;
            background: #f8fafc;
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
            width: 2.25rem;
            height: 2.25rem;
            border-radius: .55rem;
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

    {{-- Header --}}
    <div class="user-management-header d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">
                <i class="fa-solid fa-sitemap text-primary me-2"></i>
                Quản lý tài khoản
            </h1>

            <p class="text-muted mb-0" style="font-size:.88rem">
                Hiển thị phân cấp theo địa bàn quản lý
            </p>
        </div>

        <a class="btn btn-primary" href="{{ route('admin.users.create') }}">
            <i class="fa-solid fa-user-plus me-1"></i>
            Tạo tài khoản
        </a>
    </div>

    {{-- Lọc theo địa bàn --}}
    <form method="GET" action="{{ route('admin.users.index') }}" class="user-location-filter bg-white border rounded-2 p-2 mb-3">
        @include('partials.location-picker', [
            'pickerId' => 'user-list-location',
            'provinces' => $provinces,
            'provinceCode' => $provinceCode,
            'communeCode' => $communeCode,
            'villageCode' => $villageCode,
            'showVillage' => true,
            'inline' => true,
        ])
        <button class="btn btn-sm btn-outline-primary" type="submit">
            <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Lọc địa bàn
        </button>
        @if($isFiltering && $actor->hasRole(\App\Models\Role::SYSTEM_ADMIN))
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.index') }}">Bỏ lọc</a>
        @endif
    </form>

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
                    aria-expanded="true"
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
                <div class="tree-body open">

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
                                        aria-expanded="true"
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
                                    <div class="tree-body open">

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
                                                            aria-expanded="true"
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
                                                        <div class="tree-body open">

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

</div>
@endsection


@push('scripts')
<script>

    function toggleNode(header) {

        const body = header.nextElementSibling;

        const isOpen =
            header.getAttribute('aria-expanded') === 'true';

        header.setAttribute(
            'aria-expanded',
            !isOpen
        );

        if (isOpen) {

            body.classList.remove('open');

        } else {

            body.classList.add('open');

        }
    }


    function toggleAllNodes(expand) {

        document
            .querySelectorAll('.tree-header')
            .forEach(header => {

                const body =
                    header.nextElementSibling;

                header.setAttribute(
                    'aria-expanded',
                    expand
                );

                if (expand) {

                    body.classList.add('open');

                } else {

                    body.classList.remove('open');

                }

            });
    }

</script>
@endpush