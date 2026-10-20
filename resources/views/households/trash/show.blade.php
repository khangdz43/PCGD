@extends('layouts.app')

@section('title', 'Chi tiết hộ trong thùng rác - PCGD-XMC')

@section('content')
<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h5 mb-1">Chi tiết hộ - Phiếu {{ $household->household_code }}</h1>
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

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="bg-white border rounded-2 p-3 h-100">
                <div class="small text-muted">Mã phiếu / Năm học</div>
                <div class="fw-semibold">{{ $household->household_code }} · {{ $household->school_year }}</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="bg-white border rounded-2 p-3 h-100">
                <div class="small text-muted">Địa chỉ</div>
                <div class="fw-semibold">{{ $household->address ?: '—' }}</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="bg-white border rounded-2 p-3 h-100">
                <div class="small text-muted">Diện cư trú</div>
                <div class="fw-semibold">{{ $household->residence_type ?: '—' }}</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="bg-white border rounded-2 p-3 h-100">
                <div class="small text-muted">Số thành viên</div>
                <div class="fw-semibold">{{ $household->persons->count() }}</div>
            </div>
        </div>
    </div>

    <section class="bg-white border rounded-2">
        <div class="p-3 border-bottom">
            <h2 class="h6 mb-0">Danh sách thành viên</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Họ và tên</th>
                        <th scope="col">Ngày sinh</th>
                        <th scope="col">Quan hệ với chủ hộ</th>
                        <th scope="col">Trường / Lớp</th>
                        <th scope="col">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($household->persons->sortBy(fn ($person) => $person->last_name.' '.$person->first_name) as $person)
                        @php($education = $person->educations->sortByDesc('school_year')->first())
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ trim($person->last_name.' '.$person->first_name) }}</div>
                                <div class="small text-muted">{{ $person->gender }}{{ $person->ethnicity ? ' · '.$person->ethnicity : '' }}</div>
                            </td>
                            <td>{{ $person->dob?->format('d/m/Y') ?? $person->dob_str ?? '—' }}</td>
                            <td>{{ $person->relationship_with_head ?: '—' }}</td>
                            <td>
                                {{ $education?->school?->name ?? $education?->school_code ?? '—' }}
                                @if ($education?->current_class)
                                    <div class="small text-muted">{{ $education->current_class }} · {{ $education->school_year }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($person->trashed())
                                    <span class="badge text-bg-danger">Thành viên đã xóa</span>
                                @else
                                    <span class="badge text-bg-success">Còn trong hộ</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Hộ chưa có thông tin thành viên.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
