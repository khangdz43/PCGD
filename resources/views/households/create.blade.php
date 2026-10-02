@extends('layouts.app')

@section('title', 'Tạo hộ - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('households.index') }}">&larr; Danh sách phiếu</a>
        <h1 class="h3 mt-3 mb-0">Tạo hộ</h1>
        <p class="text-muted mb-0">Nhập thông tin hộ và chủ hộ. Nhân khẩu được thêm sau khi lưu hộ.</p>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('households._form', [
        'action' => route('households.store'),
        'method' => 'POST',
        'cancelUrl' => route('households.index'),
        ])
    </section>
</div>
@endsection