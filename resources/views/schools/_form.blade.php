<form method="POST" action="{{ $action }}">
    @csrf

    @if ($method !== 'POST')
    @method($method)
    @endif

    <div class="mb-3">
        <label for="name" class="form-label">
            Tên trường <span class="text-danger">*</span>
        </label>

        <input
            id="name"
            name="name"
            class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name', $school->name) }}"
            maxlength="255"
            required>

        @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label for="level" class="form-label">
            Cấp học
        </label>

        <select
            id="level"
            name="level"
            class="form-select @error('level') is-invalid @enderror">
            <option value="">-- Chọn cấp học --</option>

            @foreach ([
            'MN' => 'Mầm non',
            'TH' => 'Tiểu học',
            'THCS' => 'THCS',
            'THPT' => 'THPT',
            ] as $value => $label)
            <option
                value="{{ $value }}"
                @selected(old('level', $school->level) === $value)
                >
                {{ $label }}
            </option>
            @endforeach
        </select>

        @error('level')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-4">
        @include('partials.location-picker', [
        'pickerId' => 'school-location',
        'provinces' => $provinces,

        // Khi sửa trường: lấy địa bàn của trường
        // Khi tạo mới: lấy địa bàn được phân công cho tài khoản
        'provinceCode' => old(
        'province_code',
        $school->province_code ?? auth()->user()->province_code
        ),

        'communeCode' => old(
        'commune_code',
        $school->commune_code ?? auth()->user()->commune_code
        ),
        ])
    </div>

    <div class="mb-4">
        <label for="code" class="form-label">
            Mã trường <span class="text-danger">*</span>
        </label>

        <input
            id="code"
            class="form-control @error('code') is-invalid @enderror"
            value="{{ old('code', $school->code) }}"
            maxlength="50"
            @if ($method === 'POST') data-school-code-preview @endif
            readonly>

        <div class="form-text">
            Mã sẽ tự hiện khi nhập tên trường; mã chính thức được xác định khi lưu.
        </div>

        @error('code')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i>

            {{ $method === 'POST' ? 'Lưu trường học' : 'Lưu thay đổi' }}
        </button>

        <a
            class="btn btn-outline-secondary"
            href="{{ route('schools.index', [], false) }}">
            Hủy
        </a>
    </div>
</form>