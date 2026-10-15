@php
$pickerId = $pickerId ?? 'location-picker';
$provinceName = $provinceName ?? 'province_code';
$communeName = $communeName ?? 'commune_code';
$villageName = $villageName ?? 'village_code';
$showVillage = $showVillage ?? false;
$provinceRequired = $provinceRequired ?? false;
$communeRequired = $communeRequired ?? false;
$villageRequired = $villageRequired ?? false;
$inline = $inline ?? false;
$villagePlaceholder = $villagePlaceholder ?? '-- Chọn Thôn/Bản --';
$initialCommune = $initialCommune ?? null;
$initialVillages = $initialVillages ?? collect();
$hideProvince = $hideProvince ?? false;
$hideCommune = $hideCommune ?? false;
$hideVillage = $hideVillage ?? false;
// nếu mà submit lỗi thì lấy value cũ
////////
$selectedProvinceCode = old($provinceName, $provinceCode ?? request($provinceName, ''));
$selectedCommuneCode = old($communeName, $communeCode ?? request($communeName, ''));
$selectedVillageCode = old($villageName, $villageCode ?? request($villageName, ''));
@endphp

<div class="{{ $inline ? 'location-picker-inline' : 'row g-3' }}" data-location-picker data-provinces-url="/api/provinces" data-communes-url="/api/communes">
    <div class="{{ $inline ? 'location-picker-field' : 'col-md-4' }}" @if ($hideProvince) hidden @endif>
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

    <div class="{{ $inline ? 'location-picker-field' : 'col-md-4' }}" @if ($hideCommune) hidden @endif>
        <label for="{{ $pickerId }}-commune" class="form-label {{ $inline ? 'visually-hidden' : '' }}">Xã/Phường @if ($communeRequired)<span class="text-danger">*</span>@endif</label>
        <select id="{{ $pickerId }}-commune" name="{{ $communeName }}" class="form-select {{ $inline ? 'form-select-sm' : '' }} @error($communeName) is-invalid @enderror" data-commune data-selected-code="{{ $selectedCommuneCode }}" @disabled($initialCommune === null) @if ($communeRequired) required @endif>
            <option value="">-- Chọn Xã/Phường --</option>
            @if ($initialCommune)
            <option value="{{ $initialCommune->code }}" @selected((string) $selectedCommuneCode === (string) $initialCommune->code)>{{ $initialCommune->name }}</option>
            @endif
        </select>
        @error($communeName)
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    @if ($showVillage)
    <div class="{{ $inline ? 'location-picker-field' : 'col-md-4' }}" @if ($hideVillage) hidden @endif>
        <label for="{{ $pickerId }}-village" class="form-label {{ $inline ? 'visually-hidden' : '' }}">Thôn/Bản @if ($villageRequired)<span class="text-danger">*</span>@endif</label>
        <select id="{{ $pickerId }}-village" name="{{ $villageName }}" class="form-select {{ $inline ? 'form-select-sm' : '' }} @error($villageName) is-invalid @enderror" data-village data-placeholder="{{ $villagePlaceholder }}" data-selected-code="{{ $selectedVillageCode }}" @disabled($initialCommune === null) @if ($villageRequired) required @endif>
            <option value="">{{ $villagePlaceholder }}</option>
            @foreach ($initialVillages as $village)
            <option value="{{ $village->code }}" @selected((string) $selectedVillageCode === (string) $village->code)>{{ $village->name }}</option>
            @endforeach
        </select>
        @error($villageName)
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    @endif

    <div class="{{ $inline ? 'location-picker-status' : 'col-12' }}" data-location-status-wrap hidden>
        <p class="small text-muted mb-0" data-location-status role="status" aria-live="polite"></p>
    </div>
</div>

@push('scripts')
<script>
    (() => {
        document.querySelectorAll('[data-location-picker]').forEach(function(picker) {
            const provinceSelect = picker.querySelector('[data-province]');
            const communeSelect = picker.querySelector('[data-commune]');
            const villageSelect = picker.querySelector('[data-village]');
            const status = picker.querySelector('[data-location-status]');
            const statusWrap = picker.querySelector('[data-location-status-wrap]');

            function setStatus(message) {
                status.textContent = message;
                statusWrap.hidden = !message;
            }

            function resetSelect(select, placeholder) {
                if (!select) return;
                select.replaceChildren(new Option(placeholder, ''));
                select.disabled = true;
            }

            function setHiddenSelectionValue(select, value) {
                if (!select) return;
                select.value = value;
            }

            function formatCommuneLabel(name) {
                const normalizedName = name.trim().replace(/\s+/g, ' ');
                const match = normalizedName.match(/^(Xã|Phường|Thị trấn|Đặc khu)\s+(.+)$/iu);

                return match ? `${match[2]} (${match[1]})` : normalizedName;
            }

            async function loadVillages(communeCode, selectedCode = '') {
                resetSelect(villageSelect, villageSelect?.dataset.placeholder || '-- Chọn Thôn/Bản --');
                if (!villageSelect || !communeCode) return;

                try {
                    const response = await fetch(`${picker.dataset.communesUrl}/${encodeURIComponent(communeCode)}/villages`);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);

                    const villages = await response.json();
                    villages.forEach(village => villageSelect.add(new Option(village.name, village.code)));
                    villageSelect.disabled = false;
                    villageSelect.value = selectedCode;
                    picker.dispatchEvent(new CustomEvent('location:villages-loaded', {
                        detail: {
                            communeCode,
                            villageCode: villageSelect.value
                        }
                    }));
                } catch (error) {
                    console.error('Lỗi tải danh sách thôn/bản:', error);
                    setStatus('Không tải được danh sách thôn/bản.');
                }
            }

            async function loadCommunes(provinceCode, selectedCode = '', selectedVillageCode = '') {
                resetSelect(communeSelect, '-- Chọn Xã/Phường --');
                resetSelect(villageSelect, '-- Chọn Thôn/Bản --');
                setStatus('');
                if (!provinceCode || !communeSelect) return;

                try {
                    const response = await fetch(`${picker.dataset.provincesUrl}/${encodeURIComponent(provinceCode)}/communes`);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);

                    const communes = await response.json();
                    communes.forEach(commune => communeSelect.add(new Option(formatCommuneLabel(commune.name), commune.code)));
                    communeSelect.disabled = false;
                    setHiddenSelectionValue(communeSelect, selectedCode);
                    picker.dispatchEvent(new CustomEvent('location:communes-loaded', {
                        detail: {
                            provinceCode,
                            communeCode: communeSelect.value
                        }
                    }));

                    if (communeSelect.value && villageSelect) {
                        await loadVillages(communeSelect.value, selectedVillageCode);
                    }
                } catch (error) {
                    console.error('Lỗi tải danh sách xã/phường:', error);
                    setStatus('Không tải được danh sách xã/phường.');
                }
            }

            picker.selectLocation = async (provinceCode = '', selectedCommuneCode = '', selectedVillageCode = '') => {
                if (provinceSelect) {
                    setHiddenSelectionValue(provinceSelect, provinceCode);
                }
                await loadCommunes(provinceCode, selectedCommuneCode, selectedVillageCode);

                if (selectedCommuneCode && communeSelect && communeSelect.value !== selectedCommuneCode) {
                    throw new Error('Không tải được danh sách xã/phường của tỉnh đã chọn.');
                }
                if (selectedVillageCode && villageSelect?.value !== selectedVillageCode) {
                    throw new Error('Không tải được danh sách thôn/bản của xã đã chọn.');
                }
            };

            if (provinceSelect) {
                provinceSelect.addEventListener('change', function() {
                    if (communeSelect) communeSelect.dataset.selectedCode = '';
                    if (villageSelect) villageSelect.dataset.selectedCode = '';
                    loadCommunes(this.value);
                });
            }

            if (communeSelect) {
                communeSelect.addEventListener('change', function() {
                    if (villageSelect) villageSelect.dataset.selectedCode = '';
                    loadVillages(this.value);
                });
            }

            if (provinceSelect && provinceSelect.value) {
                if (communeSelect && communeSelect.options.length > 1 && communeSelect.value) {
                    picker.dispatchEvent(new CustomEvent('location:communes-loaded', {
                        detail: {
                            provinceCode: provinceSelect.value,
                            communeCode: communeSelect.value
                        }
                    }));
                } else if (communeSelect) {
                    loadCommunes(provinceSelect.value, communeSelect.dataset.selectedCode, villageSelect?.dataset.selectedCode || '');
                }
            }
        });
    })();
</script>
@endpush