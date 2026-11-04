<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api, apiData, errorMessage } from '../api';
import type { ModuleKey } from '../components/Sidebar.vue';
import type { User } from '../types';

const props = defineProps<{ user: User }>();
const emit = defineEmits<{ navigate: [module: ModuleKey] }>();
type SystemOverview = {
    school_year: string;
    school_years: string[];
    totals: {
        provinces: number;
        communes: number;
        villages: number;
        schools: number;
        active_users: number;
        households: number;
        persons: number;
    };
    provinces: { code: string; name: string; households: number }[];
};

const isCentralAdmin = computed(() => props.user.role_name === 'system-admin');
const systemOverview = ref<SystemOverview | null>(null);
const overviewError = ref('');
const overviewBusy = ref(false);
const selectedSchoolYear = ref('');
let overviewRequestId = 0;

const scopeLabels: Record<string, string> = {
    global: 'Trung ương',
    province: 'Cấp tỉnh',
    commune: 'Cấp xã',
};

const shortcuts = computed(() => [
    { key: 'households' as const, title: 'Phiếu điều tra', detail: 'Tra cứu và cập nhật hộ gia đình', permission: 'view-households', tone: 'blue', icon: '⌂' },
    { key: 'villages' as const, title: 'Thôn / xóm', detail: 'Danh mục địa bàn cấp xã', permission: 'view-villages', tone: 'green', icon: '⌖' },
    { key: 'schools' as const, title: 'Trường học', detail: 'Danh mục trường học', permission: 'view-schools', tone: 'violet', icon: '▤' },
    { key: 'users' as const, title: 'Tài khoản', detail: 'Tài khoản và phân quyền', permission: 'view-users', tone: 'amber', icon: '♙' },
].filter(item => props.user.permissions?.includes(item.permission)));

const leadingProvinceHouseholdCount = computed(() => Math.max(
    1,
    ...((systemOverview.value?.provinces ?? []).map(province => province.households)),
));

async function loadOverview(schoolYear?: string): Promise<void> {
    if (!isCentralAdmin.value) return;

    const requestId = ++overviewRequestId;
    overviewBusy.value = true;
    overviewError.value = '';
    try {
        const response = await api.get<unknown>('/dashboard/overview', {
            params: schoolYear ? { school_year: schoolYear } : undefined,
        });
        if (requestId !== overviewRequestId) return;
        const overview = apiData<SystemOverview>(response.data);
        systemOverview.value = overview;
        selectedSchoolYear.value = overview.school_year;
    } catch (cause) {
        if (requestId === overviewRequestId)
            overviewError.value = errorMessage(cause);
    } finally {
        if (requestId === overviewRequestId) overviewBusy.value = false;
    }
}

onMounted(() => {
    if (isCentralAdmin.value) void loadOverview();
});
</script>

<template>
    <div class="dashboard-view">
        <template v-if="isCentralAdmin">
            <section class="central-dashboard-hero">
                <div>
                    <span class="welcome-eyebrow">TỔNG QUAN TOÀN QUỐC</span>
                    <h2>Xin chào, {{ user.name }}</h2>
                    <p>Theo dõi tình hình phổ cập giáo dục và xóa mù chữ trên toàn hệ thống.</p>
                </div>
                <div class="central-year-badge">
                    <span>NĂM HỌC</span>
                    <select
                        v-model="selectedSchoolYear"
                        aria-label="Chọn năm học tổng quan"
                        :disabled="overviewBusy || !systemOverview"
                        @change="loadOverview(selectedSchoolYear)"
                    >
                        <option
                            v-for="schoolYear in systemOverview?.school_years ?? []"
                            :key="schoolYear"
                            :value="schoolYear"
                        >
                            {{ schoolYear }}
                        </option>
                    </select>
                </div>
            </section>

            <p v-if="overviewError" class="notice" role="alert">{{ overviewError }}</p>
            <div v-if="overviewBusy" class="central-overview-loading" role="status">Đang tải số liệu toàn quốc…</div>
            <template v-else-if="systemOverview">
                <section class="central-metric-grid" aria-label="Số liệu tổng quan toàn quốc">
                    <article class="central-metric-card metric-geography">
                        <span class="central-metric-label">Tỉnh / thành phố</span>
                        <strong>{{ systemOverview.totals.provinces.toLocaleString('vi-VN') }}</strong>
                        <small>Đơn vị cấp tỉnh</small>
                    </article>
                    <article class="central-metric-card metric-communes">
                        <span class="central-metric-label">Xã / phường</span>
                        <strong>{{ systemOverview.totals.communes.toLocaleString('vi-VN') }}</strong>
                        <small>Đơn vị cấp xã trên toàn quốc</small>
                    </article>
                    <article class="central-metric-card metric-households">
                        <span class="central-metric-label">Phiếu điều tra</span>
                        <strong>{{ systemOverview.totals.households.toLocaleString('vi-VN') }}</strong>
                        <small>Năm học {{ systemOverview.school_year }}</small>
                    </article>
                    <article class="central-metric-card metric-people">
                        <span class="central-metric-label">Nhân khẩu</span>
                        <strong>{{ systemOverview.totals.persons.toLocaleString('vi-VN') }}</strong>
                        <small>Trong năm học {{ systemOverview.school_year }}</small>
                    </article>
                    <article class="central-metric-card metric-schools">
                        <span class="central-metric-label">Trường học</span>
                        <strong>{{ systemOverview.totals.schools.toLocaleString('vi-VN') }}</strong>
                        <small>Danh mục trường toàn quốc</small>
                    </article>
                    <article class="central-metric-card metric-users">
                        <span class="central-metric-label">Tài khoản hoạt động</span>
                        <strong>{{ systemOverview.totals.active_users.toLocaleString('vi-VN') }}</strong>
                        <small>Đang được phép đăng nhập</small>
                    </article>
                </section>

                <section class="central-dashboard-lower">
                    <article class="central-ranking-card">
                        <div class="dashboard-section-heading">
                            <div>
                                <span class="section-eyebrow">NĂM HỌC {{ systemOverview.school_year }}</span>
                                <h3>Tỉnh có nhiều phiếu điều tra</h3>
                            </div>
                            <span class="dashboard-count">Tối đa 10 địa phương</span>
                        </div>
                        <div v-if="systemOverview.provinces.length" class="central-ranking-list">
                            <div v-for="(province, index) in systemOverview.provinces" :key="province.code" class="central-ranking-row">
                                <span class="central-ranking-index">{{ index + 1 }}</span>
                                <span class="central-ranking-name">{{ province.name }}</span>
                                <span class="central-ranking-bar" aria-hidden="true">
                                    <i :style="{ width: `${province.households / leadingProvinceHouseholdCount * 100}%` }"></i>
                                </span>
                                <strong>{{ province.households.toLocaleString('vi-VN') }}</strong>
                            </div>
                        </div>
                        <p v-else class="muted">Chưa có phiếu điều tra trong năm học này.</p>
                    </article>

                    <article class="central-catalog-card">
                        <span class="section-eyebrow">MẠNG LƯỚI GIÁO DỤC</span>
                        <h3>Quy mô địa bàn</h3>
                        <div><span>Thôn / xóm</span><strong>{{ systemOverview.totals.villages.toLocaleString('vi-VN') }}</strong></div>
                        <div><span>Trường học</span><strong>{{ systemOverview.totals.schools.toLocaleString('vi-VN') }}</strong></div>
                        <div><span>Đơn vị cấp xã</span><strong>{{ systemOverview.totals.communes.toLocaleString('vi-VN') }}</strong></div>
                    </article>
                </section>

                <section class="central-shortcuts">
                    <div class="dashboard-section-heading">
                        <div><span class="section-eyebrow">ĐIỀU HÀNH</span><h3>Truy cập nhanh</h3></div>
                    </div>
                    <div class="shortcut-grid">
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
                </section>
            </template>
        </template>

        <template v-else>
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
        </template>
    </div>
</template>
