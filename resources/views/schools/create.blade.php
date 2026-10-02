@extends('layouts.app')

@section('title', 'Thêm trường học - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('schools.index') }}">&larr; Danh sách trường học</a>
        <h1 class="h3 mt-3 mb-0">Thêm trường học</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('schools._form', ['action' => route('schools.store'), 'method' => 'POST'])
    </section>
</div>
@endsection