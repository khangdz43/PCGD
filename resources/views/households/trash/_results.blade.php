<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <h2 id="trash-results-heading" class="h6 mb-0">Dữ liệu đã xóa <span class="text-muted fw-normal">({{ number_format($items->total()) }} mục)</span></h2>
    <span class="small text-muted">Chọn các mục để khôi phục hoặc xóa vĩnh viễn.</span>
</div>
<form
    id="trash-bulk-form"
    method="POST"
    action="{{ route('utilities.trash.bulk', [], false) }}"
    class="trash-bulk-toolbar household-bulk-actions d-flex flex-wrap align-items-center gap-2 mb-3"
    data-confirm="Xác nhận thao tác với các mục đã chọn? Nếu chọn xóa vĩnh viễn, dữ liệu sẽ không thể khôi phục."
    data-confirm-title="Xác nhận thao tác thùng rác"
    data-confirm-button="Tiếp tục"
>
    @csrf
    <input type="hidden" name="operation" id="trash-bulk-operation" value="">
    <span class="trash-selection-status small text-muted me-1" data-trash-selection-count>
        <i class="fa-regular fa-square-check me-1" aria-hidden="true"></i>Chưa chọn mục nào
    </span>
    <div class="trash-bulk-actions d-flex flex-wrap align-items-center gap-2">
        <button class="btn btn-sm btn-outline-success" type="submit" data-trash-operation="restore" disabled>
            <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Khôi phục đã chọn
        </button>
        <button class="btn btn-sm btn-outline-danger" type="submit" data-trash-operation="force_delete" disabled>
            <i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>Xóa vĩnh viễn đã chọn
        </button>
    </div>
</form>

<div class="table-responsive trash-table-scroll bg-white border rounded-2" tabindex="0" aria-label="Dữ liệu đã xóa, vuốt ngang để xem thêm thông tin">
    <table class="table table-sm table-hover align-middle mb-0 trash-table">
        <thead class="table-light">
            <tr>
                <th scope="col" class="text-center">
                    <input class="form-check-input trash-checkbox" type="checkbox" id="select-all-trash-items" aria-label="Chọn tất cả mục trên trang">
                </th>
                <th scope="col">Loại</th>
                <th scope="col">Thông tin</th>
                <th scope="col">Địa bàn</th>
                <th scope="col">Thời điểm xóa</th>
                <th scope="col">Người xóa</th>
                <th scope="col" class="text-end">Thao tác</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                @if ($item->record)
                    @php
                        $record = $item->record;
                        $household = $item->item_type === 'household' ? $record : $record->household;
                        $location = $household?->village?->name
                            ?? $household?->commune?->name
                            ?? '—';
                        $recordType = $item->item_type;
                    @endphp
                    <tr>
                        <td class="text-center">
                            <input
                                class="form-check-input trash-checkbox trash-item-checkbox"
                                type="checkbox"
                                form="trash-bulk-form"
                                name="{{ $recordType === 'household' ? 'household_ids[]' : 'person_ids[]' }}"
                                value="{{ $record->id }}"
                                aria-label="Chọn {{ $recordType === 'household' ? 'phiếu '.$record->household_code : trim($record->last_name.' '.$record->first_name) }}"
                            >
                        </td>
                        <td>
                            <span class="trash-type-badge {{ $item->item_type === 'household' ? 'trash-type-household' : 'trash-type-person' }}">
                                <i class="fa-solid {{ $item->item_type === 'household' ? 'fa-house' : 'fa-user' }} me-1" aria-hidden="true"></i>
                                {{ $item->item_type === 'household' ? 'Phiếu / hộ' : 'Thành viên' }}
                            </span>
                        </td>
                        <td>
                            @if ($item->item_type === 'household')
                                <div class="trash-record-title">Phiếu {{ $record->household_code }}</div>
                                <div class="trash-record-subtitle">
                                    {{ trim($record->head_last_name.' '.$record->head_first_name) ?: 'Chưa có tên chủ hộ' }}
                                    · {{ $record->persons_count }} thành viên
                                </div>
                            @else
                                <div class="trash-record-title">{{ trim($record->last_name.' '.$record->first_name) }}</div>
                                <div class="trash-record-subtitle">Phiếu {{ $household?->household_code ?? '—' }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="trash-location">
                                {{ $location }}{{ $household?->province ? ', '.$household->province->name : '' }}
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <span class="trash-date">{{ $record->deleted_at?->format('d/m/Y') }}</span>
                            <span class="trash-time">{{ $record->deleted_at?->format('H:i') }}</span>
                        </td>
                        <td>
                            <span class="trash-deleted-by">{{ $record->deletedBy?->name ?? '—' }}</span>
                        </td>
                        <td class="text-end">
                            <div class="trash-row-actions">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('utilities.trash.households.show', $household->id, false) }}" title="Xem chi tiết hộ">
                                    <i class="fa-solid fa-eye me-sm-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Xem hộ</span>
                                </a>
                                <form method="POST" action="{{ $item->item_type === 'household' ? route('utilities.trash.households.restore', $record->id, false) : route('utilities.trash.persons.restore', $record->id, false) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button class="btn btn-sm btn-outline-success" type="submit" title="Khôi phục" aria-label="Khôi phục mục này" data-confirm="Khôi phục mục này?">
                                        <i class="fa-solid fa-rotate-left me-sm-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Khôi phục</span>
                                    </button>
                                </form>
                                <form method="POST" action="{{ $item->item_type === 'household' ? route('utilities.trash.households.destroy', $record->id, false) : route('utilities.trash.persons.destroy', $record->id, false) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Xóa vĩnh viễn" aria-label="Xóa vĩnh viễn mục này" data-confirm="Xóa vĩnh viễn mục này? Thao tác này không thể hoàn tác.">
                                        <i class="fa-solid fa-trash-can me-sm-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Xóa vĩnh viễn</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="fa-regular fa-folder-open fs-3 d-block mb-2" aria-hidden="true"></i>
                        Không có dữ liệu trong thùng rác phù hợp với bộ lọc.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($items->hasPages())
    <div class="p-3 border-top">
        {{ $items->links('vendor.pagination.bootstrap-5') }}
    </div>
@endif
