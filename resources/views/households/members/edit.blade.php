@extends('layouts.app')

@section('title', 'Sửa thành viên - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <a class="text-decoration-none" href="{{ route('households.show', $household, false) }}">&larr; Hộ {{ $household->household_code }}</a>
        <h1 class="h5 mt-2 mb-1">{{ $isHouseholdHead ? 'Sửa thông tin chủ hộ' : 'Sửa thông tin thành viên' }}</h1>
        <p class="small text-muted mb-0">Chủ hộ: <strong>{{ trim($household->head_last_name . ' ' . $household->head_first_name) }}</strong></p>
    </div>
    <section class="bg-white border rounded-2 p-3">
        @include('households.members._form', [
        'action' => route('households.members.update', [$household, $person->id], false),
        'method' => 'PUT',
        ])
    </section>
</div>
@endsection