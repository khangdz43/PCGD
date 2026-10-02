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

    <div class="row g-3">
        <div class="col-md-4">
            <label for="form-code" class="form-label">Mã phiếu <span class="text-danger">*</span></label>
            <input id="household-code" name="household_code" class="form-control @error('household_code') is-invalid @enderror" value="{{ old('household_code', $household->household_code) }}" maxlength="50" required @if ($method==='POST' ) readonly @endif>
            @if ($method === 'POST') <div class="form-text">Mã hộ được tự sinh khi tạo hộ mới.</div> @endif
            @error('household_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label for="head-last-name" class="form-label">Họ chủ hộ <span class="text-danger">*</span></label>
            <input id="head-last-name" name="head_last_name" class="form-control @error('head_last_name') is-invalid @enderror" value="{{ old('head_last_name', $household->head_last_name) }}" maxlength="50" required>
            @error('head_last_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label for="head-first-name" class="form-label">Tên chủ hộ <span class="text-danger">*</span></label>
            <input id="head-first-name" name="head_first_name" class="form-control @error('head_first_name') is-invalid @enderror" value="{{ old('head_first_name', $household->head_first_name) }}" maxlength="100" required>
            @error('head_first_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label for="school-year" class="form-label">Năm học <span class="text-danger">*</span></label>
            <select id="school-year" name="school_year" class="form-select @error('school_year') is-invalid @enderror" required>
                <option value="">-- Chọn năm học --</option>
                @php($lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1)
                @for ($year = 2020; $year <= $lastSchoolYear; $year++)
                    @php($schoolYear=$year . '-' . ($year + 1))
                    <option value="{{ $schoolYear }}" @selected(old('school_year', $household->school_year) === $schoolYear)>{{ $schoolYear }}</option>
                    @endfor
            </select>
            @error('school_year') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-12">
            @include('partials.location-picker', [
            'pickerId' => 'household-form-location',
            'provinces' => $provinces,
            'provinceCode' => $household->province_code,
            'communeCode' => $household->commune_code,
            'villageCode' => $household->village_code,
            'showVillage' => true,
            'provinceRequired' => true,
            'communeRequired' => true,
            ])
        </div>
        <div class="col-md-6">
            <label for="address" class="form-label">Địa chỉ cụ thể</label>
            <input id="address" name="address" class="form-control @error('address') is-invalid @enderror" value="{{ old('address', $household->address) }}" maxlength="1000" placeholder="Số nhà, đường/ngõ">
            @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label for="residence-type" class="form-label">Loại cư trú <span class="text-danger">*</span></label>
            <select id="residence-type" name="residence_type" class="form-select @error('residence_type') is-invalid @enderror" required>
                <option value="THUONG_TRU" @selected(old('residence_type', $household->residence_type ?: 'THUONG_TRU') === 'THUONG_TRU')>Thường trú</option>
                <option value="TAM_TRU" @selected(old('residence_type', $household->residence_type) === 'TAM_TRU')>Tạm trú</option>
                <option value="KHAC" @selected(old('residence_type', $household->residence_type) === 'KHAC')>Khác</option>
            </select>
            @error('residence_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label for="residence-status" class="form-label">Tình trạng cư trú</label>
            <input id="residence-status" name="residence_status" class="form-control @error('residence_status') is-invalid @enderror" value="{{ old('residence_status', $household->residence_status) }}" maxlength="100" placeholder="Đang ở, đã chuyển đi...">
            @error('residence_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> {{ $method === 'POST' ? 'Lưu phiếu và tiếp tục' : 'Lưu thay đổi' }}
        </button>
        <a class="btn btn-outline-secondary" href="{{ $cancelUrl }}">Hủy</a>
    </div>
</form>