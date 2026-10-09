@extends('layouts.app')

@section('title', 'Cập nhật tài khoản')

@section('content')
<div class="container">
    <h1 class="h3 mb-3">Cập nhật tài khoản</h1>
    @include('admin.access-control.users._form', ['action' => route('admin.users.update', $user), 'method' => 'PUT'])
</div>
@endsection
