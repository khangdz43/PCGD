<header class="bg-white shadow-sm border-bottom py-2">
    <div class="container app-header-content d-flex align-items-center justify-content-between">
        <div class="app-brand d-flex align-items-center gap-3">
            <div class="app-brand-mark d-flex align-items-center justify-content-center rounded-2 bg-primary text-white fw-bold flex-shrink-0" style="width: 50px; height: 50px;" role="img" aria-label="PCGD">PCGD</div>
            <div>
                <h2 class="app-brand-title m-0 fw-bold text-primary">PHẦN MỀM PHỔ CẬP GIÁO DỤC - XÓA MÙ CHỮ</h2>
                <small class="app-brand-subtitle text-muted">Hệ thống quản lý dữ liệu phổ cập giáo dục cấp xã</small>
            </div>
        </div>
        @auth
        <div class="app-account d-flex align-items-center gap-3">
            <div class="text-end">
                <a class="app-account-name badge bg-success text-decoration-none" href="{{ route('account.show') }}" title="Xem thông tin tài khoản">
                    <i class="fa-solid fa-user me-1"></i>
                    {{ auth()->user()->name }}
                    <i class="fa-solid fa-gear ms-1" aria-hidden="true"></i>
                </a>

                @php
                $user = auth()->user();
                @endphp

                <div class="app-account-location small text-muted mt-1">
                    @if($user->village)
                    <i class="fa-solid fa-location-dot me-1"></i>
                    Phạm vi: Thôn {{ $user->village->name }}
                    @elseif($user->commune)
                    <i class="fa-solid fa-location-dot me-1"></i>
                    Phạm vi: Xã {{ $user->commune->name }}
                    @elseif($user->province)
                    <i class="fa-solid fa-location-dot me-1"></i>
                    Phạm vi: Tỉnh {{ $user->province->name }}
                    @else
                    <i class="fa-solid fa-globe me-1"></i>
                    Phạm vi: Toàn hệ thống
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-outline-secondary btn-sm" type="submit">
                    Đăng xuất
                </button>
            </form>
        </div>
        @endauth
    </div>
</header>