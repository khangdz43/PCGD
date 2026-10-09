<form method="POST" action="{{ $action }}" class="bg-white border rounded shadow-sm p-4">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="mb-3">
        <label for="name" class="form-label">Tên nhóm người dùng / phòng ban</label>
        @if ($role->name === \App\Models\Role::SYSTEM_ADMIN)
            <input type="hidden" name="name" value="{{ $role->name }}">
            <input id="name" class="form-control" value="{{ $role->name }}" disabled>
            <div class="form-text">Mã nhóm quản trị hệ thống không thể thay đổi.</div>
        @else
            <input id="name" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name', $role->name) }}" maxlength="50" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @endif
    </div>

    <div class="mb-3">
        <label for="description" class="form-label">Mô tả</label>
        <textarea id="description" class="form-control @error('description') is-invalid @enderror" name="description" maxlength="255" rows="2">{{ old('description', $role->description) }}</textarea>
        <div class="form-text">Ví dụ: Phòng nhập liệu — được xem, tạo và cập nhật phiếu điều tra.</div>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>



    @if ($actor->hasRole(\App\Models\Role::SYSTEM_ADMIN))
        <div class="app-inline-note mb-3" role="note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>
            Tạo nhóm cấp Tỉnh để khởi tạo tài khoản tỉnh. Sau đó, mỗi tài khoản tỉnh và xã sẽ tự tạo nhóm quyền cho cấp dưới trực tiếp của mình.
            </span>
        </div>
    @endif

    <fieldset
        class="mb-4"
        data-default-scope="{{ $role->name === \App\Models\Role::SYSTEM_ADMIN ? 'global' : ($scopeLevel ?: 'global') }}"
    >
        @if ($role->name !== \App\Models\Role::SYSTEM_ADMIN)
            <div class="mb-3">
                <label for="scope_level" class="form-label">{{ $actor->hasRole(\App\Models\Role::SYSTEM_ADMIN) ? 'Phạm vi sử dụng của nhóm' : 'Cấp áp dụng của nhóm' }}</label>
                <select id="scope_level" class="form-select @error('scope_level') is-invalid @enderror" name="scope_level" required>
                    @foreach ($scopeLevels as $level)
                        <option value="{{ $level }}" @selected($scopeLevel === $level)>
                            {{ ['province' => 'Tỉnh', 'commune' => 'Xã', 'village' => 'Thôn / xóm'][$level] ?? $level }}
                        </option>
                    @endforeach
                </select>
                @error('scope_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <legend class="fs-6">Quyền của nhóm</legend>
        <p class="form-text">
            @if ($actor->hasRole(\App\Models\Role::SYSTEM_ADMIN))
                Chọn quyền phù hợp với phạm vi nhóm. Quyền thao tác phiếu tự kèm quyền xem.
            @else
                Bạn có thể tạo nhóm cùng cấp hoặc cấp dưới trực tiếp trong địa bàn quản lý. Nhóm cùng cấp phải có ít quyền hơn nhóm của bạn; không thể sao chép toàn bộ quyền. Quyền thao tác phiếu tự kèm quyền xem.
            @endif
        </p>
        @php
            $selected = collect(old('permissions', $role->exists ? $role->permissions->modelKeys() : []))
                ->map(fn ($id) => (int) $id)
                ->all();
            $householdPermissions = collect(\App\Support\AccessPermission::householdPermissions());
            $selectedHouseholdPermissionIds = $permissions
                ->whereIn('name', $householdPermissions)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
            $householdGroupSelected = in_array('households', (array) old('permission_groups', []), true)
                || (
                    $selectedHouseholdPermissionIds->isNotEmpty()
                    && $selectedHouseholdPermissionIds->diff($selected)->isEmpty()
                );
            $permissionGroupLabels = [
                \App\Support\AccessPermission::MANAGE_VILLAGES => 'Danh mục địa bàn và trường học',
                \App\Support\AccessPermission::MANAGE_SCHOOLS => 'Danh mục địa bàn và trường học',
                \App\Support\AccessPermission::MANAGE_ACCESS_CONTROL => 'Quản trị nhóm và tài khoản',
                \App\Support\AccessPermission::MANAGE_USER_ACCOUNTS => 'Quản trị nhóm và tài khoản',
            ];
            $householdGroupPermissions = $permissions->whereIn('name', $householdPermissions);
            $permissionGroups = $permissions
                ->reject(fn ($permission) => $householdPermissions->contains($permission->name))
                ->groupBy(fn ($permission) => $permissionGroupLabels[$permission->name] ?? 'Quyền khác');
        @endphp
        @if ($permissions->isNotEmpty())
            <div class="table-responsive border rounded">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-center" style="width: 5rem;">Chọn</th>
                            <th scope="col">Mô tả quyền</th>
                        </tr>
                    </thead>
                    @if ($householdGroupPermissions->isNotEmpty())
                        <tbody data-permission-group>
                            <tr class="table-secondary">
                                <th colspan="2" scope="rowgroup">Quản trị về nhân khẩu và phiếu</th>
                            </tr>
                            <tr @class(['d-none' => $scopeLevel === 'village']) data-group-control data-scopes="{{ $role->name === \App\Models\Role::SYSTEM_ADMIN ? 'global' : 'commune' }}">
                                <td class="text-center">
                                    <input
                                        id="permission-group-households"
                                        class="form-check-input m-0"
                                        type="checkbox"
                                        name="permission_groups[]"
                                        value="households"
                                        @checked($householdGroupSelected)
                                    >
                                </td>
                                <td>
                                    <label class="form-label mb-0 fw-semibold" for="permission-group-households">
                                        Quản trị về nhân khẩu và phiếu
                                    </label>
                                </td>
                            </tr>
                            @foreach ($householdGroupPermissions as $permission)
                                @php
                                    $permissionScopes = $role->name === \App\Models\Role::SYSTEM_ADMIN
                                        ? ['global']
                                        : array_values(array_filter(
                                            $scopeLevels,
                                            fn (string $level): bool => in_array($permission->name, \App\Support\AccessPermission::permissionsForScope($level), true)
                                        ));
                                @endphp
                                <tr @class(['household-detail-row', 'd-none' => $scopeLevel !== 'village']) data-permission-row data-household-permission="true" data-scopes="{{ implode(',', $permissionScopes) }}">
                                    <td class="text-center">
                                        <input
                                            id="permission-{{ $permission->id }}"
                                            class="form-check-input permission-checkbox m-0"
                                            type="checkbox"
                                            name="permissions[]"
                                            value="{{ $permission->id }}"
                                            data-permission-name="{{ $permission->name }}"
                                            @checked($householdGroupSelected || in_array((int) $permission->id, $selected, true))
                                        >
                                    </td>
                                    <td>
                                        <label class="form-label mb-0 fw-semibold" for="permission-{{ $permission->id }}">
                                            {{ $permission->description ?: 'Chưa có mô tả' }}
                                        </label>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif
                    @foreach ($permissionGroups as $groupName => $groupPermissions)
                        <tbody data-permission-group>
                            <tr class="table-secondary">
                                <th colspan="2" scope="rowgroup">{{ $groupName }}</th>
                            </tr>
                            @foreach ($groupPermissions as $permission)
                                @php
                                    $permissionScopes = $role->name === \App\Models\Role::SYSTEM_ADMIN
                                        ? ['global']
                                        : array_values(array_filter(
                                            $scopeLevels,
                                            fn (string $level): bool => in_array($permission->name, \App\Support\AccessPermission::permissionsForScope($level), true)
                                        ));
                                    $isSelected = in_array($permission->id, $selected, true);
                                @endphp
                                <tr data-permission-row data-scopes="{{ implode(',', $permissionScopes) }}">
                                    <td class="text-center">
                                        <input
                                            id="permission-{{ $permission->id }}"
                                            class="form-check-input permission-checkbox m-0"
                                            type="checkbox"
                                            name="permissions[]"
                                            value="{{ $permission->id }}"
                                            data-permission-name="{{ $permission->name }}"
                                            @checked($isSelected)
                                        >
                                    </td>
                                    <td>
                                        <label class="form-label mb-0 fw-semibold" for="permission-{{ $permission->id }}">
                                            {{ $permission->description ?: 'Chưa có mô tả' }}
                                        </label>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        @else
                <p class="text-muted">Chưa khai báo quyền. Hãy tạo quyền trước.</p>
        @endif
        @error('permissions')<div class="text-danger small">{{ $message }}</div>@enderror
    </fieldset>

    <button class="btn btn-primary" type="submit">Lưu</button>
    <a class="btn btn-outline-secondary" href="{{ route('admin.roles.index') }}">Quay lại</a>
</form>

@push('scripts')
<script>
    (() => {
        const checkboxes = [...document.querySelectorAll('.permission-checkbox')];
        const scopeLevel = document.getElementById('scope_level');
        const defaultScope = document.querySelector('[data-default-scope]')?.dataset.defaultScope || 'global';
        const rows = [...document.querySelectorAll('[data-permission-row]')];
        const groups = [...document.querySelectorAll('[data-permission-group]')];

        function filterPermissions() {
            const level = scopeLevel?.value || defaultScope;
            const householdControl = document.querySelector('[data-group-control]');
            if (householdControl) {
                const allowedScopes = householdControl.dataset.scopes.split(',').filter(Boolean);
                const groupVisible = allowedScopes.includes(level) && level !== 'village';
                const groupInput = householdControl.querySelector('input');
                householdControl.classList.toggle('d-none', !groupVisible);
                groupInput.disabled = !groupVisible;
            }
            rows.forEach((row) => {
                const allowedScopes = row.dataset.scopes.split(',').filter(Boolean);
                const allowed = allowedScopes.includes(level);
                const visible = row.dataset.householdPermission !== 'true' || level === 'village';
                const checkbox = row.querySelector('.permission-checkbox');
                row.classList.toggle('d-none', !visible || !allowed);
                checkbox.disabled = !allowed;
                if (!allowed) checkbox.checked = false;
            });

            groups.forEach((group) => {
                const hasVisiblePermission = group.querySelector(
                    '[data-permission-row]:not(.d-none), [data-group-control]:not(.d-none)'
                );
                group.classList.toggle('d-none', !hasVisiblePermission);
            });
            updateHouseholdMaster();
        }

        function updateHouseholdMaster() {
            const master = document.querySelector('[data-group-control] input');
            if (!master) return;

            const level = scopeLevel?.value || defaultScope;
            const allowedPermissions = checkboxes.filter((checkbox) => {
                const row = checkbox.closest('[data-household-permission]');
                return row && row.dataset.scopes.split(',').includes(level);
            });
            const checkedCount = allowedPermissions.filter((checkbox) => checkbox.checked).length;
            master.checked = allowedPermissions.length > 0 && checkedCount === allowedPermissions.length;
            master.indeterminate = checkedCount > 0 && checkedCount < allowedPermissions.length;
        }

        const householdMaster = document.querySelector('[data-group-control] input');
        householdMaster?.addEventListener('change', () => {
            const level = scopeLevel?.value || defaultScope;
            checkboxes.forEach((checkbox) => {
                const row = checkbox.closest('[data-household-permission]');
                if (row && row.dataset.scopes.split(',').includes(level)) {
                    checkbox.checked = householdMaster.checked;
                }
            });
            householdMaster.indeterminate = false;
        });

        checkboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                if (checkbox.closest('[data-household-permission]')) {
                    updateHouseholdMaster();
                }
            });
        });

        scopeLevel?.addEventListener('change', filterPermissions);
        filterPermissions();
    })();
</script>
@endpush
