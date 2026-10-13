<div class="role-members-table-scroll">
    <table class="table table-sm table-hover align-middle mb-0">
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
                        <div>{{ ['global' => 'Trung ương', 'province' => 'Tỉnh', 'commune' => 'Xã', 'village' => 'Thôn / xóm'][$member->scope_level] ?? 'Chưa xác định' }}</div>
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
                    <td colspan="5" class="text-center text-muted py-3">Nhóm này chưa có thành viên.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($members->hasPages())
    <nav class="d-flex align-items-center justify-content-end gap-2 mt-2" aria-label="Phân trang thành viên nhóm">
        @if ($members->onFirstPage())
            <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Trước</span>
        @else
            <button
                class="btn btn-sm btn-outline-secondary"
                type="button"
                data-role-members-page
                data-url="{{ $members->previousPageUrl() }}"
                aria-label="Trang trước"
            >Trước</button>
        @endif
        <label class="visually-hidden" for="role-members-page-{{ $role->id }}">Chọn trang thành viên</label>
        <select
            id="role-members-page-{{ $role->id }}"
            class="form-select form-select-sm role-members-page-select"
            aria-label="Chọn trang thành viên"
            data-role-members-page
        >
            @for ($page = 1; $page <= $members->lastPage(); $page++)
                <option value="{{ $members->url($page) }}" @selected($page === $members->currentPage())>
                    {{ $page }}
                </option>
            @endfor
        </select>
        <span class="small text-muted">/ {{ $members->lastPage() }}</span>
        @if ($members->hasMorePages())
            <button
                class="btn btn-sm btn-outline-secondary"
                type="button"
                data-role-members-page
                data-url="{{ $members->nextPageUrl() }}"
                aria-label="Trang sau"
            >Sau</button>
        @else
            <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Sau</span>
        @endif
    </nav>
@endif
