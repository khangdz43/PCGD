@extends('layouts.app')

@section('title', 'Sửa thôn/xóm - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('villages.index', [], false) }}">&larr; Danh sách thôn/xóm</a>
        <h1 class="h3 mt-3 mb-0">Sửa thông tin thôn/xóm</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('villages._form', ['action' => route('villages.update', $village->code, false), 'method' => 'PUT'])
    </section>
</div>
@endsection