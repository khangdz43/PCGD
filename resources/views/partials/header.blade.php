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
        @php
            $user = auth()->user();
            $schoolYearStart = now()->month >= 8 ? now()->year : now()->year - 1;
            $currentSchoolYear = $schoolYearStart.'-'.($schoolYearStart + 1);
        @endphp
        <div class="app-account">
            <div class="app-account-card">
                <span class="app-account-avatar" aria-hidden="true">
                    <i class="fa-solid fa-user"></i>
                </span>
                <div class="app-account-details">
                    <a class="app-account-name" href="{{ route('account.show') }}" title="Xem thông tin tài khoản">
                        <span>{{ $user->name }}</span>
                        <i class="fa-solid fa-gear" aria-hidden="true"></i>
                    </a>
                    <div class="app-account-location">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        <span>
                            @if($user->village)
                                Phạm vi: Thôn {{ $user->village->name }}
                            @elseif($user->commune)
                                Phạm vi: Xã {{ $user->commune->name }}
                            @elseif($user->province)
                                Phạm vi: Tỉnh {{ $user->province->name }}
                            @else
                                Phạm vi: Toàn hệ thống
                            @endif
                        </span>
                    </div>
                    <div class="app-account-location">
                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                        <span>Năm học: {{ $currentSchoolYear }}</span>
                    </div>
                </div>
            </div>

            <form class="app-logout-form" method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="app-logout-button" type="submit">
                    <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                    <span>Đăng xuất</span>
                </button>
            </form>
        </div>
        @endauth
    </div>
</header>