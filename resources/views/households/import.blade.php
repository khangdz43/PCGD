@extends('layouts.app')

@section('title', 'Nhập phiếu điều tra - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('households.index') }}">&larr; Phiếu điều tra hộ</a>
        <h1 class="h3 mt-3 mb-1">Nhập dữ liệu phiếu điều tra</h1>
        <p class="text-muted mb-0">Chọn địa bàn áp dụng cho file, sau đó tải lên file đã nhập theo mẫu.</p>
    </div>

    @if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <p class="mb-1">Không thể nhập file:</p>
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <section class="bg-white border rounded-2 p-4">
        <form id="household-import-form" method="POST" action="{{ route('households.import.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label for="excel-file" class="form-label">File Excel <span class="text-danger">*</span></label>
                <input id="excel-file" name="file" type="file" accept=".xls,.xlsx" class="form-control @error('file') is-invalid @enderror" required>
                <div class="form-text">Chấp nhận XLS/XLSX, tối đa 20 MB. Dữ liệu bắt đầu từ dòng 5.</div>
                @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label for="import-school-year" class="form-label">Năm học <span class="text-danger">*</span></label>
                <select id="import-school-year" name="school_year" class="form-select @error('school_year') is-invalid @enderror" required>
                    <option value="">-- Chọn năm học --</option>
                    @php($lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1)
                    @for ($year = 2020; $year <= $lastSchoolYear; $year++)
                        @php($schoolYear=$year . '-' . ($year + 1))
                        <option value="{{ $schoolYear }}" @selected(old('school_year')===$schoolYear)>{{ $schoolYear }}</option>
                        @endfor
                </select>
                @error('school_year') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3" data-import-location>
                <label class="form-label">Địa bàn áp dụng cho file <span class="text-danger">*</span></label>
                @include('partials.location-picker', [
                'pickerId' => 'household-import-location',
                'provinces' => $provinces,
                'provinceRequired' => true,
                'communeRequired' => true,
                'showVillage' => true,
                ])
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" id="household-import-submit" type="submit">
                    <i class="fa-solid fa-file-import me-1" aria-hidden="true"></i> Nhập dữ liệu
                </button>
                <a class="btn btn-outline-secondary" href="{{ route('households.template') }}">
                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Tải mẫu Excel
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('households.index') }}">Hủy</a>
            </div>
        </form>
    </section>
</div>

@push('scripts')
<script>
    document.getElementById('household-import-form').addEventListener('submit', (event) => {
        const submitButton = document.getElementById('household-import-submit');

        if (submitButton.disabled) {
            event.preventDefault();
            return;
        }

        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Đang nhập...';
    });
</script>
@endpush
@endsection