<script setup lang="ts">
import { computed } from 'vue';
import type { ModuleKey } from '../components/Sidebar.vue';
import type { User } from '../types';

const props = defineProps<{ user: User }>();
const emit = defineEmits<{ navigate: [module: ModuleKey] }>();

const scopeLabels: Record<string, string> = {
    global: 'Trung ương',
    province: 'Cấp tỉnh',
    commune: 'Cấp xã',
    village: 'Cấp thôn / xóm',
};

const shortcuts = computed(() => [
    { key: 'households' as const, title: 'Phiếu điều tra', detail: 'Tra cứu và cập nhật hộ gia đình', permission: 'view-households', tone: 'blue', icon: '⌂' },
    { key: 'villages' as const, title: 'Thôn / xóm', detail: 'Quản lý địa bàn cấp xã', permission: 'manage-villages', tone: 'green', icon: '⌖' },
    { key: 'schools' as const, title: 'Trường học', detail: 'Quản lý danh mục trường', permission: 'manage-schools', tone: 'violet', icon: '▤' },
    { key: 'users' as const, title: 'Tài khoản', detail: 'Tài khoản và phân quyền', permission: 'manage-user-accounts', tone: 'amber', icon: '♙' },
].filter(item => props.user.permissions?.includes(item.permission)));
</script>

<template>
    <div class="dashboard-view">
        <section class="welcome-banner">
            <div class="welcome-copy">
                <span class="welcome-eyebrow">BẢNG ĐIỀU KHIỂN</span>
                <h2>Xin chào, {{ user.name }}</h2>
                <p>Chúc bạn một ngày làm việc hiệu quả. Các chức năng được hiển thị theo quyền tài khoản.</p>
                <button v-if="shortcuts.length" class="welcome-button" type="button" @click="emit('navigate', shortcuts[0].key)">
                    Bắt đầu công việc <span aria-hidden="true">→</span>
                </button>
            </div>
            <div class="welcome-illustration" aria-hidden="true">
                <span class="illustration-orbit"></span>
                <span class="illustration-book">▱</span>
                <span class="illustration-star star-one">✦</span>
                <span class="illustration-star star-two">✧</span>
                <span class="illustration-dot dot-one"></span>
                <span class="illustration-dot dot-two"></span>
            </div>
        </section>

        <div class="dashboard-grid">
            <section class="dashboard-main">
                <div class="dashboard-section-heading">
                    <div>
                        <span class="section-eyebrow">LỐI TẮT</span>
                        <h3>Chức năng dành cho bạn</h3>
                    </div>
                    <span class="dashboard-count">{{ shortcuts.length }} chức năng</span>
                </div>
                <div v-if="shortcuts.length" class="shortcut-grid">
                    <button
                        v-for="item in shortcuts"
                        :key="item.key"
                        class="shortcut-card"
                        type="button"
                        @click="emit('navigate', item.key)"
                    >
                        <span class="shortcut-icon" :class="`tone-${item.tone}`">{{ item.icon }}</span>
                        <span class="shortcut-card-title">{{ item.title }}</span>
                        <span class="shortcut-card-detail">{{ item.detail }}</span>
                        <span class="shortcut-arrow" aria-hidden="true">↗</span>
                    </button>
                </div>
                <div v-else class="empty-state dashboard-empty">
                    Tài khoản hiện chưa được cấp quyền nghiệp vụ. Vui lòng liên hệ quản trị viên.
                </div>

            </section>

            <aside class="dashboard-aside">
                <section class="account-summary-card">
                    <div class="summary-card-heading">
                        <span class="section-eyebrow">TÀI KHOẢN</span>
                        <span class="summary-status"><i></i> Đang hoạt động</span>
                    </div>
                    <div class="summary-profile">
                        <span class="summary-avatar">{{ user.name.slice(0, 1).toLocaleUpperCase('vi') }}</span>
                        <div><strong>{{ user.name }}</strong><span>@{{ user.username }}</span></div>
                    </div>
                    <dl class="summary-details">
                        <div><dt>Cấp quản lý</dt><dd>{{ scopeLabels[user.scope_level ?? ''] ?? 'Chưa xác định' }}</dd></div>
                        <div><dt>Mã tài khoản</dt><dd>#{{ user.id }}</dd></div>
                    </dl>
                </section>

                <section class="permission-summary-card">
                    <div class="dashboard-section-heading">
                        <div><span class="section-eyebrow">PHÂN QUYỀN</span><h3>Quyền truy cập</h3></div>
                        <span class="permission-count">{{ user.permissions?.length ?? 0 }}</span>
                    </div>
                    <p class="permission-summary-description">Các nghiệp vụ tài khoản được phép thực hiện.</p>
                    <div v-if="user.permissions?.length" class="permission-chip-list">
                        <span v-for="permission in user.permissions.slice(0, 5)" :key="permission">
                            {{ permission.replaceAll('-', ' ') }}
                        </span>
                        <span v-if="user.permissions.length > 5" class="permission-more">+{{ user.permissions.length - 5 }} quyền khác</span>
                    </div>
                    <p v-else class="muted">Chưa được cấp quyền nghiệp vụ.</p>
                </section>
            </aside>
        </div>
    </div>
</template>
