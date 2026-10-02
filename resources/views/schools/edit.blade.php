@extends('layouts.app')

@section('title', 'Sửa trường học - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('schools.index') }}">&larr; Danh sách trường học</a>
        <h1 class="h3 mt-3 mb-0">Sửa thông tin trường học</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('schools._form', ['action' => route('schools.update', $school), 'method' => 'PUT'])
    </section>
</div>
@endsection