@extends('layouts.app')

@section('title', 'Quản lý nhóm người dùng')

@push('styles')
<style>
    /* ===== Scope Level Colors ===== */
    :root {
        --scope-global: #64748b;
        --scope-province: #64748b;
        --scope-commune: #64748b;
        --scope-village: #64748b;
    }

    /* ===== Scope Group Node ===== */
    .scope-node {
        border: 1px solid #dfe4eb;
        border-radius: .5rem;
        margin-bottom: .55rem;
        overflow: hidden;
        background: #fff;
        transition: border-color .15s ease;
    }

    .scope-node.global {
        border-left: 2px solid #cbd5e1;
    }

    .scope-node.province {
        border-left: 2px solid #cbd5e1;
    }

    .scope-node.commune {
        border-left: 2px solid #cbd5e1;
    }

    .scope-node.village {
        border-left: 2px solid #cbd5e1;
    }

    /* ===== Scope Header ===== */
    .scope-header {
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: .65rem .85rem;
        cursor: pointer;
        user-select: none;
        transition: background-color .15s ease;
    }

    .scope-header:hover {
        background: #f6f8fa;
    }

    .scope-header .toggle-icon {
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

    .scope-header[aria-expanded="true"] .toggle-icon {
        transform: rotate(90deg);
    }

    .scope-header .scope-icon {
        width: 1.8rem;
        height: 1.8rem;
        border-radius: .35rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .85rem;
        color: #475569;
        background: #f1f4f7;
        flex-shrink: 0;
    }

    .scope-icon.global {
        background: #f1f4f7;
    }

    .scope-icon.province {
        background: #f1f4f7;
    }

    .scope-icon.commune {
        background: #f1f4f7;
    }

    .scope-icon.village {
        background: #f1f4f7;
    }

    .scope-header .scope-title {
        font-weight: 700;
        color: #273449;
        font-size: .92rem;
    }

    .scope-header .scope-desc {
        font-size: .8rem;
        color: #64748b;
        margin-top: 1px;
    }

    .scope-header .scope-badge {
        margin-left: auto;
        font-size: .78rem;
        padding: .25rem .75rem;
        border-radius: 999px;
        font-weight: 600;
    }

    .scope-badge.global {
        background: #f1f3f5;
        color: #475569;
    }

    .scope-badge.province {
        background: #f1f3f5;
        color: #475569;
    }

    .scope-badge.commune {
        background: #f1f3f5;
        color: #475569;
    }

    .scope-badge.village {
        background: #f1f3f5;
        color: #475569;
    }

    /* ===== Collapse Body ===== */
    .scope-body {
        display: none;
    }

    .scope-body.open {
        display: block;
    }

    .scope-body-inner {
        padding: 0 .85rem .7rem;
    }

    /* ===== Role Card ===== */
    .role-card {
        display: flex;
        align-items: center;
        gap: .7rem;
        padding: .65rem .75rem;
        border: 1px solid #e5e9ef;
        border-radius: .4rem;
        margin-bottom: .4rem;
        background: #fff;
        transition: background-color .15s ease, border-color .15s ease;
    }

    .role-card:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
    }

    .role-icon-wrap {
        width: 2.25rem;
        height: 2.25rem;
        border-radius: .4rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .9rem;
        color: #475569;
        background: #eef2f6;
        flex-shrink: 0;
    }

    /* Dynamic Scope Colors for Role Card Icon */
    .role-icon-wrap.global {
        background: #eef2f6;
    }

    .role-icon-wrap.province {
        background: #eef2f6;
    }

    .role-icon-wrap.commune {
        background: #eef2f6;
    }

    .role-icon-wrap.village {
        background: #eef2f6;
    }

    .role-info {
        flex: 1;
        min-width: 0;
    }

    .role-name {
        font-weight: 700;
        color: #273449;
        font-size: .9rem;
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .role-name code {
        font-size: .85rem;
        padding: .1rem .45rem;
        background: #f1f5f9;
        border-radius: .3rem;
        color: #334155;
        border: 1px solid #e2e8f0;
    }

    .role-desc {
        font-size: .82rem;
        color: #64748b;
        margin-top: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Permission tags */
    .role-permissions {
        display: flex;
        flex-wrap: wrap;
        gap: .3rem;
        margin-top: .4rem;
    }

    .perm-tag {
        font-size: .68rem;
        padding: .15rem .5rem;
        border-radius: 999px;
        font-weight: 500;
        background: #f5f6f8;
        color: #475569;
        border: 1px solid #e1e5eb;
        white-space: nowrap;
    }

    .perm-tag.more {
        background: #f1f3f5;
        color: #59636e;
        border-color: #e0e4e8;
        cursor: help;
    }

    /* Member count */
    .role-members {
        flex-shrink: 0;
    }

    .role-members a {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-size: .82rem;
        font-weight: 600;
        padding: .35rem .75rem;
        border-radius: .45rem;
        background: #f1f5f9;
        color: #475569;
        text-decoration: none;
        border: 1px solid #e2e8f0;
        transition: background-color .15s ease, border-color .15s ease;
    }

    .role-members a:hover {
        background: #e9edf2;
        color: #334155;
        border-color: #cbd5e1;
    }

    /* Actions */
    .role-actions {
        display: flex;
        gap: .35rem;
        flex-shrink: 0;
    }

    .role-actions .btn {
        font-size: .78rem;
        padding: .25rem .65rem;
        border-radius: .4rem;
    }

    /* ===== Tree Controls ===== */
    .tree-controls {
        display: flex;
        gap: .5rem;
    }

    .tree-controls .btn {
        font-size: .8rem;
        padding: .3rem .75rem;
        border-radius: .4rem;
    }

    /* ===== Responsive ===== */
    @media (max-width: 768px) {
        .role-card {
            flex-wrap: wrap;
        }

        .role-members,
        .role-actions {
            width: 100%;
            justify-content: flex-end;
            margin-top: .35rem;
        }
    }
</style>
@endpush

@section('content')
<div class="container">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">
                <i class="fa-solid fa-layer-group text-primary me-2"></i>Nhóm người dùng / Phòng ban
            </h1>
            <p class="text-muted mb-0" style="font-size:.88rem">Phân cấp theo phạm vi hoạt động</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.roles.create') }}">
            <i class="fa-solid fa-plus me-1"></i> Thêm nhóm người dùng
        </a>
    </div>

    @php
    $scopeConfig = [
    'global' => ['label' => 'Trung ương', 'icon' => 'fa-globe', 'desc' => 'Quản trị toàn hệ thống', 'limit' => 4],
    'province' => ['label' => 'Cấp Tỉnh', 'icon' => 'fa-city', 'desc' => 'Quản lý cấp tỉnh / thành phố', 'limit' => 3],
    'commune' => ['label' => 'Cấp Xã', 'icon' => 'fa-building-columns', 'desc' => 'Quản lý cấp xã / phường', 'limit' => 2],
    'village' => ['label' => 'Cấp Thôn', 'icon' => 'fa-house-chimney', 'desc' => 'Quản lý cấp thôn / bản / xóm', 'limit' => 0],
    ];

    // Normalization Pipeline: Map NULL / empty keys in DB to 'global'
    $normalizedRolesByScope = $rolesByScope->keyBy(function ($items, $key) {
    $stringKey = strtolower((string)$key);
    return ($stringKey === '' || $stringKey === 'null') ? 'global' : $stringKey;
    });
    @endphp

    {{-- Controls --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <span class="text-muted" style="font-size:.85rem">
            <i class="fa-solid fa-shield-halved me-1"></i>Tổng cộng <strong>{{ $roles->count() }}</strong> nhóm &middot; <strong>{{ $roles->sum('users_count') }}</strong> thành viên
        </span>
        <div class="tree-controls">
            <button type="button" class="btn btn-outline-secondary" onclick="toggleAllScopes(true)">
                <i class="fa-solid fa-angles-down me-1"></i>Mở tất cả
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="toggleAllScopes(false)">
                <i class="fa-solid fa-angles-up me-1"></i>Thu gọn
            </button>
        </div>
    </div>

    {{-- Tree --}}
    <div class="roles-tree">
        @foreach($scopeConfig as $scope => $cfg)
        @if($normalizedRolesByScope->has($scope))
        @php
        $scopeRoles = $normalizedRolesByScope->get($scope);
        $permLimit = $cfg['limit'];
        @endphp
        <div class="scope-node {{ $scope }}">
            <div class="scope-header" aria-expanded="false" onclick="toggleScope(this)">
                <span class="toggle-icon"><i class="fa-solid fa-chevron-right"></i></span>
                <span class="scope-icon {{ $scope }}"><i class="fa-solid {{ $cfg['icon'] }}"></i></span>
                <div>
                    <div class="scope-title">{{ $cfg['label'] }}</div>
                    <div class="scope-desc">{{ $cfg['desc'] }}</div>
                </div>
                <span class="scope-badge {{ $scope }}">
                    <i class="fa-solid fa-shield-halved me-1"></i>{{ $scopeRoles->count() }} nhóm &middot; {{ $scopeRoles->sum('users_count') }} thành viên
                </span>
            </div>
            <div class="scope-body">
                <div class="scope-body-inner">
                    @foreach($scopeRoles as $role)
                    <div class="role-card">
                        <div class="role-icon-wrap {{ $scope }}">
                            <i class="fa-solid {{ $cfg['icon'] }}"></i>
                        </div>
                        <div class="role-info">
                            <div class="role-name">
                                <code>{{ $role->name }}</code>
                                @if($role->name === \App\Models\Role::SYSTEM_ADMIN)
                                <span class="badge text-bg-secondary" style="font-size:.68rem">Admin</span>
                                @endif
                            </div>
                            <div class="role-desc">{{ $role->description ?: 'Chưa có mô tả' }}</div>

                            @if($permLimit > 0 && $role->permissions->isNotEmpty())
                            <div class="role-permissions">
                                @foreach($role->permissions->take($permLimit) as $perm)
                                <span class="perm-tag">
                                    <i class="fa-solid fa-key" style="font-size:.55rem;margin-right:2px"></i>
                                    {{ $perm->description ?: $perm->name }}
                                </span>
                                @endforeach
                                @if($role->permissions->count() > $permLimit)
                                <span class="perm-tag more" title="{{ $role->permissions->skip($permLimit)->map(fn($p) => $p->description ?: $p->name)->join(', ') }}">
                                    +{{ $role->permissions->count() - $permLimit }} quyền khác
                                </span>
                                @endif
                            </div>
                            @endif
                        </div>
                        <div class="role-members">
                            <a href="{{ route('admin.roles.members', $role) }}" title="Xem thành viên">
                                <i class="fa-solid fa-users"></i>
                                {{ $role->users_count }} thành viên
                            </a>
                        </div>
                        <div class="role-actions">
                            @if ($actor->hasRole(\App\Models\Role::SYSTEM_ADMIN) || $role->created_by === $actor->id)
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.roles.edit', $role) }}" title="Chỉnh sửa">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                            @if ($role->name !== \App\Models\Role::SYSTEM_ADMIN)
                            <form class="d-inline" method="POST" action="{{ route('admin.roles.destroy', $role) }}" data-confirm="Xóa nhóm người dùng này?" data-confirm-title="Xác nhận xóa nhóm người dùng">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                            @endif
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
        @endforeach

        @if($roles->isEmpty())
        <div class="text-center py-5">
            <i class="fa-regular fa-folder-open text-muted" style="font-size:3rem"></i>
            <p class="text-muted mt-3 mb-0">Chưa có nhóm người dùng nào.</p>
        </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        window.toggleScope = (header) => {
            const body = header.nextElementSibling;
            const isOpen = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', !isOpen);
            if (isOpen) {
                body.classList.remove('open');
            } else {
                body.classList.add('open');
            }
        }

        window.toggleAllScopes = (expand) => {
            document.querySelectorAll('.scope-header').forEach(header => {
                const body = header.nextElementSibling;
                header.setAttribute('aria-expanded', expand);
                if (expand) {
                    body.classList.add('open');
                } else {
                    body.classList.remove('open');
                }
            });
        }
    })();
</script>
@endpush