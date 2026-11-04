<script setup lang="ts">
defineProps<{ permissions: string[] }>();
const emit = defineEmits<{ close: [] }>();

const exportUrl = '/api/v1/household-exports';
</script>

<template>
    <section class="workspace export-report-view">
        <header class="section-heading">
            <div class="workspace-title">
                <span class="workspace-title-mark">↓</span>
                <div>
                    <p class="workspace-eyebrow">NGHIỆP VỤ · XUẤT DỮ LIỆU</p>
                    <h2>Xuất Excel</h2>
                    <p class="muted">Tải xuống tệp Excel trong phạm vi địa bàn được phân quyền.</p>
                </div>
            </div>
            <button class="secondary-button" type="button" @click="emit('close')">Quay lại</button>
        </header>
        <section class="export-excel-grid">
            <div class="export-excel-card">
                <div class="export-excel-preview" aria-hidden="true">
                    <div class="export-sheet-bar">A&nbsp;&nbsp;&nbsp; B&nbsp;&nbsp;&nbsp; C&nbsp;&nbsp;&nbsp; D</div>
                    <div class="export-sheet-row"><span>Mã hộ</span><span>Chủ hộ</span><span>Địa bàn</span><span>Số nhân khẩu</span></div>
                    <div class="export-sheet-row"><span>……</span><span>……</span><span>……</span><span>……</span></div>
                    <div class="export-sheet-row"><span>……</span><span>……</span><span>……</span><span>……</span></div>
                </div>
                <div class="export-excel-content">
                    <span class="export-excel-type">MICROSOFT EXCEL</span>
                    <h3>Dữ liệu phiếu điều tra</h3>
                    <p>Xuất danh sách hộ gia đình, nhân khẩu và thông tin địa bàn.</p>
                    <ul>
                        <li>Đúng phạm vi địa bàn được phân quyền</li>
                        <li>Dữ liệu mới nhất trong hệ thống</li>
                        <li>Tệp định dạng .xlsx</li>
                    </ul>
                    <a v-if="permissions.includes('create-exports')" class="primary-button export-download-button" :href="exportUrl">
                        <span aria-hidden="true">↓</span> Tải tệp Excel
                    </a>
                    <p v-else class="notice">Tài khoản chưa được cấp quyền xuất dữ liệu.</p>
                </div>
            </div>
        </section>
    </section>
</template>
