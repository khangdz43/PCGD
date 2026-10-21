<nav class="app-navbar navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid px-3 px-lg-5">
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

                {{-- Dropdown Danh mục: Active khi Route hiện tại thuộc nhóm danh mục --}}
                @canany(['manage-villages', 'manage-schools', 'manage-access-control'])
                    @php
                    $isCategoryActive = request()->routeIs('villages.*') || request()->routeIs('schools.*') || request()->routeIs('admin.roles.*');
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
                            @can('manage-access-control')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}" href="{{ route('admin.roles.index', [], false) }}">
                                        Nhóm người dùng
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
                            @can('manage-user-accounts')
                                <li><a class="dropdown-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index', [], false) }}">Quản lý tài khoản</a></li>
                            @endcan
                        </ul>
                    </li>
                @endcan

                @canany(['delete-households', 'manage-villages', 'manage-schools'])
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('utilities.*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-screwdriver-wrench me-1"></i> Tiện ích
                        </a>
                        <ul class="dropdown-menu">
                            @can('delete-households')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('utilities.trash.*') ? 'active' : '' }}" href="{{ route('utilities.trash.index', [], false) }}">
                                        <i class="fa-solid fa-trash-can me-2" aria-hidden="true"></i>Thùng rác
                                    </a>
                                </li>
                            @endcan
                            @can('manage-villages')
                                @if (auth()->user()->roleScopeLevel() === 'commune')
                                    <li>
                                        <a class="dropdown-item {{ request()->routeIs('utilities.villages.*') ? 'active' : '' }}" href="{{ route('utilities.villages.merge', [], false) }}">
                                            <i class="fa-solid fa-code-merge me-2" aria-hidden="true"></i>Gộp thôn
                                        </a>
                                    </li>
                                @endif
                            @endcan
                            @can('manage-schools')
                                <li>
                                    <span class="dropdown-item disabled" aria-disabled="true">
                                        <i class="fa-solid fa-code-merge me-2" aria-hidden="true"></i>Gộp trường
                                        <small class="text-muted ms-1">Sắp có</small>
                                    </span>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endcan
            </ul>
        </div>
    </div>
</nav>