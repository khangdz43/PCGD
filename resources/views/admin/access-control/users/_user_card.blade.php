{{-- User Card Partial --}}
<div class="user-card">
    <div class="user-avatar">{{ mb_substr($user->name, 0, 1) }}</div>
    <div class="user-info">
        @php
            $isAdministratorAccount = $user->roles->contains(fn ($role) =>
                in_array($role->scope_level, ['province', 'commune'], true)
                && $role->permissions->contains(fn ($permission) => in_array($permission->name, [
                    \App\Support\AccessPermission::MANAGE_USER_ACCOUNTS,
                    \App\Support\AccessPermission::MANAGE_ACCESS_CONTROL,
                ], true))
            );
        @endphp
        <div class="user-name">
            @if ($isAdministratorAccount)
                <i class="fa-solid fa-crown text-warning me-1" title="Tài khoản quản trị tỉnh/xã" aria-label="Tài khoản quản trị tỉnh/xã"></i>
            @endif
            {{ $user->name }}
        </div>
        <div class="user-email">{{ $user->username }}</div>
        @if($showOrganizationTree)
            <div class="account-location">
                {{ $user->province?->name ?? 'Chưa gán tỉnh' }}
                @if($user->commune?->name)
                    · {{ $user->commune->name }}
                @endif
                @if($user->villages->isNotEmpty())
                    · {{ $user->villages->pluck('name')->join(', ') }}
                @endif
            </div>
        @endif
    </div>
    <div class="user-meta">
        @if($user->roles->isNotEmpty())
            @foreach($user->roles as $role)
                <span class="user-role-badge">
                    <i class="fa-solid fa-shield-halved me-1"></i>{{ $role->name }}
                </span>
            @endforeach
        @else
                <span class="user-role-badge user-role-empty">
                <i class="fa-solid fa-circle-exclamation me-1"></i>Chưa gán nhóm
            </span>
        @endif
            <span class="badge user-status {{ $user->is_active ? 'user-status-active' : 'user-status-inactive' }}">
            <i class="fa-solid {{ $user->is_active ? 'fa-circle-check' : 'fa-ban' }} me-1"></i>{{ $user->is_active ? 'Hoạt động' : 'Đã khóa' }}
        </span>
    </div>
    @unless($showOrganizationTree)
        <div class="user-actions">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.edit', $user) }}" title="Chỉnh sửa">
                <i class="fa-solid fa-pen-to-square"></i>
            </a>
            <form class="d-inline" method="POST" action="{{ route('admin.users.status', $user) }}"
                  data-confirm="{{ $user->is_active ? 'Thu hồi quyền đăng nhập của tài khoản này?' : 'Kích hoạt lại tài khoản này?' }}"
                  data-confirm-title="Xác nhận thay đổi trạng thái">
                @csrf
                @method('PATCH')
                <input type="hidden" name="is_active" value="{{ $user->is_active ? '0' : '1' }}">
                <button class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit"
                        title="{{ $user->is_active ? 'Thu hồi' : 'Kích hoạt' }}">
                    <i class="fa-solid {{ $user->is_active ? 'fa-lock' : 'fa-lock-open' }}"></i>
                </button>
            </form>
        </div>
    @endunless
</div>
