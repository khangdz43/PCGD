<nav class="app-navbar navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Mở hoặc đóng menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                {{-- Trang chủ: Active khi ở trang chủ (route name = 'home' hoặc uri = '/') --}}
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('/') ? 'active' : '' }}" href="/">
                        <i class="fa-solid fa-house me-1"></i> Trang chủ
                    </a>
                </li>

                {{-- Dropdown Danh mục: Active khi Route hiện tại thuộc nhóm 'villages.*' HOẶC 'schools.*' --}}
                @canany(['manage-villages', 'manage-schools'])
                    @php
                    $isCategoryActive = request()->routeIs('villages.*') || request()->routeIs('schools.*');
                    @endphp
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ $isCategoryActive ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fa-solid fa-list me-1"></i> Danh mục
                        </a>
                        <ul class="dropdown-menu">
                            @can('manage-villages')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('villages.*') ? 'active' : '' }}" href="{{ route('villages.index', [], false) }}">
                                        Danh sách Thôn / Xóm
                                    </a>
                                </li>
                            @endcan
                            @can('manage-schools')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('schools.*') ? 'active' : '' }}" href="{{ route('schools.index', [], false) }}">
                                        Danh sách Trường học
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endcan

                @canany(['view-households', 'create-households', 'import-households', 'export-households'])
                    @php
                        $householdDestination = auth()->user()->can('view-households')
                            ? route('households.index', [], false)
                            : (auth()->user()->can('create-households')
                                ? route('households.create', [], false)
                                : (auth()->user()->can('import-households')
                                    ? route('households.import.create', [], false)
                                    : route('households.export', [], false)));
                    @endphp
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('households.*') ? 'active' : '' }}" href="{{ $householdDestination }}">
                            <i class="fa-solid fa-file-pen me-1"></i> Phiếu điều tra
                        </a>
                    </li>
                @endcanany

                @canany(['manage-access-control', 'manage-user-accounts'])
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('admin.*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-shield-halved me-1"></i> Quản trị
                        </a>
                        <ul class="dropdown-menu">
                            @can('manage-access-control')
                                <li><a class="dropdown-item" href="{{ route('admin.roles.index') }}">Nhóm người dùng</a></li>
                            @endcan
                            @can('manage-user-accounts')
                                <li><a class="dropdown-item" href="{{ route('admin.users.index') }}">Quản lý tài khoản</a></li>
                            @endcan
                        </ul>
                    </li>
                @endcan
            </ul>
        </div>
    </div>
</nav>