@extends('layouts.app')

@section('title', 'Thêm thành viên - PCGD-XMC')

@section('content')
<div class="container">
    <div class="mb-4">
        <h1 class="h5 mb-1">{{ $isHouseholdHead ? 'Thông tin chủ hộ' : 'Thêm thành viên vào hộ' }}</h1>
        <p class="small text-muted mb-0">Chủ hộ: <strong>{{ trim($household->head_last_name . ' ' . $household->head_first_name) }}</strong></p>
    </div>
    @if ($isHouseholdHead)
    <p class="text-muted">Tên được lấy từ thông tin chủ hộ của phiếu. Bổ sung ngày sinh, giới tính, dân tộc và thông tin liên hệ để lưu chủ hộ vào danh sách nhân khẩu.</p>
    @endif
    <section class="bg-white border rounded-2 p-3">
        @include('households.members._form', [
        'action' => route('households.members.store', $household, false),
        'method' => 'POST',
        ])
    </section>
</div>
@endsection