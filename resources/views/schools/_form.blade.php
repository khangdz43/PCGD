@if ($errors->any())
<div class="alert alert-danger" role="alert">
    <p class="mb-1">Vui lòng kiểm tra lại thông tin:</p>
    <ul class="mb-0">
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST')
    @method($method)
    @endif

    <div class="mb-3">
        <label for="name" class="form-label">Tên trường <span class="text-danger">*</span></label>
        <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $school->name) }}" maxlength="255" required>
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-3">
        <label for="level" class="form-label">Cấp học</label>
        <select id="level" name="level" class="form-select @error('level') is-invalid @enderror">
            <option value="">-- Chọn cấp học --</option>
            @foreach (['MN', 'TH', 'THCS'] as $level)
            <option value="{{ $level }}" @selected(old('level', $school->level) === $level)>{{ $level }}</option>
            @endforeach
        </select>
        @error('level') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-4">
        @include('partials.location-picker', [
        'pickerId' => 'school-location',
        'provinces' => $provinces,
        'provinceCode' => $school->province_code,
        'communeCode' => $school->commune_code,
        ])
    </div>

    <div class="mb-4">
        <label for="code" class="form-label">Mã trường <span class="text-danger">*</span></label>
        <input id="code" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $school->code) }}" maxlength="50" @if ($method !=='POST' ) readonly @endif required>
        <div class="form-text">Tự tạo sau khi nhập tên trường và chọn địa bàn; có thể chỉnh sửa.</div>
        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> {{ $method === 'POST' ? 'Lưu trường học' : 'Lưu thay đổi' }}
        </button>
        <a class="btn btn-outline-secondary" href="{{ route('schools.index') }}">Hủy</a>
    </div>
</form>

@if ($method === 'POST')
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const codeInput = document.querySelector('#code');
        const nameInput = document.querySelector('#name');
        const locationPicker = document.querySelector('form [data-location-picker]');

        if (!codeInput || !nameInput || !locationPicker) return;

        const provinceSelect = locationPicker.querySelector('[data-province]');
        const communeSelect = locationPicker.querySelector('[data-commune]');
        const initialCode = codeInput.value.trim();
        let lastGeneratedCode = initialCode;
        let hasCheckedInitialCode = !initialCode;
        let codeWasEdited = false;

        function makeAbbreviation(name) {
            return name.normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[đĐ]/g, 'd')
                .trim()
                .split(/\s+/)
                .filter(word => word && word.toLowerCase() !== 'truong')
                .map(word => (word.match(/[a-zA-Z0-9]/) || [''])[0])
                .join('')
                .toUpperCase();
        }

        function updateCode() {
            const provinceCode = provinceSelect.value;
            const communeCode = communeSelect.value;
            const abbreviation = makeAbbreviation(nameInput.value);

            if (!provinceCode || !communeCode || !abbreviation) return;

            const prefix = `${provinceCode}_${communeCode}`;
            const generatedCode = prefix + abbreviation.slice(0, 50 - prefix.length);

            if (!hasCheckedInitialCode) {
                codeWasEdited = initialCode !== generatedCode;
                hasCheckedInitialCode = true;
                if (codeWasEdited) return;
            }

            if (codeWasEdited) return;

            codeInput.value = generatedCode;
            lastGeneratedCode = generatedCode;
        }

        codeInput.addEventListener('input', function() {
            if (codeInput.value !== lastGeneratedCode) codeWasEdited = true;
        });
        nameInput.addEventListener('input', updateCode);
        provinceSelect.addEventListener('change', updateCode);
        communeSelect.addEventListener('change', updateCode);
        locationPicker.addEventListener('location:communes-loaded', updateCode);
        updateCode();
    });
</script>
@endpush
@endif