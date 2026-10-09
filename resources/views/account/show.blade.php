@extends('layouts.app')

@section('title', 'Thông tin tài khoản')

@section('content')
    <div class="container">
        <div class="mb-4">
            <h1 class="h3 mb-1">Thông tin tài khoản</h1>
            <p class="text-muted mb-0">Thông tin nhóm quyền, quyền được cấp và phạm vi điều tra của bạn.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <section class="card shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Tài khoản và nhóm quyền</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Họ và tên</dt>
                            <dd class="col-sm-7">{{ $user->name }}</dd>

                            <dt class="col-sm-5">Tên đăng nhập</dt>
                            <dd class="col-sm-7">{{ $user->username }}</dd>

                            <dt class="col-sm-5">Trạng thái</dt>
                            <dd class="col-sm-7">
                                <span class="badge {{ $user->is_active ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $user->is_active ? 'Đang hoạt động' : 'Đã khóa' }}
                                </span>
                            </dd>

                            <dt class="col-sm-5">Cấp phạm vi</dt>
                            <dd class="col-sm-7">{{ $scopeLevelLabel }}</dd>
                        </dl>

                        <hr>
                        <h3 class="h6">Nhóm quyền được gán</h3>
                        @forelse ($user->roles as $role)
                            <div class="border rounded p-3 mb-2">
                                <div class="fw-semibold">{{ $role->name }}</div>
                                @if ($role->description)
                                    <div class="small text-muted mt-1">{{ $role->description }}</div>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted mb-0">Tài khoản chưa được gán nhóm quyền.</p>
                        @endforelse
                    </div>
                </section>
            </div>

            <div class="col-lg-7">
                <section class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Phạm vi điều tra được giao</h2>
                    </div>
                    <div class="card-body">
                        <ul class="mb-0">
                            @foreach ($scopeAreas as $area)
                                <li>{{ $area }}</li>
                            @endforeach
                        </ul>
                    </div>
                </section>

                <section class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Quyền chi tiết</h2>
                    </div>
                    <div class="card-body">
                        @if ($permissions->isEmpty())
                            <p class="text-muted mb-0">Tài khoản chưa được cấp quyền nghiệp vụ.</p>
                        @else
                            <ul class="list-group list-group-flush">
                                @foreach ($permissions as $permission)
                                    <li class="list-group-item px-0">
                                        <i class="fa-solid fa-check text-success me-2" aria-hidden="true"></i>
                                        {{ $permission['label'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            </div>

            <div class="col-12">
                <section class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h2 class="h5 mb-0">Đổi mật khẩu</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('account.password.update') }}" class="row g-3">
                            @csrf
                            @method('PUT')

                            <div class="col-md-4">
                                <label for="current_password" class="form-label">Mật khẩu hiện tại</label>
                                <input
                                    id="current_password"
                                    name="current_password"
                                    type="password"
                                    autocomplete="current-password"
                                    class="form-control @error('current_password') is-invalid @enderror"
                                    required>
                                @error('current_password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label for="password" class="form-label">Mật khẩu mới (ít nhất 12 ký tự)</label>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autocomplete="new-password"
                                    class="form-control @error('password') is-invalid @enderror"
                                    required>
                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label for="password_confirmation" class="form-label">Nhập lại mật khẩu mới</label>
                                <input
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    class="form-control"
                                    required>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-key me-1" aria-hidden="true"></i> Cập nhật mật khẩu
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
