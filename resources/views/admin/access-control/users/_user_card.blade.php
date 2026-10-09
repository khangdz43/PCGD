{{-- User Card Partial --}}
<div class="user-card">
    <div class="user-avatar">{{ mb_substr($user->name, 0, 1) }}</div>
    <div class="user-info">
        <div class="user-name">{{ $user->name }}</div>
        <div class="user-email">{{ $user->username }}</div>
    </div>
    <div class="user-meta">
        @if($user->roles->isNotEmpty())
            @foreach($user->roles as $role)
                <span class="user-role-badge">
                    <i class="fa-solid fa-shield-halved me-1"></i>{{ $role->name }}
                </span>
            @endforeach
        @else
            <span class="user-role-badge" style="background:#fef3c7;color:#92400e;border-color:#fde68a">
                <i class="fa-solid fa-circle-exclamation me-1"></i>Chưa gán nhóm
            </span>
        @endif
        <span class="badge {{ $user->is_active ? 'bg-success' : 'bg-secondary' }}" style="font-size:.72rem">
            <i class="fa-solid {{ $user->is_active ? 'fa-circle-check' : 'fa-ban' }} me-1"></i>{{ $user->is_active ? 'Hoạt động' : 'Đã khóa' }}
        </span>
    </div>
    <div class="user-actions">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.edit', $user) }}" title="Chỉnh sửa">
            <i class="fa-solid fa-pen-to-square"></i>
        </a>
        <form class="d-inline" method="POST" action="{{ route('admin.users.status', $user) }}"
              onsubmit="return confirm('{{ $user->is_active ? 'Thu hồi quyền đăng nhập của tài khoản này?' : 'Kích hoạt lại tài khoản này?' }}')">
            @csrf
            @method('PATCH')
            <input type="hidden" name="is_active" value="{{ $user->is_active ? '0' : '1' }}">
            <button class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit"
                    title="{{ $user->is_active ? 'Thu hồi' : 'Kích hoạt' }}">
                <i class="fa-solid {{ $user->is_active ? 'fa-lock' : 'fa-lock-open' }}"></i>
            </button>
        </form>
    </div>
</div>
