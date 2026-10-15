<form method="POST" action="{{ $action }}" class="bg-white border rounded shadow-sm p-4">
    @csrf

    @if ($method !== 'POST')
    @method($method)
    @endif

    {{-- =========================================================
        THÔNG TIN TÀI KHOẢN
    ========================================================== --}}

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="name" class="form-label">Họ và tên</label>

            <input
                id="name"
                class="form-control @error('name') is-invalid @enderror"
                name="name"
                value="{{ old('name', $user->name) }}"
                required
                maxlength="255">

            @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label for="username" class="form-label">Tên đăng nhập</label>

            <input
                id="username"
                type="text"
                class="form-control @error('username') is-invalid @enderror"
                name="username"
                value="{{ old('username', $user->username) }}"
                required
                maxlength="255"
                autocomplete="username">

            @error('username')
            <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>


    {{-- =========================================================
        MẬT KHẨU
    ========================================================== --}}

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="password" class="form-label">
                {{ $method === 'POST'
                    ? 'Mật khẩu (ít nhất 12 ký tự)'
                    : 'Mật khẩu mới (để trống nếu không đổi)' }}
            </label>

            <input
                id="password"
                type="password"
                class="form-control @error('password') is-invalid @enderror"
                name="password"
                @required($method==='POST' )
                autocomplete="new-password">

            @error('password')
            <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label for="password_confirmation" class="form-label">
                Nhập lại mật khẩu
            </label>

            <input
                id="password_confirmation"
                type="password"
                class="form-control"
                name="password_confirmation"
                @required($method==='POST' )
                autocomplete="new-password">
        </div>
    </div>


    {{-- =========================================================
        PHẠM VI HOẠT ĐỘNG
    ========================================================== --}}

    <fieldset class="mb-3">

        <legend class="form-label mb-1">
            Phạm vi hoạt động
        </legend>

        <p class="form-text">
            @if ($actor->hasRole(\App\Models\Role::SYSTEM_ADMIN))
            Chọn cấp hoạt động và nhóm quyền phù hợp. Tài khoản trung ương chỉ có các quyền được chọn trong nhóm, không có quyền toàn hệ thống.
            @else
            Có thể tạo tài khoản cùng cấp hoặc cấp dưới trực tiếp.
            Tài khoản chỉ hoạt động trong địa bàn được giao và quyền phụ thuộc vào nhóm được chọn.
            @endif
        </p>

        <div id="scopeLevelField" class="mb-3">

            <label for="scope_level" class="form-label">
                Cấp hoạt động
            </label>

            <select
                id="scope_level"
                class="form-select @error('scope_level') is-invalid @enderror"
                name="scope_level"
                required>
                @foreach ($accountLevels as $level)
                <option
                    value="{{ $level }}"
                    @selected($accountScopeLevel===$level)>
                    {{
                                [
                                    'global' => 'Trung ương',
                                    'province' => 'Tỉnh / thành phố',
                                    'commune' => 'Xã / phường',
                                    'village' => 'Thôn / xóm'
                                ][$level]
                            }}
                </option>
                @endforeach
            </select>

            @error('scope_level')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
            @enderror

        </div>

    </fieldset>


    {{-- =========================================================
        NHÓM NGƯỜI DÙNG / PHÒNG BAN
    ========================================================== --}}

    <fieldset class="mb-4">

        <legend class="form-label mb-1">
            Nhóm người dùng / phòng ban
        </legend>

        <p class="form-text mb-2">
            Chọn nhóm quyền do bạn tạo cho đúng cấp hoạt động.
            Tài khoản chỉ nhận quyền trong nhóm và bị giới hạn theo địa bàn được giao.
        </p>

        @php
        $selectedRoleId = (string) old(
        'role_id',
        $user->roles->first()?->id ?? ''
        );
        @endphp

        @if ($roles->isNotEmpty())

        <div class="table-responsive border rounded role-choice-table">

            <table class="table table-sm table-hover align-middle mb-0">

                <thead class="table-light">
                    <tr>
                        <th
                            scope="col"
                            class="text-center role-choice-select-column">
                            Chọn
                        </th>

                        <th scope="col">
                            Nhóm người dùng
                        </th>

                        <th scope="col">
                            Mô tả quyền
                        </th>
                    </tr>
                </thead>

                <tbody>

                    @foreach ($roles as $role)

                    <tr
                        class="role-choice-row
                                    @if ($selectedRoleId === (string) $role->id)
                                        is-selected
                                    @endif"
                        data-role-choice-row
                        data-role-scopes="{{ implode(',', $role->assignable_scope_levels) }}">

                        <td class="text-center">

                            <input
                                id="role-{{ $role->id }}"
                                class="form-check-input role-choice-input m-0"
                                type="radio"
                                name="role_id"
                                value="{{ $role->id }}"
                                @checked($selectedRoleId===(string) $role->id)
                            required
                            >

                        </td>

                        <td>

                            <label
                                class="role-choice-label fw-semibold mb-0"
                                for="role-{{ $role->id }}">
                                {{ $role->name }}
                            </label>

                        </td>

                        <td class="small text-muted">
                            {{ $role->description ?: 'Chưa có mô tả cho nhóm này.' }}
                        </td>

                    </tr>

                    @endforeach

                </tbody>

            </table>

        </div>

        <p
            class="small text-muted mt-2 mb-0 d-none"
            data-no-roles-for-scope>
            Không có nhóm phù hợp với cấp hoạt động đã chọn.
        </p>

        @endif

        @error('role_id')
        <div class="text-danger small mt-2">
            {{ $message }}
        </div>
        @enderror

        @if ($roles->isEmpty())

        <div class="form-text text-danger">
            Chưa có nhóm người dùng phù hợp để gán.
            Hãy nhờ người quản lý cấp trên tạo nhóm.
        </div>

        @endif

    </fieldset>


    {{-- =========================================================
        ĐỊA BÀN ĐƯỢC GIAO
    ========================================================== --}}

    <fieldset class="mb-3" id="territoryFields">

        <legend class="fs-6">
            Địa bàn được giao
        </legend>

        <p class="form-text">

            @if ($actor->hasRole(\App\Models\Role::SYSTEM_ADMIN))

            Bạn có thể chọn tỉnh và địa bàn cần giao. Với tài khoản trung ương, không gán địa bàn tỉnh.

            @elseif ($actor->roleScopeLevel() === 'global')

            Chọn tỉnh chưa có tài khoản quản trị đang hoạt động.

            @elseif ($actor->commune_code)

            Tỉnh và xã được cố định theo địa bàn mà bạn đang phụ trách.
            Bạn chỉ được giao các thôn / xóm thuộc xã này.

            @else

            Tỉnh được cố định theo địa bàn mà bạn đang phụ trách.
            Bạn chỉ được chọn xã thuộc tỉnh này.

            @endif

        </p>

        <p
            id="locationLoadError"
            class="small text-danger"
            role="status"
            aria-live="polite"></p>


        {{-- =====================================================
            TỈNH
        ====================================================== --}}

        <div class="mb-3">

            <label for="province_code" class="form-label">
                Tỉnh
            </label>

            @if ($actor->hasRole(\App\Models\Role::SYSTEM_ADMIN)
                || $actor->roleScopeLevel() === 'global')

            {{-- Tài khoản trung ương được chọn tỉnh trong phạm vi được quản lý. --}}

            @if ($method === 'POST' && $provinces->isEmpty())

            <div class="alert alert-success mb-0" role="status">
            Đã tạo đầy đủ tài khoản quản trị cho các tỉnh/thành phố.
            </div>

            @else

            <select
            id="province_code"
                class="form-select @error('province_code') is-invalid @enderror"
                name="province_code"
                required
                data-except-user="{{ $user->id }}"
                data-actor-level="{{ $actor->hasRole(\App\Models\Role::SYSTEM_ADMIN) ? 'system' : 'global' }}">

                <option value="">
                    Chọn tỉnh
                </option>

                @foreach ($provinces as $province)

                <option
                    value="{{ $province->code }}"
                    @selected(
                    (string) $selectedLocations['province_code']===(string) $province->code
                    )
                    >
                    {{ $province->name }}
                </option>

                @endforeach

            </select>

            @endif

            @else

            {{-- =================================================
                    ADMIN CẤP DƯỚI:

                    TỈNH LẤY LUÔN TỈNH CỦA ACTOR
                ================================================== --}}

            <select
                id="province_code"
                class="form-select @error('province_code') is-invalid @enderror"
                name="province_code"
                required
                data-except-user="{{ $user->id }}"
                data-actor-level="restricted"
                data-fixed="1">

                <option
                    value="{{ $actor->province_code }}"
                    selected>
                    {{ $actor->province?->name ?? $actor->province_code }}
                </option>

            </select>

            @endif

            @error('province_code')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
            @enderror

        </div>


        {{-- =====================================================
            XÃ
        ====================================================== --}}

        <div
            id="communeField"
            class="mb-3 d-none">

            <label for="commune_code" class="form-label">
                Xã
            </label>

            @if ($actor->commune_code)

            {{-- =================================================
                    ADMIN CẤP XÃ:

                    XÃ LẤY LUÔN XÃ CỦA ACTOR
                ================================================== --}}

            <select
                id="commune_code"
                class="form-select @error('commune_code') is-invalid @enderror"
                name="commune_code"
                required
                data-selected="{{ $actor->commune_code }}"
                data-fixed="1">

                <option
                    value="{{ $actor->commune_code }}"
                    selected>
                    {{ $actor->commune?->name ?? $actor->commune_code }}
                </option>

            </select>

            @else

            {{-- =================================================
                    ADMIN CẤP TỈNH:

                    XÃ ĐƯỢC LOAD THEO TỈNH
                ================================================== --}}

            <select
                id="commune_code"
                class="form-select @error('commune_code') is-invalid @enderror"
                name="commune_code"
                data-selected="{{ $selectedLocations['commune_code'] }}">

                <option value="">
                    Chọn xã
                </option>

                @if ($selectedLocations['commune_code'])

                <option
                    value="{{ $selectedLocations['commune_code'] }}"
                    selected>
                    {{
                                $selectedLocations['commune']
                                ?: $selectedLocations['commune_code']
                            }}
                </option>

                @endif

            </select>

            @endif

            @error('commune_code')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
            @enderror

        </div>


        {{-- =====================================================
            THÔN / XÓM
        ====================================================== --}}

        <div
            id="villageField"
            class="mb-3 d-none">

            <div class="d-flex justify-content-between align-items-baseline">

                <label class="form-label mb-1">
                    Thôn / xóm được quản lý
                </label>

                <span
                    id="selectedVillagesCount"
                    class="small text-muted"
                    aria-live="polite"></span>

            </div>

            <div
                id="village_codes"
                class="border rounded bg-white p-3 @error('village_codes') border-danger @enderror"
                style="max-height: 16rem; overflow-y: auto;"
                data-selected="{{ implode(',', $selectedLocations['village_codes']) }}"
                aria-live="polite">
                <span class="text-muted small">
                    Chọn xã / phường để tải danh sách thôn / xóm.
                </span>
            </div>

            @error('village_codes')
            <div class="text-danger small">
                {{ $message }}
            </div>
            @enderror

            @error('village_codes.*')
            <div class="text-danger small">
                {{ $message }}
            </div>
            @enderror

        </div>

    </fieldset>


    {{-- =========================================================
        TRẠNG THÁI TÀI KHOẢN
    ========================================================== --}}

    <div class="form-check mb-4">

        <input
            type="hidden"
            name="is_active"
            value="0">

        <input
            id="is_active"
            class="form-check-input"
            type="checkbox"
            name="is_active"
            value="1"
            @checked((bool) old('is_active', $user->is_active ?? true))
        >

        <label
            class="form-check-label"
            for="is_active">
            Tài khoản đang hoạt động
        </label>

    </div>


    {{-- =========================================================
        BUTTON
    ========================================================== --}}

    <button
        id="saveUserButton"
        class="btn btn-primary"
        type="submit"
        data-requires-province="{{ $actor->hasRole(\App\Models\Role::SYSTEM_ADMIN) && $method === 'POST' && $provinces->isEmpty() ? '1' : '0' }}"
        @disabled($actor->hasRole(\App\Models\Role::SYSTEM_ADMIN) && $method === 'POST' && $provinces->isEmpty() && $accountScopeLevel !== 'global')>
        Lưu
    </button>

    <a
        class="btn btn-outline-secondary"
        href="{{ route('admin.users.index') }}">
        Quay lại
    </a>

</form>


@push('scripts')

<style>
    /* =========================================================
       ROLE TABLE
    ========================================================== */

    .role-choice-table {
        max-height: 18rem;
        overflow-y: auto;
    }

    .role-choice-select-column {
        width: 4rem;
    }

    .role-choice-row {
        cursor: pointer;
    }

    .role-choice-row:hover>* {
        background-color: var(--bs-tertiary-bg);
    }

    .role-choice-row.is-selected>* {
        background-color: var(--bs-primary-bg-subtle);
    }

    .role-choice-label {
        cursor: pointer;
    }
</style>


<script>
    (() => {

        /* =========================================================
           ROLE
        ========================================================== */

        const roleRows =
            document.querySelectorAll('[data-role-choice-row]');

        const roleChoices =
            document.querySelectorAll('.role-choice-input');

        const noRolesForScope =
            document.querySelector('[data-no-roles-for-scope]');


        const selectRoleRow = (row) => {

            const choice =
                row.querySelector('.role-choice-input');

            if (!choice) {
                return;
            }

            choice.checked = true;

            choice.dispatchEvent(
                new Event('change', {
                    bubbles: true
                })
            );
        };


        roleRows.forEach((row) => {

            row.addEventListener('click', (event) => {

                if (
                    event.target.closest(
                        'input, label, a, button'
                    )
                ) {
                    return;
                }

                selectRoleRow(row);
            });

        });


        roleChoices.forEach((choice) => {

            choice.addEventListener('change', () => {

                roleRows.forEach((row) => {

                    const selected =
                        row.contains(choice) &&
                        choice.checked;

                    row.classList.toggle(
                        'is-selected',
                        selected
                    );

                });

            });

        });


        /* =========================================================
           ELEMENTS
        ========================================================== */

        const scopeLevel =
            document.getElementById('scope_level');

        const saveButton =
            document.getElementById('saveUserButton');

        const province =
            document.getElementById('province_code');

        const commune =
            document.getElementById('commune_code');

        const villageList =
            document.getElementById('village_codes');

        const selectedVillagesCount =
            document.getElementById('selectedVillagesCount');

        const locationLoadError =
            document.getElementById('locationLoadError');


        const fields = {

            commune: document.getElementById('communeField'),

            village: document.getElementById('villageField'),

            territory: document.getElementById('territoryFields'),

        };


        let villageRequired = false;


        /* =========================================================
           LEVEL
        ========================================================== */

        const currentLevel = () => {

            return scopeLevel ?
                scopeLevel.value :
                'province';

        };


        /* =========================================================
           VILLAGE CHECKBOXES
        ========================================================== */

        function villageCheckboxes() {

            return [
                ...villageList.querySelectorAll(
                    '.village-checkbox'
                )
            ];

        }


        function updateVillageSelection() {

            const checkboxes =
                villageCheckboxes();

            const selectedCount =
                checkboxes.filter(
                    checkbox => checkbox.checked
                ).length;


            selectedVillagesCount.textContent =
                `${selectedCount} thôn / xóm đã chọn`;


            const firstCheckbox =
                checkboxes[0];


            if (firstCheckbox) {

                firstCheckbox.setCustomValidity(

                    villageRequired &&
                    selectedCount === 0

                    ?
                    'Vui lòng chọn ít nhất một thôn / xóm.'

                    :
                    ''

                );

            }

        }


        /* =========================================================
           RENDER VILLAGES
        ========================================================== */

        function renderVillages(records, selected) {

            const selectedValues =
                new Set(

                    (
                        Array.isArray(selected) ?
                        selected :
                        String(selected || '').split(',')
                    )

                    .map(String)
                    .filter(Boolean)

                );


            villageList.replaceChildren();


            if (records.length === 0) {

                const empty =
                    document.createElement('span');

                empty.className =
                    'text-muted small';

                empty.textContent =
                    'Xã / phường này chưa có thôn / xóm.';

                villageList.append(empty);

                updateVillageSelection();

                return;

            }


            records.forEach((record, index) => {

                const wrapper =
                    document.createElement('div');

                wrapper.className =
                    'form-check py-1';


                const checkbox =
                    document.createElement('input');

                checkbox.className =
                    'form-check-input village-checkbox';

                checkbox.type =
                    'checkbox';

                checkbox.id =
                    `village_${index}`;

                checkbox.name =
                    'village_codes[]';

                checkbox.value =
                    record.code;

                checkbox.checked =
                    selectedValues.has(
                        String(record.code)
                    );

                checkbox.disabled = !villageRequired;


                const label =
                    document.createElement('label');

                label.className =
                    'form-check-label';

                label.htmlFor =
                    checkbox.id;

                label.textContent =
                    record.name;


                wrapper.append(
                    checkbox,
                    label
                );

                villageList.append(wrapper);

            });


            updateVillageSelection();

        }


        /* =========================================================
           POPULATE SELECT
        ========================================================== */

        async function populate(
            select,
            url,
            selected
        ) {

            const response =
                await fetch(url, {
                    headers: {
                        Accept: 'application/json'
                    }
                });


            if (!response.ok) {

                throw new Error(
                    'Không thể tải danh sách địa bàn.'
                );

            }


            const records =
                await response.json();


            const selectedValues =
                new Set(

                    (
                        Array.isArray(selected) ?
                        selected :
                        String(
                            selected ||
                            select.dataset.selected ||
                            ''
                        ).split(',')
                    )

                    .map(String)
                    .filter(Boolean)

                );


            select.replaceChildren(
                new Option(
                    'Chọn xã / phường',
                    ''
                )
            );


            records.forEach((record) => {

                const option =
                    new Option(
                        record.name,
                        record.code
                    );

                option.selected =
                    selectedValues.has(
                        String(record.code)
                    );

                select.add(option);

            });

        }


        /* =========================================================
           LOAD VILLAGES
        ========================================================== */

        async function loadChildren(
            preserve = true
        ) {

            const selectedVillage =
                preserve ?
                villageList.dataset.selected :
                '';


            if (!commune.value) {

                renderVillages([], []);

                return;

            }


            try {

                const response =
                    await fetch(
                        `/api/communes/${encodeURIComponent(
                        commune.value
                    )}/villages`, {
                            headers: {
                                Accept: 'application/json'
                            }
                        }
                    );


                if (!response.ok) {

                    throw new Error(
                        'Không thể tải danh sách thôn / xóm.'
                    );

                }


                renderVillages(
                    await response.json(),
                    selectedVillage
                );


                locationLoadError.textContent = '';

            } catch (error) {

                locationLoadError.textContent =
                    'Không thể tải danh sách thôn / xóm. Vui lòng tải lại hoặc thử lại.';

            }

        }


        /* =========================================================
           RESET SELECT
        ========================================================== */

        function resetSelect(
            select,
            label
        ) {

            select.replaceChildren(
                new Option(label, '')
            );

        }


        /* =========================================================
           LOAD COMMUNES
        ========================================================== */

        async function loadCommunes(
            preserve = true
        ) {

            if (!province || currentLevel() === 'global') {
                return;
            }

            /*
             * Nếu actor đã có commune_code
             * thì KHÔNG load lại danh sách xã.
             *
             * Vì xã của actor đã được cố định.
             */

            if (commune.dataset.fixed === '1') {

                if (commune.value) {

                    await loadChildren(
                        preserve
                    );

                }

                return;

            }


            resetSelect(
                commune,
                '-- Chọn xã --'
            );


            renderVillages(
                [],
                []
            );


            if (!province.value) {

                return;

            }


            try {

                const params =
                    new URLSearchParams();


                /*
                 * Chỉ lọc các xã có thể tạo
                 * tài khoản quản trị.
                 */

                if (
                    currentLevel() ===
                    'commune'
                ) {

                    params.set(
                        'available_for',
                        'commune-admin'
                    );


                    const exceptUser =
                        province.dataset.exceptUser;


                    if (exceptUser) {

                        params.set(
                            'except',
                            exceptUser
                        );

                    }

                }


                const query =
                    params.toString();


                const communesUrl =
                    `/api/provinces/${
                    encodeURIComponent(
                        province.value
                    )
                }/communes${
                    query
                        ? `?${query}`
                        : ''
                }`;


                await populate(
                    commune,
                    communesUrl,
                    preserve ?
                    commune.dataset.selected :
                    ''
                );


                locationLoadError.textContent = '';


                await loadChildren(
                    preserve
                );

            } catch (error) {

                locationLoadError.textContent =
                    'Không thể tải danh sách xã. Vui lòng thử lại.';

            }

        }


        /* =========================================================
           UPDATE FORM
        ========================================================== */

        function updateFields() {

            const level =
                currentLevel();

            const isGlobal = level === 'global';
            fields.territory.classList.toggle('d-none', isGlobal);
            saveButton.disabled =
                saveButton.dataset.requiresProvince === '1' && !isGlobal;
            if (province) {
                province.disabled = isGlobal;
                province.required = !isGlobal && province.dataset.fixed !== '1';
            }


            /* =====================================================
               ROLE FILTER
            ====================================================== */

            let visibleRoleCount = 0;


            roleRows.forEach((row) => {

                const visible =
                    row.dataset.roleScopes
                    .split(',')
                    .includes(level);


                const choice =
                    row.querySelector(
                        '.role-choice-input'
                    );


                row.classList.toggle(
                    'd-none',
                    !visible
                );


                choice.disabled = !visible;


                if (
                    !visible &&
                    choice.checked
                ) {

                    choice.checked =
                        false;

                    row.classList.remove(
                        'is-selected'
                    );

                }


                if (visible) {

                    visibleRoleCount++;

                }

            });


            noRolesForScope?.classList.toggle(
                'd-none',
                visibleRoleCount !== 0
            );


            /* =====================================================
               FIELD REQUIREMENTS
            ====================================================== */

            const requirements = {

                commune: [
                    'commune',
                    'village'
                ].includes(level),

                village: level === 'village',

            };


            fields.commune.classList.toggle(
                'd-none',
                !requirements.commune
            );


            fields.village.classList.toggle(
                'd-none',
                !requirements.village
            );


            /*
             * Nếu xã là xã cố định của actor
             * thì vẫn giữ enabled để submit.
             */

            commune.disabled = !requirements.commune;


            commune.required =
                requirements.commune;


            villageRequired =
                requirements.village;


            villageCheckboxes()
                .forEach((checkbox) => {

                    checkbox.disabled = !villageRequired;

                });


            updateVillageSelection();

        }


        /* =========================================================
           SCOPE LEVEL CHANGE
        ========================================================== */

        scopeLevel?.addEventListener(
            'change',
            () => {

                updateFields();

                if (currentLevel() !== 'global') {
                    loadCommunes(true);
                }

            }
        );


        /* =========================================================
           PROVINCE CHANGE
        ========================================================== */

        province?.addEventListener(
            'change',
            () => {

                /*
                 * Nếu tỉnh bị cố định
                 * thì không cho xử lý như tỉnh tự chọn.
                 */

                if (
                    province.dataset.fixed === '1'
                ) {

                    return;

                }


                commune.dataset.selected =
                    '';

                villageList.dataset.selected =
                    '';


                loadCommunes(false);

            }
        );


        /* =========================================================
           COMMUNE CHANGE
        ========================================================== */

        commune.addEventListener(
            'change',
            () => {

                /*
                 * Nếu xã cố định
                 * thì người dùng không thể thay đổi.
                 */

                if (
                    commune.dataset.fixed === '1'
                ) {

                    return;

                }


                villageList.dataset.selected =
                    '';


                loadChildren(false);

            }
        );


        /* =========================================================
           VILLAGE CHANGE
        ========================================================== */

        villageList.addEventListener(
            'change',
            updateVillageSelection
        );


        /* =========================================================
           INITIALIZE
        ========================================================== */

        updateFields();

        loadCommunes();

    })();
</script>

@endpush