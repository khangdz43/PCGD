@extends('layouts.app')

@section('title', 'Hộ ' . $household->household_code . ' - PCGD-XMC')

@push('styles')
<style>
    .household-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .75rem;
    }

    .household-summary-item {
        min-width: 0;
        padding: .85rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: .65rem;
        background: #fff;
    }

    .household-summary-label {
        display: block;
        margin-bottom: .25rem;
        color: #64748b;
        font-size: .78rem;
        font-weight: 600;
    }

    .household-summary-value {
        color: #1e293b;
        font-weight: 600;
        overflow-wrap: anywhere;
    }

    .household-detail-table th {
        width: 11rem;
        color: #475569;
        font-weight: 600;
    }

    .household-members-panel {
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: .75rem;
        background: #fff;
        box-shadow: 0 2px 8px rgb(15 23 42 / 4%);
    }

    .household-members-header {
        padding: 1rem 1.1rem;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
    }

    .household-members-table {
        min-width: 780px;
    }

    .household-members-table th {
        padding: .8rem 1rem;
        color: #475569;
        font-size: .78rem;
        font-weight: 700;
        letter-spacing: .025em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .household-members-table td {
        padding: .8rem 1rem;
    }

    .household-member-name {
        min-width: 190px;
        color: #0f172a;
        font-weight: 650;
    }

    .household-member-actions .btn {
        min-width: 68px;
    }

    @media (max-width: 767.98px) {
        .household-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .5rem;
        }

        .household-summary-item {
            padding: .7rem;
        }

        .household-detail-table th {
            width: 8rem;
        }

        .household-members-header {
            padding: .85rem;
        }

        .household-members-table th,
        .household-members-table td {
            padding: .7rem .8rem;
        }
    }

    @media (max-width: 359.98px) {
        .household-summary {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

@section('content')
<div class="container">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                <h1 class="h4 mb-0">Chi tiết hộ {{ $household->household_code }}</h1>
                <span class="badge text-bg-light border">{{ $household->school_year }}</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">{{ $canSeeHouseholdHead ? ($headPerson ? trim($headPerson->last_name . ' ' . $headPerson->first_name) : trim($household->head_last_name . ' ' . $household->head_first_name)) : 'Hộ gia đình' }}</h2>
                @if ($canSeeHouseholdHead)
                    <span class="badge bg-primary">Chủ hộ</span>
                @endif
            </div>
        </div>
        <div class="d-flex gap-2">
            @can('update-households')
                <a class="btn btn-outline-primary" href="{{ route('households.edit', $household, false) }}">
                    <i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Sửa hộ
                </a>
            @endcan
            @can('create-households')
                @if ($hasAnyHouseholdHead)
                    <a class="btn btn-primary" href="{{ route('households.members.create', $household, false) }}">
                        <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i> Thêm thành viên
                    </a>
                @else
                    <a class="btn btn-primary" href="{{ route('households.members.create', [$household, 'is_head' => 1], false) }}">
                        <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i> Nhập thông tin chủ hộ
                    </a>
                @endif
            @endcan
        </div>
    </div>

    @unless ($hasAnyHouseholdHead)
    <div class="app-inline-note" role="status">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>Cần lưu hồ sơ chủ hộ vào danh sách nhân khẩu trước khi thêm thành viên khác.</span>
    </div>
    @endunless

    <section class="household-summary mb-3" aria-label="Tóm tắt hộ gia đình">
        <div class="household-summary-item">
            <span class="household-summary-label">Chủ hộ</span>
            <span class="household-summary-value">
                {{ $canSeeHouseholdHead ? ($headPerson ? trim($headPerson->last_name . ' ' . $headPerson->first_name) : trim($household->head_last_name . ' ' . $household->head_first_name)) : 'Hộ gia đình' }}
            </span>
        </div>
        <div class="household-summary-item">
            <span class="household-summary-label">Số nhân khẩu</span>
            <span class="household-summary-value">{{ $persons->count() }} người</span>
        </div>
        <div class="household-summary-item">
            <span class="household-summary-label">Năm học</span>
            <span class="household-summary-value">{{ $household->school_year }}</span>
        </div>
        <div class="household-summary-item">
            <span class="household-summary-label">Tình trạng cư trú</span>
            <span class="household-summary-value">{{ ['THUONG_TRU' => 'Thường trú', 'TAM_TRU' => 'Tạm trú', 'KHAC' => 'Khác'][$household->residence_type] ?? 'Chưa cập nhật' }}</span>
        </div>
    </section>

    <section class="bg-white border rounded-2 mb-4">
        <h2 class="h6 border-bottom px-3 py-2 mb-0">Địa chỉ và cư trú</h2>
        <div class="table-responsive">
            <table class="table table-sm household-detail-table mb-0">
                <tbody>
                    <tr>
                        <th scope="row" class="ps-3">Địa bàn</th>
                        <td>{{ $household->province->name ?? '—' }} / {{ $household->commune->name ?? '—' }} / {{ $household->village->name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="ps-3">Địa chỉ cụ thể</th>
                        <td>{{ $household->address ?: 'Chưa cập nhật' }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="ps-3">Tình trạng</th>
                        <td>
                            {{ ['THUONG_TRU' => 'Thường trú', 'TAM_TRU' => 'Tạm trú', 'KHAC' => 'Khác'][$household->residence_type] ?? '—' }}
                            @if ($household->residence_status)
                                · {{ $household->residence_status }}
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="household-members-panel">
        <div class="household-members-header d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1"><i class="fa-solid fa-people-group text-primary me-2" aria-hidden="true"></i>Danh sách nhân khẩu</h2>
                <p class="small text-muted mb-0">Toàn bộ nhân khẩu trong hộ; có thể xem, sửa hoặc xóa từng hồ sơ theo quyền được cấp.</p>
            </div>
            <span class="badge rounded-pill text-bg-primary px-3 py-2">{{ $persons->count() }} người</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 household-members-table">
                <thead class="table-light">
                <tr>
                    <th scope="col" class="text-center">STT</th>
                    <th scope="col">Họ và tên</th>
                    <th scope="col">Quan hệ với chủ hộ</th>
                    <th scope="col">Ngày sinh</th>
                    <th scope="col">Giới tính</th>
                    <th scope="col">Dân tộc</th>
                    <th scope="col" class="text-end">Thao tác</th>
                </tr>
                </thead>
                <tbody>
                    @php
                        $hasOtherHouseholdMembers = $persons->contains(fn ($member) => $member->relationship_with_head !== 'Chủ hộ');
                    @endphp
                    @forelse ($persons as $person)
                    <tr>
                        <td class="text-center text-muted">{{ $loop->iteration }}</td>
                        <td class="household-member-name">{{ trim($person->last_name . ' ' . $person->first_name) }}</td>
                        <td>
                            @if ($person->relationship_with_head === 'Chủ hộ')
                                <span class="badge rounded-pill text-bg-primary">Chủ hộ</span>
                            @else
                                {{ $person->relationship_with_head ?: '—' }}
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $person->dob?->format('d/m/Y') ?? $person->dob_str ?? '—' }}</td>
                        <td>{{ ['NAM' => 'Nam', 'NU' => 'Nữ'][$person->gender] ?? '—' }}</td>
                        <td>{{ $person->ethnicity->name ?? '—' }}</td>
                        <td class="text-end text-nowrap household-member-actions">
                            <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('households.members.show', [$household, $person->id], false) }}" title="Xem hồ sơ nhân khẩu" aria-label="Xem hồ sơ {{ $person->first_name }}">
                                    <i class="fa-solid fa-eye me-1" aria-hidden="true"></i><span>Xem</span>
                                </a>
                                @can('update-households')
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('households.members.edit', [$household, $person->id], false) }}" title="Sửa thành viên {{ $person->first_name }}">
                                        <i class="fa-solid fa-pen me-1" aria-hidden="true"></i><span>Sửa</span>
                                    </a>
                                @endcan
                                @can('delete-households')
                                    @if ($person->relationship_with_head === 'Chủ hộ' && $hasOtherHouseholdMembers)
                                        <button class="btn btn-sm btn-outline-danger" type="button" disabled title="Hãy xóa các thành viên khác trước khi xóa chủ hộ">
                                            <i class="fa-solid fa-trash me-1" aria-hidden="true"></i><span>Xóa</span>
                                        </button>
                                    @else
                                        <form class="d-inline" method="POST" action="{{ route('households.members.destroy', [$household, $person->id], false) }}" onsubmit="return confirm('Bạn có chắc muốn xóa {{ trim($person->last_name . ' ' . $person->first_name) }} khỏi hộ?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa thành viên {{ $person->first_name }}">
                                                <i class="fa-solid fa-trash me-1" aria-hidden="true"></i><span>Xóa</span>
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fa-solid fa-users d-block fs-3 mb-2" aria-hidden="true"></i>
                            Chưa có nhân khẩu trong hộ.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-top px-3 py-2 small text-muted d-md-none">
            <i class="fa-solid fa-arrows-left-right me-1" aria-hidden="true"></i> Vuốt ngang bảng để xem thông tin và thao tác.
        </div>
    </section>
</div>
@endsection