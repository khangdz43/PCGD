@extends('layouts.app')

@section('title', 'Thêm trường học - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <h1 class="h3 mb-0">Thêm trường học</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('schools._form', ['action' => route('schools.store', [], false), 'method' => 'POST'])
    </section>
</div>
@endsection