@extends('layouts.app')

@section('title', 'Tạo tài khoản')

@section('content')
<div class="container">
    <h1 class="h3 mb-3">Tạo tài khoản cấp dưới</h1>
    @include('admin.access-control.users._form', ['action' => route('admin.users.store'), 'method' => 'POST'])
</div>
@endsection
