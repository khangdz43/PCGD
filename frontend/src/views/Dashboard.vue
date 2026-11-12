<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api, apiData, apiRows, errorMessage } from '../api';
import type { ModuleKey } from '../components/Sidebar.vue';
import type { User } from '../types';

const props = defineProps<{ user: User }>();
const emit = defineEmits<{ navigate: [module: ModuleKey] }>();
type SystemOverview = {
    school_year: string;
    school_years: string[];
    age_reference_date: string;
    totals: {
        provinces: number;
        communes: number;
        villages: number;
        schools: number;
        active_users: number;
        households: number;
        persons: number;
    };
    education_summary: {
        preschool_age_5: { population: number; studying: number; completed: number };
        primary_age_6_to_14: { population: number; studying: number; completed: number };
        lower_secondary_age_11_to_18: { population: number; studying: number; completed: number };
        literacy_age_15_to_60: { population: number; studying: number; completed: number; relapse: number };
        data_quality: { school_age_missing_education: number; missing_birth_dates: number };
    };
    provinces: { code: string; name: string; households: number }[];
};
type TerritorialOverview = {
    scope_level: 'province' | 'commune';
    scope_area: string;
    school_year: string;
    school_years: string[];
    totals: {
        communes: number;
        villages: number;
        schools: number;
        active_users: number;
        households: number;
        persons: number;
    };
    education_summary: SystemOverview['education_summary'];
    units: { code: string; name: string; households: number }[];
};
type OrganizationUnit = {
    code: string;
    name: string;
    kind: 'province' | 'commune' | 'village';
    account_count?: number;
    expanded: boolean;
    loaded: boolean;
    loading: boolean;
    error: string;
    children: OrganizationUnit[];
};
type OrganizationUnitResponse = {
    code: string;
    name: string;
    account_count?: number;
};

const isCentralAdmin = computed(() =>
    props.user.role_name
        ?.split(',')
        .some(roleName => roleName.trim() === 'system-admin') ?? false,
);
const isTerritorialAdmin = computed(() =>
    !isCentralAdmin.value &&
    ['province', 'commune'].includes(props.user.scope_level ?? ''),
);
const canViewOrganizationTree = computed(() =>
    isCentralAdmin.value &&
    props.user.permissions?.includes('view-organization-accounts'),
);
const systemOverview = ref<SystemOverview | null>(null);
const territorialOverview = ref<TerritorialOverview | null>(null);
const organizationUnits = ref<OrganizationUnit[]>([]);
const organizationTreeLoading = ref(false);
const organizationTreeError = ref('');
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

async function loadTerritorialOverview(schoolYear?: string): Promise<void> {
    if (!isTerritorialAdmin.value) return;

    const requestId = ++overviewRequestId;
    overviewBusy.value = true;
    overviewError.value = '';
    try {
        const response = await api.get<unknown>('/dashboard/overview', {
            params: schoolYear ? { school_year: schoolYear } : undefined,
        });
        if (requestId !== overviewRequestId) return;
        const overview = apiData<TerritorialOverview>(response.data);
        territorialOverview.value = overview;
        selectedSchoolYear.value = overview.school_year;
    } catch (cause) {
        if (requestId === overviewRequestId)
            overviewError.value = errorMessage(cause);
    } finally {
        if (requestId === overviewRequestId) overviewBusy.value = false;
    }
}

function organizationUnit(
    unit: OrganizationUnitResponse,
    kind: OrganizationUnit['kind'],
): OrganizationUnit {
    return {
        ...unit,
        kind,
        expanded: false,
        loaded: false,
        loading: false,
        error: '',
        children: [],
    };
}

async function loadOrganizationUnits(): Promise<void> {
    if (!canViewOrganizationTree.value || organizationUnits.value.length) return;

    organizationTreeLoading.value = true;
    organizationTreeError.value = '';
    try {
        const { data } = await api.get<unknown>('/provinces', {
            params: { include_account_counts: 1 },
        });
        organizationUnits.value = apiRows<OrganizationUnitResponse>(
            apiData<unknown>(data),
        ).map((unit) => organizationUnit(unit, 'province'));
    } catch (cause) {
        organizationTreeError.value = errorMessage(cause);
    } finally {
        organizationTreeLoading.value = false;
    }
}

async function toggleOrganizationUnit(unit: OrganizationUnit): Promise<void> {
    if (unit.kind === 'village' || unit.loading) return;
    if (unit.loaded) {
        unit.expanded = !unit.expanded;
        return;
    }

    unit.expanded = true;
    unit.loading = true;
    unit.error = '';
    const endpoint = unit.kind === 'province'
        ? `/provinces/${encodeURIComponent(unit.code)}/communes`
        : `/communes/${encodeURIComponent(unit.code)}/villages`;
    const childKind = unit.kind === 'province' ? 'commune' : 'village';

    try {
        const { data } = await api.get<unknown>(endpoint, {
            params: { include_account_counts: 1 },
        });
        unit.children = apiRows<OrganizationUnitResponse>(
            apiData<unknown>(data),
        ).map((child) => organizationUnit(child, childKind));
        unit.loaded = true;
    } catch (cause) {
        unit.error = errorMessage(cause);
    } finally {
        unit.loading = false;
    }
}

onMounted(() => {
    if (isCentralAdmin.value) void loadOverview();
    else if (isTerritorialAdmin.value) void loadTerritorialOverview();
    if (canViewOrganizationTree.value) void loadOrganizationUnits();
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

                <section class="central-education-summary">
                    <div class="dashboard-section-heading">
                        <div>
                            <span class="section-eyebrow">PHỔ CẬP GIÁO DỤC &amp; XÓA MÙ CHỮ</span>
                            <h3>Tình hình theo nhóm tuổi</h3>
                        </div>
                        <span class="dashboard-count">
                            Tuổi tính đến {{ systemOverview.age_reference_date }}
                        </span>
                    </div>
                    <div class="central-cohort-grid">
                        <article class="central-cohort-card">
                            <span class="central-cohort-age">5 tuổi</span>
                            <h4>Mầm non</h4>
                            <strong>{{ systemOverview.education_summary.preschool_age_5.population.toLocaleString('vi-VN') }}</strong>
                            <small>Người có ngày sinh trong nhóm tuổi</small>
                            <div><span>Đang học mầm non</span><b>{{ systemOverview.education_summary.preschool_age_5.studying.toLocaleString('vi-VN') }}</b></div>
                            <div><span>Ghi nhận hoàn thành</span><b>{{ systemOverview.education_summary.preschool_age_5.completed.toLocaleString('vi-VN') }}</b></div>
                        </article>
                        <article class="central-cohort-card">
                            <span class="central-cohort-age">6–14 tuổi</span>
                            <h4>Tiểu học</h4>
                            <strong>{{ systemOverview.education_summary.primary_age_6_to_14.population.toLocaleString('vi-VN') }}</strong>
                            <small>Người có ngày sinh trong nhóm tuổi</small>
                            <div><span>Đang học lớp 1–5</span><b>{{ systemOverview.education_summary.primary_age_6_to_14.studying.toLocaleString('vi-VN') }}</b></div>
                            <div><span>Ghi nhận hoàn thành</span><b>{{ systemOverview.education_summary.primary_age_6_to_14.completed.toLocaleString('vi-VN') }}</b></div>
                        </article>
                        <article class="central-cohort-card">
                            <span class="central-cohort-age">11–18 tuổi</span>
                            <h4>Trung học cơ sở</h4>
                            <strong>{{ systemOverview.education_summary.lower_secondary_age_11_to_18.population.toLocaleString('vi-VN') }}</strong>
                            <small>Người có ngày sinh trong nhóm tuổi</small>
                            <div><span>Đang học lớp 6–9</span><b>{{ systemOverview.education_summary.lower_secondary_age_11_to_18.studying.toLocaleString('vi-VN') }}</b></div>
                            <div><span>Ghi nhận tốt nghiệp</span><b>{{ systemOverview.education_summary.lower_secondary_age_11_to_18.completed.toLocaleString('vi-VN') }}</b></div>
                        </article>
                        <article class="central-cohort-card central-cohort-literacy">
                            <span class="central-cohort-age">15–60 tuổi</span>
                            <h4>Xóa mù chữ</h4>
                            <strong>{{ systemOverview.education_summary.literacy_age_15_to_60.population.toLocaleString('vi-VN') }}</strong>
                            <small>Người có ngày sinh trong nhóm tuổi</small>
                            <div><span>Ghi nhận đang học</span><b>{{ systemOverview.education_summary.literacy_age_15_to_60.studying.toLocaleString('vi-VN') }}</b></div>
                            <div><span>Có ghi nhận lớp hoàn thành</span><b>{{ systemOverview.education_summary.literacy_age_15_to_60.completed.toLocaleString('vi-VN') }}</b></div>
                            <div><span>Ghi nhận tái mù chữ</span><b>{{ systemOverview.education_summary.literacy_age_15_to_60.relapse.toLocaleString('vi-VN') }}</b></div>
                        </article>
                    </div>
                    <!-- <div class="central-data-quality" role="note">
                        <strong>Cần rà soát dữ liệu</strong>
                        <span>{{ systemOverview.education_summary.data_quality.school_age_missing_education.toLocaleString('vi-VN') }} hồ sơ 5–18 tuổi chưa có thông tin học tập</span>
                        <span>{{ systemOverview.education_summary.data_quality.missing_birth_dates.toLocaleString('vi-VN') }} hồ sơ thiếu ngày sinh</span>
                    </div> -->
                    <p class="central-data-disclaimer">
                        Số liệu đếm từ phiếu đã nhập, không phải tỷ lệ hoặc kết quả công nhận đạt chuẩn.
                    </p>
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
                        <section
                            v-if="canViewOrganizationTree"
                            class="central-organization-tree"
                            aria-labelledby="central-organization-tree-title"
                        >
                            <div class="central-organization-heading">
                                <h4 id="central-organization-tree-title">Cây đơn vị</h4>
                                <span>{{ organizationUnits.length.toLocaleString('vi-VN') }} tỉnh</span>
                            </div>
                            <p v-if="organizationTreeLoading" class="central-organization-status" role="status">
                                Đang tải danh sách đơn vị…
                            </p>
                            <div v-else-if="organizationTreeError" class="central-organization-error" role="alert">
                                {{ organizationTreeError }}
                                <button type="button" @click="loadOrganizationUnits">Thử lại</button>
                            </div>
                            <div v-else class="central-organization-list">
                                <div
                                    v-for="province in organizationUnits"
                                    :key="province.code"
                                    class="central-organization-branch"
                                >
                                    <button
                                        class="central-organization-row central-organization-province"
                                        type="button"
                                        :aria-expanded="province.expanded"
                                        @click="toggleOrganizationUnit(province)"
                                    >
                                        <span class="central-organization-chevron" :class="{ 'is-expanded': province.expanded }">›</span>
                                        <span class="central-organization-name">{{ province.name }}</span>
                                        <span class="central-organization-count">{{ province.account_count ?? 0 }}</span>
                                    </button>
                                    <div v-if="province.expanded" class="central-organization-children">
                                        <p v-if="province.loading" class="central-organization-status" role="status">Đang tải xã / phường…</p>
                                        <p v-else-if="province.error" class="central-organization-error" role="alert">
                                            {{ province.error }}
                                            <button type="button" @click="toggleOrganizationUnit(province)">Thử tải lại</button>
                                        </p>
                                        <p v-else-if="province.loaded && !province.children.length" class="central-organization-empty">
                                            Chưa có đơn vị cấp xã.
                                        </p>
                                        <div
                                            v-for="commune in province.children"
                                            :key="commune.code"
                                            class="central-organization-branch"
                                        >
                                            <button
                                                class="central-organization-row central-organization-commune"
                                                type="button"
                                                :aria-expanded="commune.expanded"
                                                @click="toggleOrganizationUnit(commune)"
                                            >
                                                <span class="central-organization-chevron" :class="{ 'is-expanded': commune.expanded }">›</span>
                                                <span class="central-organization-name">{{ commune.name }}</span>
                                                <span class="central-organization-count">{{ commune.account_count ?? 0 }}</span>
                                            </button>
                                            <div v-if="commune.expanded" class="central-organization-children">
                                                <p v-if="commune.loading" class="central-organization-status" role="status">Đang tải thôn / xóm…</p>
                                                <p v-else-if="commune.error" class="central-organization-error" role="alert">
                                                    {{ commune.error }}
                                                    <button type="button" @click="toggleOrganizationUnit(commune)">Thử tải lại</button>
                                                </p>
                                                <p v-else-if="commune.loaded && !commune.children.length" class="central-organization-empty">
                                                    Chưa có thôn / xóm.
                                                </p>
                                                <div
                                                    v-for="village in commune.children"
                                                    :key="village.code"
                                                    class="central-organization-row central-organization-village"
                                                >
                                                    <span class="central-organization-leaf" aria-hidden="true">•</span>
                                                    <span class="central-organization-name">{{ village.name }}</span>
                                                    <span class="central-organization-count">{{ village.account_count ?? 0 }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="central-organization-hint">
                                Số bên phải là tài khoản được phân công tại đơn vị.
                            </p>
                        </section>
                    </article>
                </section>

            </template>
        </template>

        <template v-else-if="isTerritorialAdmin">
            <section class="territorial-dashboard-hero">
                <div>
                    <span class="welcome-eyebrow">
                        TỔNG QUAN {{ user.scope_level === 'province' ? 'CẤP TỈNH' : 'CẤP XÃ' }}
                    </span>
                    <h2>{{ territorialOverview?.scope_area ?? user.name }}</h2>
                    <p>Theo dõi phiếu điều tra, nhân khẩu và mạng lưới giáo dục trong địa bàn được phân công.</p>
                </div>
                <div class="central-year-badge">
                    <span>NĂM HỌC</span>
                    <select
                        v-model="selectedSchoolYear"
                        aria-label="Chọn năm học tổng quan"
                        :disabled="overviewBusy || !territorialOverview"
                        @change="loadTerritorialOverview(selectedSchoolYear)"
                    >
                        <option
                            v-for="schoolYear in territorialOverview?.school_years ?? []"
                            :key="schoolYear"
                            :value="schoolYear"
                        >
                            {{ schoolYear }}
                        </option>
                    </select>
                </div>
            </section>

            <p v-if="overviewError" class="notice" role="alert">{{ overviewError }}</p>
            <div v-if="overviewBusy" class="central-overview-loading" role="status">Đang tải số liệu địa bàn…</div>
            <template v-else-if="territorialOverview">
                <section class="central-metric-grid territorial-metric-grid" aria-label="Số liệu trong địa bàn">
                    <article v-if="territorialOverview.scope_level === 'province'" class="central-metric-card metric-geography">
                        <span class="central-metric-label">Xã / phường</span>
                        <strong>{{ territorialOverview.totals.communes.toLocaleString('vi-VN') }}</strong>
                        <small>Trong tỉnh / thành phố</small>
                    </article>
                    <article class="central-metric-card metric-communes">
                        <span class="central-metric-label">Thôn / xóm</span>
                        <strong>{{ territorialOverview.totals.villages.toLocaleString('vi-VN') }}</strong>
                        <small>Địa bàn đang quản lý</small>
                    </article>
                    <article class="central-metric-card metric-schools">
                        <span class="central-metric-label">Trường học</span>
                        <strong>{{ territorialOverview.totals.schools.toLocaleString('vi-VN') }}</strong>
                        <small>Danh mục trong địa bàn</small>
                    </article>
                    <article class="central-metric-card metric-users">
                        <span class="central-metric-label">Tài khoản hoạt động</span>
                        <strong>{{ territorialOverview.totals.active_users.toLocaleString('vi-VN') }}</strong>
                        <small>Được phân công trong địa bàn</small>
                    </article>
                    <article class="central-metric-card metric-households">
                        <span class="central-metric-label">Phiếu điều tra</span>
                        <strong>{{ territorialOverview.totals.households.toLocaleString('vi-VN') }}</strong>
                        <small>Năm học {{ territorialOverview.school_year }}</small>
                    </article>
                    <article class="central-metric-card metric-people">
                        <span class="central-metric-label">Nhân khẩu</span>
                        <strong>{{ territorialOverview.totals.persons.toLocaleString('vi-VN') }}</strong>
                        <small>Trong năm học {{ territorialOverview.school_year }}</small>
                    </article>
                </section>

                <section class="territorial-dashboard-lower">
                    <article class="central-ranking-card">
                        <div class="dashboard-section-heading">
                            <div>
                                <span class="section-eyebrow">NĂM HỌC {{ territorialOverview.school_year }}</span>
                                <h3>{{ territorialOverview.scope_level === 'province' ? 'Phiếu điều tra theo xã / phường' : 'Phiếu điều tra theo thôn / xóm' }}</h3>
                            </div>
                            <span class="dashboard-count">Tối đa 10 đơn vị</span>
                        </div>
                        <div v-if="territorialOverview.units.length" class="central-ranking-list">
                            <div
                                v-for="(unit, index) in territorialOverview.units"
                                :key="unit.code"
                                class="central-ranking-row"
                            >
                                <span class="central-ranking-index">{{ index + 1 }}</span>
                                <span class="central-ranking-name">{{ unit.name }}</span>
                                <strong>{{ unit.households.toLocaleString('vi-VN') }}</strong>
                            </div>
                        </div>
                        <p v-else class="muted">Chưa có đơn vị hoặc phiếu điều tra trong địa bàn.</p>
                    </article>

                    <article class="central-education-summary">
                        <div class="dashboard-section-heading">
                            <div>
                                <span class="section-eyebrow">PHỔ CẬP GIÁO DỤC &amp; XÓA MÙ CHỮ</span>
                                <h3>Tình hình theo nhóm tuổi</h3>
                            </div>
                        </div>
                        <div class="territorial-cohort-list">
                            <div>
                                <span>Mầm non · 5 tuổi</span>
                                <strong>{{ territorialOverview.education_summary.preschool_age_5.population.toLocaleString('vi-VN') }}</strong>
                                <small>Đang học {{ territorialOverview.education_summary.preschool_age_5.studying.toLocaleString('vi-VN') }}</small>
                            </div>
                            <div>
                                <span>Tiểu học · 6–14 tuổi</span>
                                <strong>{{ territorialOverview.education_summary.primary_age_6_to_14.population.toLocaleString('vi-VN') }}</strong>
                                <small>Đang học {{ territorialOverview.education_summary.primary_age_6_to_14.studying.toLocaleString('vi-VN') }}</small>
                            </div>
                            <div>
                                <span>THCS · 11–18 tuổi</span>
                                <strong>{{ territorialOverview.education_summary.lower_secondary_age_11_to_18.population.toLocaleString('vi-VN') }}</strong>
                                <small>Đang học {{ territorialOverview.education_summary.lower_secondary_age_11_to_18.studying.toLocaleString('vi-VN') }}</small>
                            </div>
                            <div>
                                <span>Xóa mù chữ · 15–60 tuổi</span>
                                <strong>{{ territorialOverview.education_summary.literacy_age_15_to_60.population.toLocaleString('vi-VN') }}</strong>
                                <small>Đang học {{ territorialOverview.education_summary.literacy_age_15_to_60.studying.toLocaleString('vi-VN') }}</small>
                            </div>
                        </div>
                        <p class="central-data-disclaimer">
                            Số liệu được giới hạn theo địa bàn của tài khoản; đây là số người có ngày sinh trong nhóm tuổi, không phải tỷ lệ công nhận đạt chuẩn.
                        </p>
                    </article>
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
