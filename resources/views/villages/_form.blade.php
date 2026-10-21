<form method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST')
    @method($method)
    @endif

    <div class="mb-3">
        <label for="village-code" class="form-label">Mã thôn/xóm</label>
        @if ($method === 'POST')
            <input id="village-code" class="form-control" value="" readonly>
            <div class="form-text">Mã được tự tạo theo địa bàn và tên thôn khi lưu.</div>
        @else
            <input id="village-code" class="form-control" value="{{ $village->code }}" readonly>
        @endif
    </div>

    <div class="mb-3">
        <label for="village-name" class="form-label">Tên thôn/xóm <span class="text-danger">*</span></label>
        <input id="village-name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $village->name) }}" maxlength="255" required>
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-4">
        @include('partials.location-picker', [
        'pickerId' => 'village-form-location',
        'provinces' => $provinces,
        'provinceCode' => $provinceCode ?? '',
        'communeCode' => $communeCode ?? $village->commune_code,
        'provinceRequired' => true,
        'communeRequired' => true,
        ])
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> {{ $method === 'POST' ? 'Lưu thôn/xóm' : 'Lưu thay đổi' }}
        </button>
        <a class="btn btn-outline-secondary" href="{{ route('villages.index', [], false) }}">Hủy</a>
    </div>
</form>

@if ($method === 'POST')
@push('scripts')
<script>
    (() => {
        const codeInput = document.querySelector('#village-code');
        const nameInput = document.querySelector('#village-name');
        const locationPicker = document.querySelector('form [data-location-picker]');

        if (!codeInput || !nameInput || !locationPicker) return;

        const provinceSelect = locationPicker.querySelector('[data-province]');
        const communeSelect = locationPicker.querySelector('[data-commune]');

        function updateCodePreview() {
            const provinceCode = provinceSelect.value;
            const communeCode = communeSelect.value;
            const villageSlug = nameInput.value
                .replace(/[đĐ]/g, 'd')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-zA-Z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .toUpperCase() || 'THON';
            const prefix = `${communeCode}_`;

            codeInput.value = provinceCode && communeCode
                ? `${prefix}${villageSlug}`.slice(0, 20)
                : '';
        }

        nameInput.addEventListener('input', updateCodePreview);
        provinceSelect.addEventListener('change', updateCodePreview);
        communeSelect.addEventListener('change', updateCodePreview);
        locationPicker.addEventListener('location:communes-loaded', updateCodePreview);
        updateCodePreview();
    })();
</script>
@endpush
@endif