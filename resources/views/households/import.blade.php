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
                <div id="household-import-upload-progress" class="mt-2 d-none" aria-live="polite">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span data-upload-progress-label>Đang tải file lên máy chủ...</span>
                        <span data-upload-progress-value>0%</span>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Tiến độ tải file lên" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" data-upload-progress-bar style="width: 0%"></div>
                    </div>
                </div>
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
        const uploadProgress = document.getElementById('household-import-upload-progress');
        const uploadProgressLabel = uploadProgress.querySelector('[data-upload-progress-label]');
        const uploadProgressValue = uploadProgress.querySelector('[data-upload-progress-value]');
        const uploadProgressTrack = uploadProgress.querySelector('[role="progressbar"]');
        const uploadProgressBar = uploadProgress.querySelector('[data-upload-progress-bar]');

        importForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitButton.disabled) return;

            submitButton.disabled = true;
            submitButton.setAttribute('aria-busy', 'true');
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Đang tải lên...';
            uploadProgress.classList.remove('d-none');

            try {
                const payload = await new Promise((resolve, reject) => {
                    const request = new XMLHttpRequest();
                    request.open('POST', importForm.action);
                    request.setRequestHeader('Accept', 'application/json');
                    request.withCredentials = true;
                    request.upload.addEventListener('progress', (progressEvent) => {
                        if (!progressEvent.lengthComputable) return;
                        const percent = Math.round(progressEvent.loaded / progressEvent.total * 100);
                        uploadProgressValue.textContent = `${percent}%`;
                        uploadProgressTrack.setAttribute('aria-valuenow', String(percent));
                        uploadProgressBar.style.width = `${percent}%`;
                    });
                    request.addEventListener('load', () => {
                        let responsePayload = {};
                        try {
                            responsePayload = JSON.parse(request.responseText);
                        } catch {
                            reject(new Error('Máy chủ trả về phản hồi không hợp lệ.'));
                            return;
                        }
                        if (request.status === 202) {
                            resolve(responsePayload);
                            return;
                        }
                        const validationMessage = Object.values(responsePayload.errors || {}).flat()[0];
                        reject(new Error(validationMessage || responsePayload.message || 'Không thể đưa file vào hàng đợi.'));
                    });
                    request.addEventListener('error', () => reject(new Error('Không thể kết nối để tải file lên.')));
                    request.addEventListener('abort', () => reject(new Error('Đã hủy tải file lên.')));
                    request.send(new FormData(importForm));
                });
                uploadProgressLabel.textContent = 'Đã tải file lên, đang chuyển tới lịch sử xử lý...';
                uploadProgressValue.textContent = '100%';
                uploadProgressTrack.setAttribute('aria-valuenow', '100');
                uploadProgressBar.style.width = '100%';
                window.location.assign(payload.history_url);
            } catch (error) {
                submitButton.disabled = false;
                submitButton.removeAttribute('aria-busy');
                submitButton.innerHTML = '<i class="fa-solid fa-file-import me-1" aria-hidden="true"></i> Nhập dữ liệu';
                uploadProgress.classList.add('d-none');
                window.showAppNotification('error', error.message || 'Không thể tải file lên.');
            }
        });
    })();
</script>
@endpush
@endsection