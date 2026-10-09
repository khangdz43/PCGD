@extends('layouts.app')

@section('title', 'Thêm nhóm người dùng')

@section('content')
<div class="container">
    <h1 class="h3 mb-3">Thêm nhóm người dùng / phòng ban</h1>
    @include('admin.access-control.roles._form', ['action' => route('admin.roles.store'), 'method' => 'POST'])
</div>
@endsection
