@extends('layouts.app')

@section('title', 'Thành viên nhóm')

@section('content')
<div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <a class="text-decoration-none" href="{{ route('admin.roles.index') }}">← Nhóm người dùng</a>
            <h1 class="h3 mt-2 mb-1">Thành viên nhóm</h1>
            <div class="text-muted">
                <code>{{ $role->name }}</code>
                <span class="ms-2">{{ $members->total() }} thành viên</span>
            </div>
        </div>
    </div>

    <div class="table-responsive bg-white border rounded shadow-sm">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Họ và tên</th>
                    <th scope="col">Tên đăng nhập</th>
                    <th scope="col">Cấp / địa bàn</th>
                    <th scope="col">Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr>
                        <td>{{ $members->firstItem() + $loop->index }}</td>
                        <td class="fw-semibold">{{ $member->name }}</td>
                        <td>{{ $member->username }}</td>
                        <td>
                            <div>{{ ['province' => 'Tỉnh', 'commune' => 'Xã', 'village' => 'Thôn / xóm'][$member->scope_level] ?? 'Chưa xác định' }}</div>
                            <small class="text-muted">
                                {{ $member->province?->name ?? '—' }}
                                @if ($member->commune)
                                    · {{ $member->commune->name }}
                                @endif
                                @if ($member->villages->isNotEmpty())
                                    · {{ $member->villages->pluck('name')->join(', ') }}
                                @endif
                            </small>
                        </td>
                        <td>
                            @if ($member->is_active)
                                <span class="badge text-bg-success">Đang hoạt động</span>
                            @else
                                <span class="badge text-bg-secondary">Đã vô hiệu hóa</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            Nhóm này chưa có thành viên.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($members->hasPages())
        <div class="mt-3">{{ $members->links('vendor.pagination.bootstrap-5') }}</div>
    @endif
</div>
@endsection
