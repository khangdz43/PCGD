@extends('layouts.app')

@section('title', 'Sửa trường học - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <h1 class="h3 mb-0">Sửa thông tin trường học</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('schools._form', ['action' => route('schools.update', $school, false), 'method' => 'PUT'])
    </section>
</div>
@endsection