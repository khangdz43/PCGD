@extends('layouts.app')

@section('title', 'Hồ sơ ' . $person->last_name . ' ' . $person->first_name . ' - PCGD-XMC')

@section('content')
<div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <a class="small text-decoration-none" href="{{ route('households.show', $household, false) }}">&larr; Hộ {{ $household->household_code }}</a>
            <div class="d-flex align-items-center gap-2 mt-2">
                <h1 class="h4 mb-0">{{ trim($person->last_name . ' ' . $person->first_name) }}</h1>
                @if ($person->relationship_with_head === 'Chủ hộ')
                <span class="badge bg-primary">Chủ hộ</span>
                @endif
            </div>
            <p class="small text-muted mb-0">{{ $person->relationship_with_head ?: 'Thành viên hộ' }} · Hộ {{ $household->household_code }}</p>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('households.members.edit', [$household, $person->id], false) }}">
            <i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Sửa hồ sơ
        </a>
    </div>

    <div class="row g-3">
        <div class="col-xl-5">
            <section class="bg-white border rounded-2 h-100">
                <h2 class="h6 border-bottom px-3 py-2 mb-0">Thông tin đối tượng</h2>
                <dl class="row small mb-0 p-3">
                    <dt class="col-sm-5 py-1">Họ và tên</dt>
                    <dd class="col-sm-7 py-1 mb-0 fw-semibold">{{ trim($person->last_name . ' ' . $person->first_name) }}</dd>
                    <dt class="col-sm-5 py-1">Quan hệ với chủ hộ</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->relationship_with_head ?: '—' }}</dd>
                    <dt class="col-sm-5 py-1">Ngày sinh</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->dob?->format('d/m/Y') ?? $person->dob_str ?? '—' }}</dd>
                    <dt class="col-sm-5 py-1">Giới tính</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ ['NAM' => 'Nam', 'NU' => 'Nữ'][$person->gender] ?? '—' }}</dd>
                    <dt class="col-sm-5 py-1">Dân tộc</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->ethnicity->name ?? '—' }}</dd>
                    <dt class="col-sm-5 py-1">Tôn giáo</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->religion ?: '—' }}</dd>
                    <dt class="col-sm-5 py-1">Họ tên cha/mẹ</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->parent_name ?: '—' }}</dd>
                    <dt class="col-sm-5 py-1">Diện ưu tiên</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->priority_type ?: '—' }}</dd>
                    <dt class="col-sm-5 py-1">Điện thoại</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->phone ?: '—' }}</dd>
                    <dt class="col-sm-5 py-1">Ghi chú</dt>
                    <dd class="col-sm-7 py-1 mb-0">{{ $person->note ?: '—' }}</dd>
                </dl>
            </section>
        </div>

        <div class="col-xl-7">
            <section class="bg-white border rounded-2 mb-3">
                <h2 class="h6 border-bottom px-3 py-2 mb-0">Thông tin hộ</h2>
                <dl class="row small mb-0 p-3">
                    <dt class="col-sm-4 py-1">Địa bàn</dt>
                    <dd class="col-sm-8 py-1 mb-0">{{ $household->province->name ?? '—' }} / {{ $household->commune->name ?? '—' }} / {{ $household->village->name ?? '—' }}</dd>
                    <dt class="col-sm-4 py-1">Địa chỉ</dt>
                    <dd class="col-sm-8 py-1 mb-0">{{ $household->address ?: '—' }}</dd>
                </dl>
            </section>

            <section class="bg-white border rounded-2">
                <h2 class="h6 border-bottom px-3 py-2 mb-0">Khuyết tật và hoàn cảnh</h2>
                @if ($person->disability)
                @php
                $disabilityLabels = [
                'mobility_disability' => 'Vận động',
                'hearing_speech_disability' => 'Nghe/nói',
                'visual_disability' => 'Thị giác',
                'mental_disability' => 'Tâm thần',
                'intellectual_disability' => 'Trí tuệ',
                'learning_disability' => 'Học tập',
                'autism' => 'Tự kỷ',
                'other_disability' => 'Khuyết tật khác',
                ];
                $selectedDisabilities = collect($disabilityLabels)
                ->filter(fn ($label, $field) => $person->disability->{$field})
                ->values();
                @endphp
                <dl class="row small mb-0 p-3">
                    <dt class="col-sm-4 py-1">Dạng khuyết tật</dt>
                    <dd class="col-sm-8 py-1 mb-0">{{ $selectedDisabilities->isNotEmpty() ? $selectedDisabilities->join(', ') : 'Không ghi nhận' }}</dd>
                    <dt class="col-sm-4 py-1">Giấy xác nhận</dt>
                    <dd class="col-sm-8 py-1 mb-0">{{ $person->disability->has_disability_cert ? 'Có' : 'Không' }}</dd>
                    <dt class="col-sm-4 py-1">Khả năng học tập</dt>
                    <dd class="col-sm-8 py-1 mb-0">{{ $person->disability->can_study === null ? '—' : ($person->disability->can_study ? 'Có' : 'Không') }}</dd>
                    <dt class="col-sm-4 py-1">Hoàn cảnh</dt>
                    <dd class="col-sm-8 py-1 mb-0">{{ $person->disability->special_circumstance ?: '—' }}</dd>
                    <dt class="col-sm-4 py-1">Chi tiết</dt>
                    <dd class="col-sm-8 py-1 mb-0">{{ $person->disability->special_circumstance_detail ?: '—' }}</dd>
                </dl>
                @else
                <p class="small text-muted p-3 mb-0">Chưa có thông tin khuyết tật hoặc hoàn cảnh.</p>
                @endif
            </section>
        </div>

        <div class="col-12">
            <section class="bg-white border rounded-2">

                @forelse ($person->educations->sortByDesc('school_year') as $education)
                <div class="p-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <h3 class="h6 mb-2">Năm học {{ $education->school_year }}</h3>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle small mb-0">
                            <tbody>
                                <tr>
                                    <th class="table-light" scope="row">Lớp / Khối</th>
                                    <td>{{ $education->current_class ?: '—' }} / {{ $education->academic_block ?: '—' }}</td>
                                    <th class="table-light" scope="row">Trường</th>
                                    <td>{{ $education->school->name ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th class="table-light" scope="row">Bổ túc</th>
                                    <td>{{ $education->is_complementary ? 'Có' : 'Không' }}</td>
                                    <th class="table-light" scope="row">Năm học</th>
                                    <td>{{ $education->school_year }}</td>
                                    <th class="table-light" scope="row">Bậc tốt nghiệp</th>
                                    <td>{{ $education->graduation_level ?: '—' }}</td>
                                </tr>
                                <tr>
                                    <th class="table-light" scope="row">Năm tốt nghiệp</th>
                                    <td>{{ $education->graduation_year ?: '—' }}</td>
                                    <th class="table-light" scope="row">Tốt nghiệp nghề</th>
                                    <td colspan="3">{{ $education->vocational_grad_level ?: '—' }} / {{ $education->vocational_grad_year ?: '—' }}</td>
                                </tr>
                                <tr>
                                    <th class="table-light" scope="row">Học xong</th>
                                    <td>{{ $education->finished_class ?: '—' }} / {{ $education->finished_year ?: '—' }}</td>
                                    <th class="table-light" scope="row">Bỏ học</th>
                                    <td>{{ $education->dropped_class ?: '—' }} / {{ $education->dropped_year ?: '—' }}</td>
                                    <th class="table-light" scope="row">Năng lực học tập</th>
                                    <td>{{ $education->learning_capacity ?: '—' }}</td>
                                </tr>
                                <tr>
                                    <th class="table-light" scope="row">Xóa mù chữ</th>
                                    <td>{{ $education->literacy_current_class ?: '—' }}</td>
                                    <th class="table-light" scope="row">Đã hoàn thành</th>
                                    <td>{{ $education->literacy_completed_class ?: '—' }}</td>
                                    <th class="table-light" scope="row">Tái mù chữ</th>
                                    <td>{{ $education->literacy_relapse_level ? 'Mức ' . $education->literacy_relapse_level : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                @empty
                <p class="small text-muted p-3 mb-0">Chưa có dữ liệu học tập.</p>
                @endforelse
            </section>
        </div>
    </div>
</div>
@endsection