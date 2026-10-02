@extends('layouts.app')

@section('title', 'Hộ ' . $household->household_code . ' - PCGD-XMC')

@section('content')
<div class="container">
    @if (session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
    <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
    @endif

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <a class="text-decoration-none" href="{{ route('households.index', [], false) }}">&larr; Danh sách phiếu</a>
            <h1 class="h4 mt-2 mb-1">Hộ {{ $household->household_code }}</h1>
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">{{ $headPerson ? trim($headPerson->last_name . ' ' . $headPerson->first_name) : trim($household->head_last_name . ' ' . $household->head_first_name) }}</h2>
                <span class="badge bg-primary">Chủ hộ</span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary" href="{{ route('households.edit', $household, false) }}">
                <i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Sửa hộ
            </a>
            @if ($headPerson)
            <a class="btn btn-primary" href="{{ route('households.members.create', $household, false) }}">
                <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i> Thêm thành viên
            </a>
            @else
            <a class="btn btn-primary" href="{{ route('households.members.create', [$household, 'is_head' => 1], false) }}">
                <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i> Nhập thông tin chủ hộ
            </a>
            @endif
        </div>
    </div>

    @unless ($headPerson)
    <div class="alert alert-info" role="status">Cần lưu hồ sơ chủ hộ vào danh sách nhân khẩu trước khi thêm thành viên khác.</div>
    @endunless

    <section class="bg-white border rounded-2 mb-3">
        <h2 class="h6 border-bottom px-3 py-2 mb-0">Thông tin hộ</h2>
        <dl class="row mb-0 px-3 py-2">
            <dt class="col-sm-3 py-1">Chủ hộ</dt>
            <dd class="col-sm-9 py-1 mb-0">{{ $headPerson ? trim($headPerson->last_name . ' ' . $headPerson->first_name) : trim($household->head_last_name . ' ' . $household->head_first_name) }} <span class="badge bg-primary-subtle text-primary">Chủ hộ</span></dd>
            <dt class="col-sm-3 py-1">Địa bàn</dt>
            <dd class="col-sm-9 py-1 mb-0">{{ $household->province->name ?? '—' }} / {{ $household->commune->name ?? '—' }} / {{ $household->village->name ?? '—' }}</dd>
            <dt class="col-sm-3 py-1">Địa chỉ</dt>
            <dd class="col-sm-9 py-1 mb-0">{{ $household->address ?: '—' }}</dd>
            <dt class="col-sm-3 py-1">Cư trú</dt>
            <dd class="col-sm-9 py-1 mb-0">{{ ['THUONG_TRU' => 'Thường trú', 'TAM_TRU' => 'Tạm trú', 'KHAC' => 'Khác'][$household->residence_type] ?? '—' }}{{ $household->residence_status ? ' - ' . $household->residence_status : '' }}</dd>
        </dl>
    </section>

    <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
        <h2 class="h6 mb-0">Nhân khẩu trong hộ <span class="text-muted">({{ $persons->count() }})</span></h2>
    </div>
    <div class="table-responsive bg-white border rounded-2">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Họ và tên</th>
                    <th scope="col">Quan hệ với chủ hộ</th>
                    <th scope="col">Ngày sinh</th>
                    <th scope="col">Giới tính</th>
                    <th scope="col">Dân tộc</th>
                    <th scope="col" class="text-end">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($persons as $person)
                <tr class="person-row" data-url="{{ route('households.members.show', [$household, $person->id], false) }}" style="cursor: pointer;">
                    <td class="fw-medium">{{ trim($person->last_name . ' ' . $person->first_name) }}</td>
                    <td>
                        @if ($person->relationship_with_head === 'Chủ hộ')
                        <span class="badge bg-primary">Chủ hộ</span>
                        @else
                        {{ $person->relationship_with_head ?: '—' }}
                        @endif
                    </td>
                    <td>{{ $person->dob?->format('d/m/Y') ?? $person->dob_str ?? '—' }}</td>
                    <td>{{ ['NAM' => 'Nam', 'NU' => 'Nữ'][$person->gender] ?? '—' }}</td>
                    <td>{{ $person->ethnicity->name ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('households.members.edit', [$household, $person->id], false) }}" title="Sửa thành viên" aria-label="Sửa {{ $person->first_name }}">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                        </a>
                        <form class="d-inline" method="POST" action="{{ route('households.members.destroy', [$household, $person->id], false) }}" onsubmit="return confirm('Bạn có chắc muốn xóa thành viên này khỏi hộ?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa thành viên" aria-label="Xóa {{ $person->first_name }}">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-5">Chưa có nhân khẩu trong hộ.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script>
    document.querySelectorAll('.person-row').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button, form, input, select, textarea')) {
                return;
            }

            window.location.href = row.dataset.url;
        });
    });
</script>
@endpush
@endsection