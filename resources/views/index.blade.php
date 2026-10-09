@extends('layouts.app')

@section('title', 'Chọn địa bàn - PCGD-XMC')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">
            <section class="bg-white border rounded-2 shadow-sm p-4 p-md-5">
                <h1 class="h4 mb-3">Trang chủ</h1>
                @canany(['view-households', 'create-households', 'import-households', 'export-households'])
                    <p class="text-muted mb-3">Các chức năng phiếu điều tra được cấp cho tài khoản:</p>
                    <div class="d-flex flex-wrap gap-2">
                        @can('view-households')
                            <a class="btn btn-outline-primary" href="{{ route('households.index', [], false) }}">Danh sách phiếu</a>
                        @endcan
                        @can('create-households')
                            <a class="btn btn-primary" href="{{ route('households.create', [], false) }}">Tạo phiếu mới</a>
                        @endcan
                        @can('import-households')
                            <a class="btn btn-outline-primary" href="{{ route('households.import.create', [], false) }}">Nhập dữ liệu</a>
                        @endcan
                        @can('export-households')
                            <a class="btn btn-outline-success" href="{{ route('households.export', [], false) }}">Xuất dữ liệu</a>
                        @endcan
                    </div>
                @else
                    Trang chủ
                @endcanany
            </section>
        </div>
    </div>
</div>
@endsection