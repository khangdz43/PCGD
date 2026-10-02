@extends('layouts.app')

@section('title', 'Danh sách trường học - PCGD-XMC')

@section('content')
<div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Danh sách trường học</h1>
            <p class="text-muted mb-0">Quản lý thông tin các trường trong địa bàn.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('schools.create', [], false) }}">
            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Thêm trường học
        </a>
    </div>

    @if (session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    <form id="school-filter-form" method="GET" action="{{ route('schools.index', [], false) }}" class="directory-filter-form bg-white border rounded-2 p-2 mb-3">
        <div class="directory-filter-field">
            <label for="school-search" class="visually-hidden">Mã hoặc tên trường</label>
            <input id="school-search" name="q" type="search" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Mã / Tên trường">
        </div>
        <div class="directory-filter-field">
            <label for="level" class="visually-hidden">Cấp học</label>
            <select id="level" name="level" class="form-select form-select-sm">
                <option value="">-- Tất cả cấp học --</option>
                @foreach (['MN', 'TH', 'THCS'] as $level)
                <option value="{{ $level }}" @selected(request('level')===$level)>{{ $level }}</option>
                @endforeach
            </select>
        </div>
        @include('partials.location-picker', [
        'pickerId' => 'school-filter-location',
        'provinces' => $provinces,
        'inline' => true,
        ])
        <button class="btn btn-sm btn-outline-secondary directory-filter-clear" type="button" id="clear-school-filters" title="Xóa bộ lọc" aria-label="Xóa bộ lọc">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
        <span id="school-filter-status" class="small text-danger" role="status" aria-live="polite"></span>
    </form>

    <div id="school-results" aria-live="polite">
        <div class="table-responsive bg-white border rounded-2">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Mã trường</th>
                        <th scope="col">Tên trường</th>
                        <th scope="col">Cấp học</th>
                        <th scope="col">Tỉnh/Thành phố</th>
                        <th scope="col">Xã/Phường</th>
                        <th scope="col" class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($schools as $school)
                    <tr>
                        <td class="text-nowrap">{{ $school->code }}</td>
                        <td>{{ $school->name }}</td>
                        <td>{{ $school->level ?: '—' }}</td>
                        <td>{{ $school->province->name ?? '—' }}</td>
                        <td>{{ $school->commune->name ?? '—' }}</td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('schools.edit', $school, false) }}" title="Sửa" aria-label="Sửa {{ $school->name }}">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                            </a>
                            <form class="d-inline" method="POST" action="{{ route('schools.destroy', $school, false) }}" onsubmit="return confirm('Bạn có chắc muốn xóa trường học này?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa" aria-label="Xóa {{ $school->name }}">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">Chưa có trường học phù hợp.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $schools->links('pagination::bootstrap-5') }}
        </div>
    </div>
</div>

@push('scripts')
<style>
    .directory-filter-form {
        display: grid;
        grid-template-columns: minmax(150px, 1.5fr) minmax(120px, 0.8fr) repeat(2, minmax(130px, 1fr)) auto;
        align-items: center;
        gap: 0.5rem;
    }

    .directory-filter-field,
    .directory-filter-form .location-picker-field {
        min-width: 0;
    }

    .directory-filter-form .location-picker-inline {
        display: contents;
    }

    .directory-filter-clear {
        width: 2rem;
        height: 2rem;
        padding: 0;
    }

    .directory-filter-form [id$="-filter-status"]:empty {
        display: none;
    }

    .directory-filter-form [id$="-filter-status"]:not(:empty) {
        grid-column: 1 / -1;
    }

    #school-results.directory-results-loading {
        opacity: 0.55;
        pointer-events: none;
    }

    @media (max-width: 767.98px) {
        .directory-filter-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>
<script>
    const schoolFilterForm = document.getElementById('school-filter-form');
    const schoolResults = document.getElementById('school-results');
    const schoolFilterStatus = document.getElementById('school-filter-status');
    const clearSchoolFilters = document.getElementById('clear-school-filters');
    let schoolFilterTimer;
    let schoolFilterRequest;

    function schoolFilterUrl() {
        const url = new URL(schoolFilterForm.action, window.location.href);
        url.search = '';
        new URLSearchParams(new FormData(schoolFilterForm)).forEach((value, key) => {
            if (value) url.searchParams.set(key, value);
        });
        return url;
    }

    function updateSchoolClearButton() {
        clearSchoolFilters.disabled = ![...new FormData(schoolFilterForm).values()].some((value) => String(value).trim());
    }

    async function refreshSchools(url = schoolFilterUrl()) {
        schoolFilterRequest?.abort();
        const requestController = new AbortController();
        schoolFilterRequest = requestController;
        schoolResults.setAttribute('aria-busy', 'true');
        schoolResults.classList.add('directory-results-loading');
        schoolFilterStatus.textContent = '';

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: requestController.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextResults = page.getElementById('school-results');
            if (!nextResults) throw new Error('School results were not found in the response.');

            schoolResults.innerHTML = nextResults.innerHTML;
            window.history.replaceState({}, '', `${url.pathname}${url.search}`);
            updateSchoolClearButton();
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Không tải được danh sách trường:', error);
                schoolFilterStatus.textContent = 'Không tải được kết quả lọc. Vui lòng thử lại.';
            }
        } finally {
            if (schoolFilterRequest === requestController) {
                schoolFilterRequest = null;
                schoolResults.removeAttribute('aria-busy');
                schoolResults.classList.remove('directory-results-loading');
            }
        }
    }

    schoolFilterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshSchools();
    });

    schoolFilterForm.addEventListener('input', (event) => {
        if (event.target.name !== 'q') return;
        clearTimeout(schoolFilterTimer);
        schoolFilterTimer = setTimeout(() => refreshSchools(), 300);
        updateSchoolClearButton();
    });

    schoolFilterForm.addEventListener('change', (event) => {
        if (event.target.matches('select')) refreshSchools();
        updateSchoolClearButton();
    });

    clearSchoolFilters.addEventListener('click', () => {
        schoolFilterForm.querySelectorAll('input[name], select[name]').forEach((field) => field.value = '');
        schoolFilterForm.querySelector('[data-province]').dispatchEvent(new Event('change', {
            bubbles: true
        }));
    });

    schoolResults.addEventListener('click', (event) => {
        const pageLink = event.target.closest('.pagination a');
        if (pageLink) {
            event.preventDefault();
            refreshSchools(new URL(pageLink.href, window.location.href));
        }
    });

    updateSchoolClearButton();
</script>
@endpush
@endsection