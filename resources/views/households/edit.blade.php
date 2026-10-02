@extends('layouts.app')

@section('title', 'Sửa phiếu điều tra - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('households.show', $household, false) }}">&larr; Hộ {{ $household->household_code }}</a>
        <h1 class="h3 mt-3 mb-0">Sửa phiếu điều tra hộ</h1>
    </div>
    <section class="bg-white border rounded-2 p-4">
        @include('households._form', [
        'action' => route('households.update', $household, false),
        'method' => 'PUT',
        'cancelUrl' => route('households.show', $household, false),
        ])
    </section>
</div>
@endsection