@extends('layouts.app')

@section('title', 'Sửa nhóm người dùng')

@section('content')
<div class="container">
    <h1 class="h3 mb-3">Sửa nhóm người dùng / phòng ban</h1>
    @include('admin.access-control.roles._form', ['action' => route('admin.roles.update', $role), 'method' => 'PUT'])
</div>
@endsection
