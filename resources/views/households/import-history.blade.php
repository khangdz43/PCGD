@extends('layouts.app')

@section('title', 'Lịch sử tải lên - PCGD-XMC')

@section('content')
<div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Lịch sử tải lên</h1>
            <p class="text-muted mb-0">Theo dõi tiến độ và kết quả xử lý các file Excel bạn đã tải lên.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('households.import.create', [], false) }}">
            <i class="fa-solid fa-file-import me-1" aria-hidden="true"></i> Tải file mới
        </a>
    </div>

    <section class="bg-white border rounded-2 p-3 p-md-4">
        @if ($imports->isEmpty())
            <div class="text-center py-5">
                <i class="fa-solid fa-clock-rotate-left fa-2x text-muted mb-3" aria-hidden="true"></i>
                <p class="mb-3">Bạn chưa có lịch sử tải file nào.</p>
                <a class="btn btn-outline-primary" href="{{ route('households.import.create', [], false) }}">Tải file Excel</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">STT</th>
                            <th scope="col">Tên file</th>
                            <th scope="col">Thời gian tải lên</th>
                            <th scope="col">Năm học</th>
                            <th scope="col">Trạng thái xử lý</th>
                            <th scope="col">Kết quả</th>
                            <th scope="col">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody
                        data-import-statuses
                        data-statuses-url="{{ route('households.import.statuses', [], false) }}"
                    >
                        @foreach ($imports as $import)
                            @php
                                $isProcessing = in_array($import->status, ['PENDING', 'PROCESSING'], true);
                                $progress = $import->status === 'SUCCESS'
                                    ? 100
                                    : ($import->total_rows > 0
                                        ? min(90, (int) floor($import->processed_rows * 90 / $import->total_rows))
                                        : 0);
                                $statusLabel = match ($import->status) {
                                    'PENDING' => 'Đang chờ '.($queuePositions[$import->id] ?? '—').'/'.$queueTotal,
                                    'PROCESSING' => 'Đang xử lý',
                                    'SUCCESS' => 'Xử lý thành công',
                                    'FAILED' => 'Xử lý thất bại',
                                    'ROLLED_BACK' => 'Đã xóa phiếu nhập',
                                    default => 'Không xác định',
                                };
                                $statusClass = match ($import->status) {
                                    'PENDING' => 'text-bg-secondary',
                                    'PROCESSING' => 'text-bg-primary',
                                    'SUCCESS' => 'text-bg-success',
                                    'FAILED' => 'text-bg-danger',
                                    'ROLLED_BACK' => 'text-bg-secondary',
                                    default => 'text-bg-light',
                                };
                            @endphp
                            <tr
                                data-import-row
                                data-import-id="{{ $import->id }}"
                                data-status="{{ $import->status }}"
                                @if (request('import_id') == $import->id) class="table-primary" @endif
                            >
                                <td>{{ $imports->firstItem() + $loop->index }}</td>
                                <td class="text-break">{{ $import->file_name }}</td>
                                <td class="text-nowrap">{{ $import->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td>{{ $import->context['school_year'] ?? '—' }}</td>
                                <td>
                                    <span data-status-label class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                                    <div class="progress mt-2" style="min-width: 120px; height: .5rem" role="progressbar" aria-label="Tiến độ nhập dữ liệu" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}">
                                        <div data-progress-bar class="progress-bar {{ $isProcessing ? 'progress-bar-striped progress-bar-animated' : '' }}" style="width: {{ $progress }}%"></div>
                                    </div>
                                    <small data-progress-label class="text-muted">{{ $progress }}% · {{ $import->processed_rows }}/{{ $import->total_rows }} dòng</small>
                                </td>
                                <td>
                                    @if ($import->status === 'ROLLED_BACK')
                                        <span data-result-counts>{{ $import->context['deleted_households'] ?? 0 }} phiếu và {{ $import->context['deleted_persons'] ?? 0 }} nhân khẩu đã xóa</span>
                                    @else
                                        <span data-result-counts>{{ $import->success_rows }} thành công · {{ $import->error_rows }} lỗi</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if ($import->file_path)
                                        <a
                                            data-original-download
                                            class="btn btn-sm btn-outline-primary mb-1"
                                            href="{{ route('households.import.download', $import, false) }}"
                                            download="{{ $import->file_name }}"
                                        >
                                            <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Tải file gốc
                                        </a>
                                    @endif
                                    @if ($import->file_path)
                                        <a
                                            data-error-download
                                            class="btn btn-sm btn-outline-danger mb-1 {{ $import->status === 'FAILED' ? '' : 'd-none' }}"
                                            href="{{ route('households.import.download-errors', $import, false) }}"
                                            download="{{ pathinfo($import->file_name, PATHINFO_FILENAME) }}-loi{{ pathinfo($import->file_name, PATHINFO_EXTENSION) ? '.'.pathinfo($import->file_name, PATHINFO_EXTENSION) : '' }}"
                                        >
                                            <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Tải file lỗi
                                        </a>
                                    @endif
                                    <form class="d-inline" data-delete-import-history action="{{ route('households.import.destroy', $import, false) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            class="btn btn-sm btn-outline-danger mb-1"
                                            type="submit"
                                            @disabled($isProcessing)
                                            title="{{ $isProcessing ? 'Chờ file xử lý xong để xóa lịch sử' : 'Chỉ xóa lịch sử và file tải lên, không xóa dữ liệu đã nhập' }}"
                                        >
                                            <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Xóa lịch sử
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($imports->hasPages())
                {{ $imports->links('vendor.pagination.bootstrap-5') }}
            @endif
        @endif
    </section>
</div>

@push('scripts')
<script>
    (() => {
        if (!window.householdImportHistoryDeleteHandlerInitialized) {
            window.householdImportHistoryDeleteHandlerInitialized = true;
            document.addEventListener('submit', async (event) => {
                const form = event.target instanceof HTMLFormElement
                    ? event.target.closest('[data-delete-import-history]')
                    : null;
                if (!form) return;

                event.preventDefault();
                if (form.dataset.processing === 'true') return;
                if (!await window.appConfirm('Xóa lịch sử và các file tải lên của lần nhập này?', {
                    title: 'Xác nhận xóa lịch sử tải lên',
                    confirmText: 'Xóa lịch sử',
                })) return;

                const button = form.querySelector('button');
                form.dataset.processing = 'true';
                button.disabled = true;

                try {
                    const response = await fetch(form.action, {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                        },
                        credentials: 'same-origin',
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.message || 'Không thể xóa lịch sử tải lên.');

                    form.closest('[data-import-row]').remove();
                    window.showAppNotification('success', result.message);
                } catch (error) {
                    form.dataset.processing = 'false';
                    button.disabled = false;
                    window.showAppNotification('error', error.message || 'Không thể xóa lịch sử tải lên.');
                }
            });
        }

        const statusTable = document.querySelector('[data-import-statuses]');
        if (statusTable && statusTable.dataset.initialized !== 'true') {
            statusTable.dataset.initialized = 'true';
            let statusTimer;
            let isCheckingStatuses = false;

            const activeRows = () => Array.from(statusTable.querySelectorAll('[data-import-row]'))
                .filter((row) => ['PENDING', 'PROCESSING'].includes(row.dataset.status));

            const scheduleStatusCheck = (delay = 5000) => {
                window.clearTimeout(statusTimer);
                if (activeRows().length > 0 && document.visibilityState === 'visible') {
                    statusTimer = window.setTimeout(checkStatuses, delay);
                }
            };

            const updateRow = (row, status) => {
                const statusLabel = row.querySelector('[data-status-label]');
                const progressTrack = row.querySelector('[role="progressbar"]');
                const progressBar = row.querySelector('[data-progress-bar]');
                const progressLabel = row.querySelector('[data-progress-label]');
                const resultCounts = row.querySelector('[data-result-counts]');
                const errorDownload = row.querySelector('[data-error-download]');
                const originalDownload = row.querySelector('[data-original-download]');
                const deleteButton = row.querySelector('[data-delete-import-history] button');
                const percent = Math.max(0, Math.min(100, status.progress));
                const labels = {
                    PENDING: ['Đang chờ xử lý', 'text-bg-secondary'],
                    PROCESSING: ['Đang xử lý', 'text-bg-primary'],
                    SUCCESS: ['Xử lý thành công', 'text-bg-success'],
                    FAILED: ['Xử lý thất bại', 'text-bg-danger'],
                    ROLLED_BACK: ['Đã xóa phiếu nhập', 'text-bg-secondary'],
                };
                const [label, badgeClass] = labels[status.status] || ['Không xác định', 'text-bg-light'];

                row.dataset.status = status.status;
                progressBar.style.width = `${percent}%`;
                progressTrack.setAttribute('aria-valuenow', String(percent));
                progressLabel.textContent = `${percent}% · ${status.processed_rows}/${status.total_rows} dòng`;
                resultCounts.textContent = `${status.success_rows} thành công · ${status.error_rows} lỗi`;
                statusLabel.textContent = status.status === 'PENDING' && status.queue_position !== null
                    ? `Đang chờ ${status.queue_position}/${status.queue_total}`
                    : label;
                statusLabel.className = `badge ${badgeClass}`;
                errorDownload?.classList.toggle('d-none', !status.has_error_file);
                originalDownload?.classList.remove('d-none');
                if (deleteButton) deleteButton.disabled = ['PENDING', 'PROCESSING'].includes(status.status);
                if (!['PENDING', 'PROCESSING'].includes(status.status)) {
                    progressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
                }
            };

            async function checkStatuses() {
                if (isCheckingStatuses || document.visibilityState !== 'visible') return;
                const rows = activeRows();
                if (rows.length === 0) return;

                isCheckingStatuses = true;
                try {
                    const query = new URLSearchParams();
                    rows.forEach((row) => query.append('ids[]', row.dataset.importId));
                    const response = await fetch(`${statusTable.dataset.statusesUrl}?${query}`, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) throw new Error('Không lấy được trạng thái import.');

                    const payload = await response.json();
                    let completedImport = false;
                    rows.forEach((row) => {
                        const status = payload.statuses?.[row.dataset.importId];
                        if (!status) return;
                        updateRow(row, status);
                        completedImport ||= !['PENDING', 'PROCESSING'].includes(status.status);
                    });
                    if (completedImport) {
                        window.dispatchEvent(new Event('household-import-completed'));
                    }
                    scheduleStatusCheck();
                } catch (error) {
                    console.error(error);
                    scheduleStatusCheck(15000);
                } finally {
                    isCheckingStatuses = false;
                }
            }

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    checkStatuses();
                } else {
                    window.clearTimeout(statusTimer);
                }
            });
            checkStatuses();
        }
    })();
</script>
@endpush
@endsection
