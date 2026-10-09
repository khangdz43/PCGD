@extends('layouts.app')

@section('title', 'Sửa thôn/xóm - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <h1 class="h3 mb-0">Sửa thông tin thôn/xóm</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('villages._form', ['action' => route('villages.update', $village->code, false), 'method' => 'PUT'])
    </section>
</div>
@endsection