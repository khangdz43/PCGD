@extends('layouts.app')

@section('title', 'Thêm thôn/xóm - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('villages.index', [], false) }}">&larr; Danh sách thôn/xóm</a>
        <h1 class="h3 mt-3 mb-0">Thêm thôn/xóm</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('villages._form', ['action' => route('villages.store', [], false), 'method' => 'POST'])
    </section>
</div>
@endsection