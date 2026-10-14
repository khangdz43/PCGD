@extends('layouts.app')

@section('title', 'Nhập phiếu điều tra - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <h1 class="h3 mb-1">Nhập dữ liệu phiếu điều tra</h1>
        <p class="text-muted mb-0">Chọn địa bàn trong phạm vi được giao, sau đó tải lên file đã nhập theo mẫu.</p>
    </div>

    <section class="bg-white border rounded-2 p-4">
        <form id="household-import-form" method="POST" action="{{ route('households.import.store', [], false) }}" enctype="multipart/form-data" data-turbo="false">
            @csrf
            <div class="mb-3">
                <label for="excel-file" class="form-label">File Excel <span class="text-danger">*</span></label>
                <input id="excel-file" name="file" type="file" accept=".xls,.xlsx" class="form-control @error('file') is-invalid @enderror" required>
                <div class="form-text">Chấp nhận XLS/XLSX, tối đa 20 MB. Dữ liệu bắt đầu từ dòng 5.</div>
                @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                @php($currentSchoolYearStart = now()->month >= 8 ? now()->year : now()->year - 1)
                @php($currentSchoolYear = $currentSchoolYearStart . '-' . ($currentSchoolYearStart + 1))
                <label for="import-school-year" class="form-label">Năm học áp dụng</label>
                <input id="import-school-year" class="form-control @error('school_year') is-invalid @enderror" value="{{ $currentSchoolYear }}" readonly>
                <input type="hidden" name="school_year" value="{{ $currentSchoolYear }}">
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
                'provinceCode' => $provinceCode,
                'communeCode' => $communeCode,
                'villageCode' => $villageCode,
                'villageRequired' => $villageRequired,
                'initialCommune' => $initialCommune,
                'initialVillages' => $initialVillages,
                ])
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" id="household-import-submit" type="submit">
                    <i class="fa-solid fa-file-import me-1" aria-hidden="true"></i> Nhập dữ liệu
                </button>
                <a class="btn btn-outline-secondary" href="{{ route('households.template', [], false) }}" data-turbo="false">
                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Tải mẫu Excel
                </a>
                @can('view-households')
                    <a class="btn btn-outline-secondary" href="{{ route('households.index', [], false) }}">Hủy</a>
                @else
                    <a class="btn btn-outline-secondary" href="{{ route('index', [], false) }}">Hủy</a>
                @endcan
                <a class="btn btn-outline-info" href="{{ route('households.import-history', [], false) }}">
                    <i class="fa-solid fa-clock-rotate-left me-1" aria-hidden="true"></i> Lịch sử tải lên
                </a>
            </div>
        </form>

    </section>
</div>

@push('scripts')
<script>
    (() => {
        const importForm = document.getElementById('household-import-form');
        if (!importForm || importForm.dataset.queueReady === 'true') return;
        importForm.dataset.queueReady = 'true';

        const submitButton = document.getElementById('household-import-submit');

        importForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitButton.disabled) return;

            submitButton.disabled = true;
            submitButton.setAttribute('aria-busy', 'true');
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Đang tải lên...';

            try {
                const response = await fetch(importForm.action, {
                    method: 'POST',
                    body: new FormData(importForm),
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();

                if (response.status !== 202) {
                    const validationMessage = Object.values(payload.errors || {}).flat()[0];
                    throw new Error(validationMessage || payload.message || 'Không thể đưa file vào hàng đợi.');
                }

                window.showAppNotification(
                    'info',
                    'File đã được tải lên và đang xử lý nền. Bạn có thể tiếp tục làm việc; hệ thống sẽ thông báo khi có kết quả.',
                    'Đã nhận file nhập dữ liệu',
                    { href: @json(route('households.import-history', [], false)), label: 'Xem lịch sử tải lên' },
                );
                importForm.reset();
            } catch (error) {
                submitButton.disabled = false;
                submitButton.removeAttribute('aria-busy');
                submitButton.innerHTML = '<i class="fa-solid fa-file-import me-1" aria-hidden="true"></i> Nhập dữ liệu';
                window.showAppNotification('error', error.message || 'Không thể tải file lên.');
            }
        });
    })();
</script>
@endpush
@endsection