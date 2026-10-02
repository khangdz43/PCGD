@php
$pickerId = $pickerId ?? 'location-picker';
$provinceName = $provinceName ?? 'province_code';
$communeName = $communeName ?? 'commune_code';
$villageName = $villageName ?? 'village_code';
$showVillage = $showVillage ?? false;
$provinceRequired = $provinceRequired ?? false;
$communeRequired = $communeRequired ?? false;
$inline = $inline ?? false;
// nếu mà submit lỗi thì lấy value cũ
////////
$selectedProvinceCode = old($provinceName, $provinceCode ?? request($provinceName, ''));
$selectedCommuneCode = old($communeName, $communeCode ?? request($communeName, ''));
$selectedVillageCode = old($villageName, $villageCode ?? request($villageName, ''));
@endphp

<div class="{{ $inline ? 'location-picker-inline' : 'row g-3' }}" data-location-picker data-provinces-url="/api/provinces" data-communes-url="/api/communes">
    <div class="{{ $inline ? 'location-picker-field' : 'col-md-4' }}">
        <label for="{{ $pickerId }}-province" class="form-label {{ $inline ? 'visually-hidden' : '' }}">Tỉnh/Thành phố @if ($provinceRequired)<span class="text-danger">*</span>@endif</label>
        <select id="{{ $pickerId }}-province" name="{{ $provinceName }}" class="form-select {{ $inline ? 'form-select-sm' : '' }} @error($provinceName) is-invalid @enderror" data-province @if ($provinceRequired) required @endif>
            <option value="">-- Chọn Tỉnh/Thành phố --</option>
            @foreach ($provinces as $province)
            @php
            $provinceType = str_starts_with($province->name, 'Thành phố ') ? 'Thành phố' : 'Tỉnh';
            $provinceLabel = preg_replace('/^(Tỉnh|Thành phố)\s+/u', '', $province->name);
            @endphp
            <option value="{{ $province->code }}" @selected((string) $selectedProvinceCode===(string) $province->code)>{{ $provinceLabel }} ({{ $provinceType }})</option>
            @endforeach
        </select>
        @error($provinceName)
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="{{ $inline ? 'location-picker-field' : 'col-md-4' }}">
        <label for="{{ $pickerId }}-commune" class="form-label {{ $inline ? 'visually-hidden' : '' }}">Xã/Phường @if ($communeRequired)<span class="text-danger">*</span>@endif</label>
        <select id="{{ $pickerId }}-commune" name="{{ $communeName }}" class="form-select {{ $inline ? 'form-select-sm' : '' }} @error($communeName) is-invalid @enderror" data-commune data-selected-code="{{ $selectedCommuneCode }}" disabled @if ($communeRequired) required @endif>
            <option value="">-- Chọn Xã/Phường --</option>
        </select>
        @error($communeName)
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    @if ($showVillage)
    <div class="{{ $inline ? 'location-picker-field' : 'col-md-4' }}">
        <label for="{{ $pickerId }}-village" class="form-label {{ $inline ? 'visually-hidden' : '' }}">Thôn/Bản</label>
        <select id="{{ $pickerId }}-village" name="{{ $villageName }}" class="form-select {{ $inline ? 'form-select-sm' : '' }} @error($villageName) is-invalid @enderror" data-village data-selected-code="{{ $selectedVillageCode }}" disabled>
            <option value="">-- Chọn Thôn/Bản --</option>
        </select>
        @error($villageName)
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    @endif

    <div class="{{ $inline ? 'location-picker-status' : 'col-12' }}">
        <p class="small text-muted mb-0" data-location-status role="status" aria-live="polite"></p>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-location-picker]').forEach(function(picker) {
            const provinceSelect = picker.querySelector('[data-province]');
            const communeSelect = picker.querySelector('[data-commune]');
            const villageSelect = picker.querySelector('[data-village]');
            const status = picker.querySelector('[data-location-status]');

            function resetSelect(select, placeholder) {
                if (!select) return;
                select.replaceChildren(new Option(placeholder, ''));
                select.disabled = true;
            }

            function formatCommuneLabel(name) {
                const normalizedName = name.trim().replace(/\s+/g, ' ');
                const match = normalizedName.match(/^(Xã|Phường|Thị trấn|Đặc khu)\s+(.+)$/iu);

                return match ? `${match[2]} (${match[1]})` : normalizedName;
            }

            async function loadVillages(communeCode, selectedCode = '') {
                resetSelect(villageSelect, '-- Chọn Thôn/Bản --');
                if (!villageSelect || !communeCode) return;

                try {
                    const response = await fetch(`${picker.dataset.communesUrl}/${encodeURIComponent(communeCode)}/villages`);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);

                    const villages = await response.json();
                    villages.forEach(village => villageSelect.add(new Option(village.name, village.code)));
                    villageSelect.disabled = false;
                    villageSelect.value = selectedCode;
                } catch (error) {
                    console.error('Lỗi tải danh sách thôn/bản:', error);
                    status.textContent = 'Không tải được danh sách thôn/bản.';
                }
            }

            async function loadCommunes(provinceCode, selectedCode = '') {
                resetSelect(communeSelect, '-- Chọn Xã/Phường --');
                resetSelect(villageSelect, '-- Chọn Thôn/Bản --');
                status.textContent = '';
                if (!provinceCode) return;

                try {
                    const response = await fetch(`${picker.dataset.provincesUrl}/${encodeURIComponent(provinceCode)}/communes`);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);

                    const communes = await response.json();
                    communes.forEach(commune => communeSelect.add(new Option(formatCommuneLabel(commune.name), commune.code)));
                    communeSelect.disabled = false;
                    communeSelect.value = selectedCode;
                    picker.dispatchEvent(new CustomEvent('location:communes-loaded', {
                        detail: {
                            provinceCode,
                            communeCode: communeSelect.value
                        }
                    }));

                    if (communeSelect.value && villageSelect) {
                        await loadVillages(communeSelect.value, villageSelect.dataset.selectedCode);
                    }
                } catch (error) {
                    console.error('Lỗi tải danh sách xã/phường:', error);
                    status.textContent = 'Không tải được danh sách xã/phường.';
                }
            }

            provinceSelect.addEventListener('change', function() {
                if (villageSelect) villageSelect.dataset.selectedCode = '';
                loadCommunes(this.value);
            });

            communeSelect.addEventListener('change', function() {
                if (villageSelect) villageSelect.dataset.selectedCode = '';
                loadVillages(this.value);
            });

            if (provinceSelect.value) {
                loadCommunes(provinceSelect.value, communeSelect.dataset.selectedCode);
            }
        });
    });
</script>
@endpush