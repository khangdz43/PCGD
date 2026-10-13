{{-- User Card Partial --}}
@php
    $isCentralAccount = $user->roleScopeLevel() === 'global';
@endphp
<div @class(['user-card', 'user-card-central' => $isCentralAccount])>
    <div class="user-avatar">{{ mb_substr($user->name, 0, 1) }}</div>
    <div class="user-info">
        @php
            $administratorRoles = $user->roles->filter(fn ($role) =>
                in_array($role->scope_level, ['global', 'province', 'commune'], true)
                && $role->permissions->contains(fn ($permission) => in_array($permission->name, [
                    \App\Support\AccessPermission::MANAGE_USER_ACCOUNTS,
                    \App\Support\AccessPermission::MANAGE_ACCESS_CONTROL,
                ], true))
            );
            $administratorScope = $administratorRoles->contains(fn ($role) => $role->scope_level === 'global')
                ? 'global'
                : ($administratorRoles->contains(fn ($role) => $role->scope_level === 'province')
                    ? 'province'
                    : ($administratorRoles->isNotEmpty() ? 'commune' : null));
        @endphp
        <div class="user-name">
            @if ($administratorScope === 'global')
                <i class="fa-solid fa-crown text-danger me-1" title="Tài khoản quản trị trung ương" aria-label="Tài khoản quản trị trung ương"></i>
            @elseif ($administratorScope === 'province')
                <i class="fa-solid fa-crown text-warning me-1" title="Tài khoản quản trị tỉnh" aria-label="Tài khoản quản trị tỉnh"></i>
            @elseif ($administratorScope === 'commune')
                <i class="fa-solid fa-crown text-primary me-1" title="Tài khoản quản trị xã" aria-label="Tài khoản quản trị xã"></i>
            @endif
            {{ $user->name }}
            @if ($isCentralAccount)
                <span class="account-scope-badge">Trung ương</span>
            @endif
        </div>
        <div class="user-email">{{ $user->username }}</div>
        @if($showOrganizationTree)
            <div class="account-location">
                @if($user->roleScopeLevel() === 'global')
                    Trung ương
                @else
                    {{ $user->province?->name ?? 'Chưa gán tỉnh' }}
                @if($user->commune?->name)
                    · {{ $user->commune->name }}
                @endif
                @if($user->villages->isNotEmpty())
                    · {{ $user->villages->pluck('name')->join(', ') }}
                @endif
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
    @if(!$showOrganizationTree || $user->can_change_account_status || $user->can_reset_account_password)
        <div class="user-actions">
            @unless($showOrganizationTree)
                <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.edit', $user) }}" title="Chỉnh sửa">
                    <i class="fa-solid fa-pen-to-square"></i>
                </a>
            @endunless
            @if($user->can_change_account_status)
                <form class="d-inline" method="POST" action="{{ route('admin.users.status', $user) }}"
                      data-confirm="{{ $user->is_active ? 'Thu hồi quyền đăng nhập của tài khoản này?' : 'Kích hoạt lại tài khoản này?' }}"
                      data-confirm-title="Xác nhận thay đổi trạng thái">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="is_active" value="{{ $user->is_active ? '0' : '1' }}">
                    <button class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit"
                            title="{{ $user->is_active ? 'Thu hồi tài khoản' : 'Kích hoạt tài khoản' }}"
                            aria-label="{{ $user->is_active ? 'Thu hồi tài khoản ' : 'Kích hoạt tài khoản ' }}{{ $user->name }}">
                        <i class="fa-solid {{ $user->is_active ? 'fa-lock' : 'fa-lock-open' }}" aria-hidden="true"></i>
                    </button>
                </form>
            @endif
            @if($user->can_reset_account_password)
                <form class="d-inline" method="POST" action="{{ route('admin.users.reset-password', $user) }}"
                      data-confirm="Đặt lại mật khẩu của tài khoản này về {{ \App\Models\User::TEMPORARY_PASSWORD }}?"
                      data-confirm-title="Xác nhận đặt lại mật khẩu">
                    @csrf
                    <button class="btn btn-sm btn-outline-warning" type="submit"
                            title="Đặt lại mật khẩu"
                            aria-label="Đặt lại mật khẩu tài khoản {{ $user->name }}">
                        <i class="fa-solid fa-key" aria-hidden="true"></i>
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>
