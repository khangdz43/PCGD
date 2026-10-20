@extends('layouts.app')

@section('title', 'Chi tiết hộ trong thùng rác - PCGD-XMC')

@push('styles')
<style>
    .trash-detail-table,
    .trash-member-table {
        min-width: 760px;
    }

    .trash-members-table-shell {
        overflow: hidden;
    }

    .trash-member-table {
        min-width: 3000px;
        font-size: .78rem;
    }

    .trash-member-table th,
    .trash-member-table td {
        padding: .35rem .45rem;
        vertical-align: middle;
    }

    .trash-member-table thead th {
        position: sticky;
        z-index: 2;
        background: #e9eef6;
        color: #1f2937;
        font-weight: 700;
        white-space: nowrap;
    }

    .trash-member-table thead tr:first-child th {
        position: sticky;
        top: 0;
        z-index: 3;
        height: 2.3rem;
        background: #2f67c8;
        color: #fff;
        text-align: center;
    }

    .trash-member-table thead tr:nth-child(2) th {
        top: 2.3rem;
    }

    .trash-member-table tbody tr.trash-household-head td {
        background: #fff2b2;
    }

    .trash-member-table .trash-member-name {
        min-width: 10rem;
        font-weight: 600;
        white-space: nowrap;
    }

    @media (max-width: 767.98px) {
        .trash-detail-table th {
            white-space: nowrap;
        }
    }
</style>
@endpush

@section('content')
<div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h5 mb-1">Chi tiết hộ {{ $household->household_code }}</h1>
            <p class="small text-muted mb-0">
                {{ trim($household->head_last_name.' '.$household->head_first_name) ?: 'Chưa có tên chủ hộ' }}
                · {{ $household->village?->name ?? $household->commune?->name ?? 'Chưa có địa bàn' }}
                {{ $household->province ? ', '.$household->province->name : '' }}
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($household->trashed())
                <form method="POST" action="{{ route('utilities.trash.households.restore', $household->id, false) }}" data-confirm="Khôi phục phiếu điều tra này?">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-sm btn-outline-success" type="submit">
                        <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Khôi phục phiếu
                    </button>
                </form>
            @endif
            <a href="{{ route('utilities.trash.index', [], false) }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Quay lại thùng rác
            </a>
        </div>
    </div>

    @if ($household->trashed())
        <div class="alert alert-warning py-2">
            Phiếu đã xóa lúc {{ $household->deleted_at?->format('d/m/Y H:i') }}.
            Các thành viên vẫn được giữ nguyên trạng thái xóa riêng.
        </div>
    @endif

    <section class="bg-white border rounded-2 mb-3">
        <h2 class="h6 border-bottom px-3 py-2 mb-0">Thông tin phiếu và hộ gia đình</h2>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0 trash-detail-table">
                <tbody>
                    <tr>
                        <th scope="row" class="table-light">Mã phiếu</th>
                        <td>{{ $household->household_code }}</td>
                        <th scope="row" class="table-light">Năm học</th>
                        <td>{{ $household->school_year ?: '—' }}</td>
                        <th scope="row" class="table-light">Số thành viên</th>
                        <td>{{ $household->persons->count() }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="table-light">Chủ hộ</th>
                        <td>{{ trim($household->head_last_name.' '.$household->head_first_name) ?: '—' }}</td>
                        <th scope="row" class="table-light">Diện cư trú</th>
                        <td colspan="3">{{ ['THUONG_TRU' => 'Thường trú', 'TAM_TRU' => 'Tạm trú', 'KHAC' => 'Khác'][$household->residence_type] ?? $household->residence_type ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="table-light">Tỉnh/Thành phố</th>
                        <td>{{ $household->province?->name ?? '—' }}</td>
                        <th scope="row" class="table-light">Xã/Phường</th>
                        <td>{{ $household->commune?->name ?? '—' }}</td>
                        <th scope="row" class="table-light">Thôn/Bản</th>
                        <td>{{ $household->village?->name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="table-light">Địa chỉ</th>
                        <td colspan="5">{{ $household->address ?: '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <h2 class="h6 mb-0">Danh sách thành viên và thông tin học tập</h2>
        <span class="small text-muted">Mỗi năm học hiển thị trên một dòng. Vuốt ngang bảng để xem các cột còn lại.</span>
    </div>

    <div class="trash-members-table-shell bg-white border rounded-2">
        <div class="table-responsive household-table-scroll">
            <table class="table table-sm table-bordered table-hover align-middle mb-0 trash-member-table">
                <thead>
                    <tr>
                        <th scope="col" rowspan="2">Họ và tên</th>
                        <th scope="col" colspan="9">Thông tin đối tượng</th>
                        <th scope="col" colspan="17">Thông tin học tập</th>
                        <th scope="col" colspan="12">Khuyết tật và hoàn cảnh</th>
                    </tr>
                    <tr>
                        <th scope="col">Ngày sinh</th>
                        <th scope="col">Giới tính</th>
                        <th scope="col">Dân tộc</th>
                        <th scope="col">Tôn giáo</th>
                        <th scope="col">Quan hệ</th>
                        <th scope="col">Cha/mẹ</th>
                        <th scope="col">Ưu tiên</th>
                        <th scope="col">Điện thoại</th>
                        <th scope="col">Ghi chú</th>
                        <th scope="col">Năm học</th>
                        <th scope="col">Khối</th>
                        <th scope="col">Lớp</th>
                        <th scope="col">Trường</th>
                        <th scope="col">Bậc TN</th>
                        <th scope="col">Năm TN</th>
                        <th scope="col">Bổ túc</th>
                        <th scope="col">TN nghề</th>
                        <th scope="col">Năm TN nghề</th>
                        <th scope="col">Học xong lớp</th>
                        <th scope="col">Năm học xong</th>
                        <th scope="col">Bỏ học lớp</th>
                        <th scope="col">Năm bỏ học</th>
                        <th scope="col">Đang XMC</th>
                        <th scope="col">Hoàn thành XMC</th>
                        <th scope="col">Tái mù</th>
                        <th scope="col">Năng lực học tập</th>
                        <th scope="col">Vận động</th>
                        <th scope="col">Nghe/nói</th>
                        <th scope="col">Thị giác</th>
                        <th scope="col">Tâm thần</th>
                        <th scope="col">Trí tuệ</th>
                        <th scope="col">Học tập</th>
                        <th scope="col">Tự kỷ</th>
                        <th scope="col">Khác</th>
                        <th scope="col">Giấy KT</th>
                        <th scope="col">Khả năng học</th>
                        <th scope="col">Hoàn cảnh</th>
                        <th scope="col">Chi tiết hoàn cảnh</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($household->persons->sortBy(fn ($person) => $person->last_name.' '.$person->first_name) as $person)
                        @php
                            $educations = $person->educations->sortByDesc('school_year');
                            $educationRows = $educations->isNotEmpty() ? $educations : collect([null]);
                            $disability = $person->disability;
                        @endphp
                        @foreach ($educationRows as $education)
                            <tr class="{{ $person->relationship_with_head === 'Chủ hộ' ? 'trash-household-head' : '' }}">
                                <td class="trash-member-name">{{ trim($person->last_name.' '.$person->first_name) }}</td>
                                <td class="text-nowrap">{{ $person->dob?->format('d/m/Y') ?? $person->dob_str ?? '—' }}</td>
                                <td>{{ ['NAM' => 'Nam', 'NU' => 'Nữ'][$person->gender] ?? '—' }}</td>
                                <td>{{ $person->ethnicity?->name ?? $person->ethnicity ?? '—' }}</td>
                                <td>{{ $person->religion ?: '—' }}</td>
                                <td>{{ $person->relationship_with_head ?: '—' }}</td>
                                <td>{{ $person->parent_name ?: '—' }}</td>
                                <td>{{ $person->priority_type ?: '—' }}</td>
                                <td>{{ $person->phone ?: '—' }}</td>
                                <td>{{ $person->note ?: '—' }}</td>
                                <td class="text-nowrap">{{ $education?->school_year ?: '—' }}</td>
                                <td>{{ $education?->academic_block ?: '—' }}</td>
                                <td>{{ $education?->current_class ?: '—' }}</td>
                                <td>{{ $education?->school?->name ?? $education?->school_code ?? '—' }}</td>
                                <td>{{ $education?->graduation_level ?: '—' }}</td>
                                <td>{{ $education?->graduation_year ?: '—' }}</td>
                                <td>{{ $education?->is_complementary ? 'Có' : 'Không' }}</td>
                                <td>{{ $education?->vocational_grad_level ?: '—' }}</td>
                                <td>{{ $education?->vocational_grad_year ?: '—' }}</td>
                                <td>{{ $education?->finished_class ?: '—' }}</td>
                                <td>{{ $education?->finished_year ?: '—' }}</td>
                                <td>{{ $education?->dropped_class ?: '—' }}</td>
                                <td>{{ $education?->dropped_year ?: '—' }}</td>
                                <td>{{ $education?->literacy_current_class ?: '—' }}</td>
                                <td>{{ $education?->literacy_completed_class ?: '—' }}</td>
                                <td>{{ $education?->literacy_relapse_level ?: '—' }}</td>
                                <td>{{ $education?->learning_capacity ?: '—' }}</td>
                                <td>{{ $disability?->mobility_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->hearing_speech_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->visual_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->mental_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->intellectual_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->learning_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->autism ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->other_disability ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->has_disability_cert ? 'Có' : '—' }}</td>
                                <td>{{ $disability?->can_study === null ? '—' : ($disability->can_study ? 'Có' : 'Không') }}</td>
                                <td>{{ $disability?->special_circumstance ?: '—' }}</td>
                                <td>{{ $disability?->special_circumstance_detail ?: '—' }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="39" class="text-center text-muted py-4">Hộ chưa có thông tin thành viên.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
