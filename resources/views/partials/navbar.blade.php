<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
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
                @php
                $isCategoryActive = request()->routeIs('villages.*') || request()->routeIs('schools.*');
                @endphp
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle {{ $isCategoryActive ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-list me-1"></i> Danh mục
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item {{ request()->routeIs('villages.*') ? 'active' : '' }}" href="{{ route('villages.index', [], false) }}">
                                1. Danh sách Thôn / Xóm
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item {{ request()->routeIs('schools.*') ? 'active' : '' }}" href="{{ route('schools.index', [], false) }}">
                                2. Danh sách Trường học
                            </a>
                        </li>
                    </ul>
                </li>

                {{-- Phiếu điều tra: Active khi Route hiện tại thuộc nhóm 'households.*' --}}
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('households.*') ? 'active' : '' }}" href="{{ route('households.index', [], false) }}">
                        <i class="fa-solid fa-file-pen me-1"></i> Phiếu điều tra
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>