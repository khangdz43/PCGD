@php
    $breadcrumbs = [
        ['label' => 'Trang chủ', 'url' => route('index', [], false)],
    ];
    $currentRoute = request()->route();
    $household = $currentRoute?->parameter('household');
    $householdCode = $household?->household_code;
    $canViewHouseholds = auth()->user()?->can('view-households') ?? false;

    if (request()->routeIs('households.*')) {
        $householdList = ['label' => 'Phiếu điều tra', 'url' => route('households.index', [], false)];
        $householdCrumb = [
            'label' => $householdCode ? 'Hộ ' . $householdCode : 'Phiếu điều tra',
            'url' => $household ? route('households.show', $household, false) : null,
        ];

        if (request()->routeIs('households.index')) {
            $breadcrumbs[] = ['label' => 'Phiếu điều tra'];
        } elseif (request()->routeIs('households.create')) {
            if ($canViewHouseholds) {
                $breadcrumbs[] = $householdList;
            }
            $breadcrumbs[] = ['label' => 'Tạo phiếu'];
        } elseif (request()->routeIs('households.import.*')) {
            if ($canViewHouseholds) {
                $breadcrumbs[] = $householdList;
            }
            $breadcrumbs[] = ['label' => 'Nhập dữ liệu'];
        } elseif (request()->routeIs('households.import-history')) {
            if ($canViewHouseholds) {
                $breadcrumbs[] = $householdList;
            }
            $breadcrumbs[] = ['label' => 'Lịch sử tải lên'];
        } elseif (request()->routeIs('households.show')) {
            if ($canViewHouseholds) {
                $breadcrumbs[] = $householdList;
            }
            $breadcrumbs[] = ['label' => $householdCrumb['label']];
        } elseif (request()->routeIs('households.edit')) {
            if ($canViewHouseholds) {
                $breadcrumbs[] = $householdList;
                $breadcrumbs[] = $householdCrumb;
            }
            $breadcrumbs[] = ['label' => 'Sửa phiếu'];
        } elseif (request()->routeIs('households.members.*')) {
            if ($canViewHouseholds) {
                $breadcrumbs[] = $householdList;
                $breadcrumbs[] = $householdCrumb;
            }

            if (request()->routeIs('households.members.show')) {
                $memberName = trim(($person->last_name ?? '') . ' ' . ($person->first_name ?? ''));
                $breadcrumbs[] = ['label' => $memberName ?: 'Hồ sơ nhân khẩu'];
            } elseif (request()->routeIs('households.members.edit')) {
                $breadcrumbs[] = ['label' => 'Sửa hồ sơ nhân khẩu'];
            } else {
                $breadcrumbs[] = [
                    'label' => ! empty($isHouseholdHead) ? 'Thông tin chủ hộ' : 'Thêm thành viên',
                ];
            }
        }
    } elseif (request()->routeIs('villages.*')) {
        if (request()->routeIs('villages.index')) {
            $breadcrumbs[] = ['label' => 'Danh sách thôn / xóm'];
        } else {
            $breadcrumbs[] = ['label' => 'Danh sách thôn / xóm', 'url' => route('villages.index', [], false)];
            $breadcrumbs[] = ['label' => request()->routeIs('villages.create') ? 'Thêm thôn / xóm' : 'Sửa thôn / xóm'];
        }
    } elseif (request()->routeIs('schools.*')) {
        if (request()->routeIs('schools.index')) {
            $breadcrumbs[] = ['label' => 'Danh sách trường học'];
        } else {
            $breadcrumbs[] = ['label' => 'Danh sách trường học', 'url' => route('schools.index', [], false)];
            $breadcrumbs[] = ['label' => request()->routeIs('schools.create') ? 'Thêm trường học' : 'Sửa trường học'];
        }
    } elseif (request()->routeIs('account.*')) {
        $breadcrumbs[] = ['label' => 'Thông tin tài khoản'];
    } elseif (request()->routeIs('admin.roles.*')) {
        if (request()->routeIs('admin.roles.index')) {
            $breadcrumbs[] = ['label' => 'Quản lý nhóm người dùng'];
        } else {
            $breadcrumbs[] = ['label' => 'Quản lý nhóm người dùng', 'url' => route('admin.roles.index')];
            $breadcrumbs[] = ['label' => request()->routeIs('admin.roles.create') ? 'Tạo nhóm người dùng' : 'Sửa nhóm người dùng'];
        }
    } elseif (request()->routeIs('admin.users.*')) {
        if (request()->routeIs('admin.users.index')) {
            $breadcrumbs[] = ['label' => 'Quản lý tài khoản'];
        } else {
            $breadcrumbs[] = ['label' => 'Quản lý tài khoản', 'url' => route('admin.users.index')];
            $breadcrumbs[] = ['label' => request()->routeIs('admin.users.create') ? 'Tạo tài khoản' : 'Sửa tài khoản'];
        }
    }
@endphp

@if (count($breadcrumbs) > 1)
    <div class="app-breadcrumb-wrap mb-2 py-1">
        <div class="container">
            <nav aria-label="Đường dẫn trang">
                <ol class="breadcrumb app-breadcrumb">
                    @foreach ($breadcrumbs as $breadcrumb)
                        @if (! $loop->last && ! empty($breadcrumb['url']))
                            <li class="breadcrumb-item">
                                <a href="{{ $breadcrumb['url'] }}">{{ $breadcrumb['label'] }}</a>
                            </li>
                        @else
                            <li class="breadcrumb-item {{ $loop->last ? 'active' : '' }}" @if ($loop->last) aria-current="page" @endif>
                                {{ $breadcrumb['label'] }}
                            </li>
                        @endif
                    @endforeach
                </ol>
            </nav>
        </div>
    </div>
@endif
