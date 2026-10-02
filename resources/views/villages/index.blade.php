@extends('layouts.app')

@section('title', 'Danh sách thôn/xóm - PCGD-XMC')

@section('content')
<div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Danh sách thôn/xóm</h1>
            <p class="text-muted mb-0">Quản lý thôn, bản theo xã/phường.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('villages.create') }}">
            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Thêm thôn/xóm
        </a>
    </div>
    <!-- check cái truyền từ controller success í -->
    @if (session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('villages.index') }}" class="bg-white border rounded-2 p-3 mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="village-search" class="form-label">Tìm theo mã hoặc tên</label>
                <!-- request('q') lấy value q trên url  -->
                <input id="village-search" name="q" type="search" class="form-control" value="{{ request('q') }}" placeholder="Nhập mã hoặc tên thôn/xóm">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary" type="submit">
                    <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i> Lọc danh sách
                </button>
                <!-- điền hết render xóa lọc -->
                @if (request()->filled('q') || request()->filled('province_code') || request()->filled('commune_code'))
                <a class="btn btn-outline-secondary" href="{{ route('villages.index') }}">Xóa lọc</a>
                @endif
            </div>
            <div class="col-12">
                <!-- include cái chọn location -->
                @include('partials.location-picker', [
                'pickerId' => 'village-filter-location',
                'provinces' => $provinces,
                ])
            </div>
        </div>
    </form>

    <div class="table-responsive bg-white border rounded-2">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Mã thôn/xóm</th>
                    <th scope="col">Tên thôn/xóm</th>
                    <th scope="col">Tỉnh/Thành phố</th>
                    <th scope="col">Xã/Phường</th>
                    <th scope="col" class="text-end">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($villages as $village)
                <tr>
                    <td class="text-nowrap">{{ $village->code }}</td>
                    <td>{{ $village->name }}</td>
                    <td>{{ $village->commune->province->name ?? '—' }}</td>
                    <td>{{ $village->commune->name ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('villages.edit', $village->code) }}" title="Sửa" aria-label="Sửa {{ $village->name }}">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                        </a>
                        <form class="d-inline" method="POST" action="{{ route('villages.destroy', $village->code) }}" onsubmit="return confirm('Bạn có chắc muốn xóa thôn/xóm này?')">
                            {{-- sinh csrdf chống tấn công --}}
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa" aria-label="Xóa {{ $village->name }}">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-5">Chưa có thôn/xóm phù hợp.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- phân trang do thăng villages nó là đối tượng Page r ở controller index í -->
    <div class="mt-3">{{ $villages->links() }}</div>
</div>
@endsection