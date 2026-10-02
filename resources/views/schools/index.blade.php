@extends('layouts.app')

@section('title', 'Danh sách trường học - PCGD-XMC')

@section('content')
<div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Danh sách trường học</h1>
            <p class="text-muted mb-0">Quản lý thông tin các trường trong địa bàn.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('schools.create') }}">
            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Thêm trường học
        </a>
    </div>

    @if (session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('schools.index') }}" class="bg-white border rounded-2 p-3 mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="q" class="form-label">Tìm theo mã hoặc tên trường</label>
                <input id="q" name="q" type="search" class="form-control" value="{{ request('q') }}" placeholder="Nhập mã hoặc tên trường">
            </div>
            <div class="col-md-3">
                <label for="level" class="form-label">Cấp học</label>
                <select id="level" name="level" class="form-select">
                    <option value="">-- Tất cả cấp học --</option>
                    @foreach (['MN', 'TH', 'THCS'] as $level)
                    <option value="{{ $level }}" @selected(request('level')===$level)>{{ $level }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary" type="submit">
                    <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i> Tìm kiếm
                </button>
                @if (request()->filled('q') || request()->filled('level') || request()->filled('province_code') || request()->filled('commune_code'))
                <a class="btn btn-outline-secondary" href="{{ route('schools.index') }}">Xóa lọc</a>
                @endif
            </div>
            <div class="col-12">
                @include('partials.location-picker', [
                'pickerId' => 'school-filter-location',
                'provinces' => $provinces,
                ])
            </div>
        </div>
    </form>

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
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('schools.edit', $school) }}" title="Sửa" aria-label="Sửa {{ $school->name }}">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                        </a>
                        <form class="d-inline" method="POST" action="{{ route('schools.destroy', $school) }}" onsubmit="return confirm('Bạn có chắc muốn xóa trường học này?')">
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
@endsection