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

<form method="POST" action="{{ $action }}" class="person-survey-form">
    @csrf
    @if ($method === 'POST' && $isHouseholdHead)
    <input type="hidden" name="is_head" value="1">
    @endif
    @if ($method !== 'POST')
    @method($method)
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            <fieldset class="border rounded-2 p-3 mb-4">
                <legend class="float-none w-auto px-2 h6 mb-0">Thông tin đối tượng</legend>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label for="last-name" class="form-label">Họ <span class="text-danger">*</span></label>
                        <input id="last-name" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name', $person->last_name) }}" maxlength="50" @if ($method==='POST' && $isHouseholdHead) readonly @endif required>
                        @error('last_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="first-name" class="form-label">Tên <span class="text-danger">*</span></label>
                        <input id="first-name" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name', $person->first_name) }}" maxlength="100" @if ($method==='POST' && $isHouseholdHead) readonly @endif required>
                        @error('first_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="dob" class="form-label">Ngày sinh <span class="text-danger">*</span></label>
                        <input id="dob" name="dob" type="date" class="form-control @error('dob') is-invalid @enderror" value="{{ old('dob', $person->dob?->format('Y-m-d')) }}" required>
                        @error('dob') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="gender" class="form-label">Giới tính <span class="text-danger">*</span></label>
                        <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror" required>
                            <option value="NAM" @selected(old('gender', $person->gender) === 'NAM')>Nam</option>
                            <option value="NU" @selected(old('gender', $person->gender) === 'NU')>Nữ</option>

                        </select>
                        @error('gender') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="ethnicity-code" class="form-label">Dân tộc <span class="text-danger">*</span></label>
                        <select id="ethnicity-code" name="ethnicity_code" class="form-select @error('ethnicity_code') is-invalid @enderror" required>
                            <option value="">-- Chọn dân tộc --</option>
                            @foreach ($ethnicities as $ethnicity)
                            <option value="{{ $ethnicity->code }}" @selected(old('ethnicity_code', $person->ethnicity_code) === $ethnicity->code)>{{ $ethnicity->name }}</option>
                            @endforeach
                        </select>
                        @error('ethnicity_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="religion" class="form-label">Tôn giáo</label>
                        <input id="religion" name="religion" class="form-control @error('religion') is-invalid @enderror" value="{{ old('religion', $person->religion) }}" maxlength="50">
                        @error('religion') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    @unless ($isHouseholdHead)
                    <div class="col-12">
                        <label for="relationship" class="form-label">Quan hệ với chủ hộ <span class="text-danger">*</span></label>
                        <input id="relationship" name="relationship_with_head" class="form-control @error('relationship_with_head') is-invalid @enderror" value="{{ old('relationship_with_head', $person->relationship_with_head) }}" maxlength="100" placeholder="Vợ/chồng, con, cha/mẹ..." required>
                        @error('relationship_with_head') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    @else
                    <input type="hidden" name="relationship_with_head" value="Chủ hộ">
                    @endunless
                    <div class="col-12">
                        <label for="parent-name" class="form-label">Họ tên cha/mẹ</label>
                        <input id="parent-name" name="parent_name" class="form-control @error('parent_name') is-invalid @enderror" value="{{ old('parent_name', $person->parent_name) }}" maxlength="150">
                        @error('parent_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="priority-type" class="form-label">Diện ưu tiên</label>
                        <input id="priority-type" name="priority_type" class="form-control @error('priority_type') is-invalid @enderror" value="{{ old('priority_type', $person->priority_type) }}" maxlength="100">
                        @error('priority_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="phone" class="form-label">Điện thoại</label>
                        <input id="phone" name="phone" type="tel" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $person->phone) }}" maxlength="20">
                        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label for="note" class="form-label">Ghi chú </label>
                        <textarea id="note" name="note" class="form-control @error('note') is-invalid @enderror" rows="2" maxlength="2000">{{ old('note', $person->note) }}</textarea>
                        @error('note') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="border rounded-2 p-3">
                <legend class="float-none w-auto px-2 h6 mb-2">Khuyết tật và hoàn cảnh</legend>
                <div class="row g-2">
                    @foreach ([
                    'mobility_disability' => 'Vận động',
                    'hearing_speech_disability' => 'Nghe/nói',
                    'visual_disability' => 'Thị giác',
                    'mental_disability' => 'Tâm thần',
                    'intellectual_disability' => 'Trí tuệ',
                    'learning_disability' => 'Học tập',
                    'autism' => 'Tự kỷ',
                    'other_disability' => 'Khác',
                    ] as $field => $label)
                    <div class="col-sm-6">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="disability[{{ $field }}]" value="1" @checked((bool) old('disability.' . $field, $disability->{$field}))>
                            <span class="form-check-label">{{ $label }}</span>
                        </label>
                    </div>
                    @endforeach
                    <div class="col-12">
                        <hr class="my-2">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="disability[has_disability_cert]" value="1" @checked((bool) old('disability.has_disability_cert', $disability->has_disability_cert))>
                            <span class="form-check-label">Có giấy xác nhận khuyết tật</span>
                        </label>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="disability[can_study]" value="1" @checked((bool) old('disability.can_study', $disability->can_study))>
                            <span class="form-check-label">Có khả năng học tập</span>
                        </label>
                    </div>
                    <div class="col-12">
                        <label for="special-circumstance" class="form-label">Hoàn cảnh</label>
                        <select id="special-circumstance" name="disability[special_circumstance]" class="form-select">
                            <option value="">-- Chọn --</option>
                            @foreach (['Chuyển đến', 'Chuyển đi'] as $circumstance)
                            <option value="{{ $circumstance }}" @selected(old('disability.special_circumstance', $disability->special_circumstance) === $circumstance)>{{ $circumstance }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="circumstance-detail" class="form-label">Chi tiết hoàn cảnh</label>
                        <textarea id="circumstance-detail" name="disability[special_circumstance_detail]" class="form-control" rows="2" maxlength="2000">{{ old('disability.special_circumstance_detail', $disability->special_circumstance_detail) }}</textarea>
                    </div>
                </div>
            </fieldset>
        </div>

        <div class="col-lg-7">
            <fieldset class="border rounded-2 p-3 mb-4">
                <legend class="float-none w-auto px-2 h6 mb-0">Thông tin học tập</legend>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="school-year" class="form-label">Năm học <span class="text-danger">*</span></label>
                        <select id="school-year" name="education[school_year]" class="form-select @error('education.school_year') is-invalid @enderror" required @disabled($isHouseholdHead)>
                            <option value="">-- Chọn năm học --</option>
                            @php($lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1)
                            @for ($year = 2020; $year <= $lastSchoolYear; $year++)
                                @php($schoolYear=$year . '-' . ($year + 1))
                                <option value="{{ $schoolYear }}" @selected(old('education.school_year', $education->school_year) === $schoolYear)>{{ $schoolYear }}</option>
                                @endfor
                        </select>
                        @if ($isHouseholdHead)
                        <input type="hidden" name="education[school_year]" value="{{ old('education.school_year', $education->school_year) }}">
                        <div class="form-text">Năm học lấy theo phiếu của hộ.</div>
                        @endif
                        @error('education.school_year') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="current-class" class="form-label">Lớp học</label>
                        <input id="current-class" name="education[current_class]" class="form-control" value="{{ old('education.current_class', $education->current_class) }}" maxlength="50">
                    </div>
                    <div class="col-sm-6">
                        <label for="academic-block" class="form-label">Khối lớp</label>
                        <input id="academic-block" name="education[academic_block]" class="form-control @error('education.academic_block') is-invalid @enderror" value="{{ old('education.academic_block', $education->academic_block) }}" maxlength="50">
                        @error('education.academic_block') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12" data-school-picker>
                        <label class="form-label">Địa bàn trường</label>
                        @include('partials.location-picker', [
                        'pickerId' => 'education-school-location',
                        'provinces' => $provinces,
                        'provinceName' => 'school_province_code',
                        'communeName' => 'school_commune_code',
                        'provinceCode' => $education->school?->province_code ?? $household->province_code,
                        'communeCode' => $education->school?->commune_code ?? $household->commune_code,
                        ])
                    </div>
                    <div class="col-12">
                        <label for="school-code" class="form-label">Trường</label>
                        <select id="school-code" name="education[school_code]" class="form-select @error('education.school_code') is-invalid @enderror" data-school-select>
                            <option value="">-- Chọn trường --</option>
                            @foreach ($schools as $school)
                            <option value="{{ $school->code }}" data-province-code="{{ $school->province_code }}" data-commune-code="{{ $school->commune_code }}" @selected(old('education.school_code', $education->school_code) === $school->code)>{{ $school->name }}{{ $school->level ? ' (' . $school->level . ')' : '' }}</option>
                            @endforeach
                        </select>
                        @error('education.school_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="border rounded-2 p-3 mb-4">
                <legend class="float-none w-auto px-2 h6 mb-0">Tốt nghiệp và quá trình học</legend>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="graduation-level" class="form-label">Bậc tốt nghiệp</label>
                        <select id="graduation-level" name="education[graduation_level]" class="form-select @error('education.graduation_level') is-invalid @enderror">
                            <option value="">-- Chọn bậc tốt nghiệp --</option>
                            @foreach (['MN', 'TH', 'THCS', 'THPT'] as $level)
                            <option value="{{ $level }}" @selected(old('education.graduation_level', $education->graduation_level) === $level)>{{ $level }}</option>
                            @endforeach
                        </select>
                        @error('education.graduation_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="graduation-year" class="form-label">Năm tốt nghiệp</label>
                        <input id="graduation-year" name="education[graduation_year]" class="form-control" value="{{ old('education.graduation_year', $education->graduation_year) }}" maxlength="20">
                    </div>
                    <div class="col-sm-6 d-flex align-items-end">
                        <label class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="education[is_complementary]" value="1" @checked((bool) old('education.is_complementary', $education->is_complementary))>
                            <span class="form-check-label">Học hệ bổ túc</span>
                        </label>
                    </div>
                    <div class="col-sm-6">
                        <label for="vocational-level" class="form-label">Bậc tốt nghiệp nghề</label>
                        <input id="vocational-level" name="education[vocational_grad_level]" class="form-control" value="{{ old('education.vocational_grad_level', $education->vocational_grad_level) }}" maxlength="50">
                    </div>
                    <div class="col-sm-6">
                        <label for="vocational-year" class="form-label">Năm tốt nghiệp nghề</label>
                        <input id="vocational-year" name="education[vocational_grad_year]" class="form-control" value="{{ old('education.vocational_grad_year', $education->vocational_grad_year) }}" maxlength="20">
                    </div>
                    <div class="col-sm-6">
                        <label for="finished-class" class="form-label">Học xong lớp</label>
                        <input id="finished-class" name="education[finished_class]" class="form-control" value="{{ old('education.finished_class', $education->finished_class) }}" maxlength="20">
                    </div>
                    <div class="col-sm-6">
                        <label for="finished-year" class="form-label">Năm học xong</label>
                        <input id="finished-year" name="education[finished_year]" class="form-control" value="{{ old('education.finished_year', $education->finished_year) }}" maxlength="20">
                    </div>
                    <div class="col-sm-6">
                        <label for="dropped-class" class="form-label">Bỏ học lớp</label>
                        <input id="dropped-class" name="education[dropped_class]" class="form-control" value="{{ old('education.dropped_class', $education->dropped_class) }}" maxlength="20">
                    </div>
                    <div class="col-sm-6">
                        <label for="dropped-year" class="form-label">Năm bỏ học</label>
                        <input id="dropped-year" name="education[dropped_year]" class="form-control" value="{{ old('education.dropped_year', $education->dropped_year) }}" maxlength="20">
                    </div>
                </div>
            </fieldset>

            <fieldset class="border rounded-2 p-3">
                <legend class="float-none w-auto px-2 h6 mb-0">Xóa mù chữ</legend>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="literacy-current" class="form-label">Đang học lớp xóa mù chữ</label>
                        <input id="literacy-current" name="education[literacy_current_class]" class="form-control" value="{{ old('education.literacy_current_class', $education->literacy_current_class) }}" maxlength="20">
                    </div>
                    <div class="col-sm-6">
                        <label for="literacy-completed" class="form-label">Đã hoàn thành lớp</label>
                        <input id="literacy-completed" name="education[literacy_completed_class]" class="form-control" value="{{ old('education.literacy_completed_class', $education->literacy_completed_class) }}" maxlength="20">
                    </div>
                    <div class="col-sm-6">
                        <label for="literacy-relapse" class="form-label">Mức độ tái mù chữ</label>
                        <select id="literacy-relapse" name="education[literacy_relapse_level]" class="form-select">
                            <option value="">-- Chọn --</option>
                            <option value="1" @selected((string) old('education.literacy_relapse_level', $education->literacy_relapse_level) === '1')>Mức 1</option>
                            <option value="2" @selected((string) old('education.literacy_relapse_level', $education->literacy_relapse_level) === '2')>Mức 2</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label for="learning-capacity" class="form-label">Năng lực học tập</label>
                        <input id="learning-capacity" name="education[learning_capacity]" class="form-control" value="{{ old('education.learning_capacity', $education->learning_capacity) }}" maxlength="50">
                    </div>
                </div>
            </fieldset>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> {{ $method === 'POST' ? ($isHouseholdHead ? 'Lưu thông tin chủ hộ' : 'Thêm thành viên') : 'Lưu thay đổi' }}
        </button>
        <a class="btn btn-outline-secondary" href="{{ route('households.show', $household) }}">Hủy</a>
    </div>
</form>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const schoolPicker = document.querySelector('[data-school-picker]');
        const schoolSelect = document.querySelector('[data-school-select]');
        if (!schoolPicker || !schoolSelect) return;

        const locationPicker = schoolPicker.querySelector('[data-location-picker]');
        const provinceSelect = locationPicker.querySelector('[data-province]');
        const communeSelect = locationPicker.querySelector('[data-commune]');

        function filterSchools() {
            const provinceCode = provinceSelect.value;
            const communeCode = communeSelect.value;

            Array.from(schoolSelect.options).forEach(function(option) {
                if (!option.value) return;
                const matchesProvince = !provinceCode || option.dataset.provinceCode === provinceCode;
                const matchesCommune = !communeCode || option.dataset.communeCode === communeCode;
                option.hidden = !matchesProvince || !matchesCommune;
                option.disabled = option.hidden;
            });

            if (schoolSelect.selectedOptions[0]?.hidden) schoolSelect.value = '';
        }

        provinceSelect.addEventListener('change', filterSchools);
        communeSelect.addEventListener('change', filterSchools);
        locationPicker.addEventListener('location:communes-loaded', filterSchools);
        filterSchools();
    });
</script>
@endpush

@push('styles')
<style>
    .person-survey-form {
        font-size: 0.875rem;
    }

    .person-survey-form .row {
        --bs-gutter-x: 0.75rem;
        --bs-gutter-y: 0.5rem;
    }

    .person-survey-form fieldset {
        padding: 0.65rem !important;
        margin-bottom: 0.75rem !important;
    }

    .person-survey-form legend {
        font-size: 0.9rem;
    }

    .person-survey-form .form-label {
        margin-bottom: 0.2rem;
        font-size: 0.8rem;
    }

    .person-survey-form .form-control,
    .person-survey-form .form-select {
        min-height: 2rem;
        padding: 0.25rem 0.5rem;
        font-size: 0.85rem;
    }

    .person-survey-form textarea.form-control {
        min-height: auto;
    }

    .person-survey-form .form-check {
        min-height: 1.25rem;
        margin-bottom: 0.1rem;
    }

    .person-survey-form .form-check-label {
        font-size: 0.82rem;
    }

    @media (min-width: 992px) {
        .person-survey-form>.row>.col-lg-5 {
            width: 38%;
        }

        .person-survey-form>.row>.col-lg-7 {
            width: 62%;
        }
    }
</style>
@endpush