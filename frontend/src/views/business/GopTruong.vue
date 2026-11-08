<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue';
import { api, apiData, apiPage, errorMessage } from '../../api';

type School = {
    id: number;
    code: string;
    name: string;
    type: string | null;
    is_active: boolean;
    level: string;
    province_code: string;
    commune_code: string | null;
};

const props = defineProps<{
    permissions: string[];
    scopeLevel: string | null;
}>();

const schools = ref<School[]>([]);
const targetSchools = ref<School[]>([]);
const selectedSourceSchools = ref<School[]>([]);
const sourceCodes = computed(() => selectedSourceSchools.value.map(school => school.code));
const targetId = ref('');
const targetSchool = ref<School | null>(null);
const decisionNumber = ref('');
const searchQuery = ref('');
const targetSearchQuery = ref('');
const loading = ref(false);
const targetLoading = ref(false);
const saving = ref(false);
const error = ref('');
const sourceNotice = ref('');
const successMessage = ref('');
const confirmMerge = ref(false);
const currentPage = ref(1);
const lastPage = ref(1);
const totalSchools = ref(0);
const targetCurrentPage = ref(1);
const targetLastPage = ref(1);
const targetTotalSchools = ref(0);
let searchTimer: number | null = null;
let targetSearchTimer: number | null = null;
let activeRequest: AbortController | null = null;
let activeTargetRequest: AbortController | null = null;
const targetHasSearched = ref(false);

const canViewMerges = computed(() =>
    props.scopeLevel !== 'global' &&
    props.permissions.includes('view-school-merges'),
);
const canCreate = computed(() =>
    canViewMerges.value &&
    props.permissions.includes('create-school-merges'),
);
const mergeCompatible = computed(() => {
    const target = targetSchool.value;
    if (!target || selectedSourceSchools.value.length === 0) return false;
    return selectedSourceSchools.value.every(school =>
        !school.is_active && isCompatible(school, target),
    );
});
const compatibilityMessage = computed(() => {
    const target = targetSchool.value;
    if (!target) return '';
    for (const source of selectedSourceSchools.value) {
        const message = getCompatibilityMessage(source, target);
        if (message) return message;
    }
    return '';
});
const targetOptions = computed(() => targetSchools.value.filter(school =>
    selectedSourceSchools.value.every(source => isCompatible(source, school))
        && !sourceCodes.value.includes(school.code),
));
function isCompatible(source: School, target: School): boolean {
    return getCompatibilityMessage(source, target) === '';
}

function getCompatibilityMessage(source: School, target: School): string {
    if (source.code === target.code) return 'Trường đích không thể đồng thời là trường nguồn.';
    if (!isSourceLevelAllowed(source, target)) {
        if (target.level === 'MN') return 'Trường Mầm non chỉ được gộp với trường Mầm non.';
        return target.type === 'multi_level'
            ? 'Trường đích liên cấp chỉ nhận trường cùng cấp hoặc cấp phổ thông thấp hơn.'
            : 'Trường đích một cấp chỉ nhận trường nguồn cùng cấp.';
    }
    if (!source.province_code?.trim() || !target.province_code?.trim()) {
        return 'Các trường phải được gán tỉnh trước khi gộp.';
    }
    if (source.province_code !== target.province_code) return 'Các trường phải thuộc cùng tỉnh.';

    const sameEducationGroup = (source.level === 'MN') === (target.level === 'MN');
    const sameCommuneRequired = source.level !== 'THPT' && target.level !== 'THPT';
    if (!sameEducationGroup) {
        return 'Trường Mầm non chỉ được gộp với trường Mầm non; các cấp phổ thông không gộp với Mầm non.';
    }
    if (
        sameCommuneRequired
        && (!source.commune_code?.trim()
            || !target.commune_code?.trim()
            || source.commune_code !== target.commune_code)
    ) {
        return 'Trường Mầm non, Tiểu học và THCS phải được gán cùng xã trước khi gộp.';
    }

    return '';
}

function isSourceLevelAllowed(source: School, target: School): boolean {
    if (target.level === 'MN') return source.level === 'MN';
    if (source.level === 'MN') return false;
    if (target.type !== 'multi_level') {
        return source.level === target.level && source.type === 'single_level';
    }

    const levelOrder: Record<string, number> = { TH: 1, THCS: 2, THPT: 3 };
    return (levelOrder[source.level] ?? 0) > 0
        && (levelOrder[source.level] ?? 0) <= (levelOrder[target.level] ?? 0);
}

function schoolLevelLabel(level: string): string {
    return ({ MN: 'Mầm non', TH: 'Tiểu học', THCS: 'THCS', THPT: 'THPT' } as Record<string, string>)[level] ?? level;
}

function schoolTypeLabel(type: string | null): string {
    return type === 'multi_level' ? 'Trường liên cấp' : 'Trường một cấp';
}

function schoolLocationLabel(school: School): string {
    const location = [
        school.commune_code ? `Xã ${school.commune_code}` : null,
        school.province_code ? `Tỉnh ${school.province_code}` : null,
    ].filter(Boolean);

    return location.length ? location.join(' · ') : 'Chưa gán địa bàn';
}

async function loadSchools(page = 1): Promise<void> {
    if (!canViewMerges.value || !targetSchool.value) return;
    activeRequest?.abort();
    const controller = new AbortController();
    activeRequest = controller;
    loading.value = true;
    error.value = '';
    schools.value = [];
    try {
        const response = await api.get<unknown>('/school-merges', {
            params: {
                q: searchQuery.value.trim() || undefined,
                target_code: targetId.value || undefined,
                page,
                per_page: 30,
            },
            signal: controller.signal,
        });
        if (controller.signal.aborted) return;

        const payload = apiData<unknown>(response.data);
        const pagination = apiPage<School>(response.data, payload);
        schools.value = pagination.data;
        currentPage.value = pagination.current_page;
        lastPage.value = pagination.last_page;
        totalSchools.value = pagination.total;

        for (const school of schools.value) {
            if (sourceCodes.value.includes(school.code)) {
                selectedSourceSchools.value = selectedSourceSchools.value.map(selected =>
                    selected.code === school.code ? school : selected,
                );
            }
        }
    } catch (cause) {
        if (!controller.signal.aborted) error.value = errorMessage(cause);
    } finally {
        if (activeRequest === controller) {
            activeRequest = null;
            loading.value = false;
        }
    }
}

async function loadTargetSchools(page = 1): Promise<void> {
    if (!canViewMerges.value) return;
    if (!targetSearchQuery.value.trim()) {
        activeTargetRequest?.abort();
        targetSchools.value = [];
        targetCurrentPage.value = 1;
        targetLastPage.value = 1;
        targetTotalSchools.value = 0;
        targetHasSearched.value = false;
        return;
    }

    activeTargetRequest?.abort();
    const controller = new AbortController();
    activeTargetRequest = controller;
    targetLoading.value = true;
    targetHasSearched.value = true;
    error.value = '';
    try {
        const response = await api.get<unknown>('/school-merges', {
            params: {
                q: targetSearchQuery.value.trim() || undefined,
                page,
                per_page: 30,
            },
            signal: controller.signal,
        });
        if (controller.signal.aborted) return;

        const payload = apiData<unknown>(response.data);
        const pagination = apiPage<School>(response.data, payload);
        targetSchools.value = pagination.data;
        targetCurrentPage.value = pagination.current_page;
        targetLastPage.value = pagination.last_page;
        targetTotalSchools.value = pagination.total;
        targetSchool.value = targetSchools.value.find(school => school.code === targetId.value)
            ?? targetSchool.value;
    } catch (cause) {
        if (!controller.signal.aborted) error.value = errorMessage(cause);
    } finally {
        if (activeTargetRequest === controller) {
            activeTargetRequest = null;
            targetLoading.value = false;
        }
    }
}

function scheduleSearch(): void {
    if (searchTimer !== null) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        searchTimer = null;
        void loadSchools(1);
    }, 300);
}

function scheduleTargetSearch(): void {
    if (targetSearchTimer !== null) window.clearTimeout(targetSearchTimer);
    if (!targetSearchQuery.value.trim()) {
        void loadTargetSchools(1);
        return;
    }

    targetSearchTimer = window.setTimeout(() => {
        targetSearchTimer = null;
        void loadTargetSchools(1);
    }, 300);
}

function toggleSource(school: School, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    selectedSourceSchools.value = checked
        ? [...selectedSourceSchools.value.filter(selected => selected.code !== school.code), school]
        : selectedSourceSchools.value.filter(selected => selected.code !== school.code);
}

function selectTarget(school: School): void {
    if (school.code === targetId.value) return;

    targetId.value = school.code;
    targetSchool.value = school;
    selectedSourceSchools.value = [];
    sourceNotice.value = '';
    searchQuery.value = '';
    schools.value = [];
    currentPage.value = 1;
    lastPage.value = 1;
    totalSchools.value = 0;
    activeRequest?.abort();

    if (targetSchool.value) {
        void loadSchools(1);
    }
}

function removeSource(schoolCode: string): void {
    selectedSourceSchools.value = selectedSourceSchools.value.filter(school => school.code !== schoolCode);
}

function openMergeConfirmation(): void {
    if (canCreate.value && mergeCompatible.value && !saving.value) {
        confirmMerge.value = true;
    }
}

async function submitMerge(): Promise<void> {
    if (!canCreate.value || !mergeCompatible.value || saving.value) return;
    saving.value = true;
    error.value = '';
    successMessage.value = '';
    try {
        const response = await api.post<unknown>('/school-merges', {
            source_truong_ids: sourceCodes.value,
            target_truong_id: targetId.value,
            qd_sap_nhap: decisionNumber.value.trim() || null,
        });
        const result = apiData<Record<string, unknown>>(response.data);
        successMessage.value = String(result.message ?? 'Đã gộp các trường thành công.');
        selectedSourceSchools.value = [];
        targetId.value = '';
        targetSchool.value = null;
        targetSearchQuery.value = '';
        targetSchools.value = [];
        targetHasSearched.value = false;
        sourceNotice.value = '';
        decisionNumber.value = '';
        confirmMerge.value = false;
        schools.value = [];
    } catch (cause) {
        error.value = errorMessage(cause);
        confirmMerge.value = false;
    } finally {
        saving.value = false;
    }
}

onUnmounted(() => {
    if (searchTimer !== null) window.clearTimeout(searchTimer);
    if (targetSearchTimer !== null) window.clearTimeout(targetSearchTimer);
    activeRequest?.abort();
    activeTargetRequest?.abort();
});
</script>

<template>
    <section class="workspace school-merge-view">
        <div v-if="error" class="school-merge-feedback is-error" role="alert">
            <span aria-hidden="true">!</span>
            <p>{{ error }}</p>
        </div>

        <div class="merge-constraint-note" role="note">
            <span class="merge-constraint-icon" aria-hidden="true">!</span>
            <div>
                <strong>Lưu ý khi gộp trường</strong>
                <p>
                    Chọn trường đích đang hoạt động; trường nguồn phải ngừng hoạt động.
                    Mầm non chỉ gộp với Mầm non. Tiểu học và THCS gộp trong cùng xã;
                    THPT gộp trong cùng tỉnh. Lớp trùng tên sẽ được đổi theo tên lớp
                    và mã trường nguồn.
                </p>
            </div>
        </div>

        <form @submit.prevent="openMergeConfirmation">
            <div class="school-merge-columns">
                <section class="merge-selection-card">
                    <div class="merge-step">
                        <span>01</span>
                        <div>
                            <h3>Trường đích</h3>
                            <p>Nơi tiếp nhận hồ sơ sau khi gộp</p>
                        </div>
                    </div>
                    <label class="workspace-field">
                        <span>Tìm trường đích theo mã hoặc tên</span>
                        <input
                            v-model="targetSearchQuery"
                            type="search"
                            placeholder="Nhập mã hoặc tên trường đích"
                            :disabled="saving"
                            @input="scheduleTargetSearch"
                            @keydown.enter.prevent
                        >
                    </label>
                    <p v-if="targetLoading" class="muted" role="status">Đang tìm trường đích…</p>
                    <div v-else-if="targetOptions.length" class="school-target-list" aria-label="Kết quả tìm trường đích">
                        <button
                            v-for="school in targetOptions"
                            :key="school.code"
                            class="school-target-option"
                            :class="{ selected: targetId === school.code }"
                            type="button"
                            :aria-pressed="targetId === school.code"
                            :disabled="saving"
                            @click="selectTarget(school)"
                        >
                            <span class="school-target-option-copy">
                                <strong>{{ school.name || 'Chưa cập nhật tên' }}</strong>
                                <small>{{ school.code }} · {{ schoolLevelLabel(school.level) }} · {{ schoolTypeLabel(school.type) }} · {{ schoolLocationLabel(school) }}</small>
                            </span>
                            <span class="school-target-option-action">{{ targetId === school.code ? 'Đã chọn' : 'Chọn' }}</span>
                        </button>
                    </div>
                    <p v-else-if="targetHasSearched" class="muted" role="status">
                        Không tìm thấy trường đích phù hợp.
                    </p>
                    <p v-else-if="!targetSchool" class="muted" role="status">
                        Nhập mã hoặc tên trường để tìm và chọn trường tiếp nhận.
                    </p>
                    <div v-if="targetLastPage > 1 && targetOptions.length" class="pagination-controls" aria-label="Phân trang kết quả trường đích">
                        <button class="secondary-button" type="button" :disabled="targetLoading || saving || targetCurrentPage <= 1" @click="loadTargetSchools(targetCurrentPage - 1)">Trước</button>
                        <span>Trang {{ targetCurrentPage }} / {{ targetLastPage }} · {{ targetTotalSchools.toLocaleString('vi-VN') }} trường</span>
                        <button class="secondary-button" type="button" :disabled="targetLoading || saving || targetCurrentPage >= targetLastPage" @click="loadTargetSchools(targetCurrentPage + 1)">Sau</button>
                    </div>
                    <div v-if="targetSchool" class="school-target-summary">
                        <strong>Trường đích: {{ targetSchool.name }}</strong>
                        <span>{{ targetSchool.code }} · {{ schoolLevelLabel(targetSchool.level) }} · {{ schoolTypeLabel(targetSchool.type) }}</span>
                        <span>{{ targetSchool.level === 'THPT' ? 'Phạm vi gộp: cùng tỉnh' : 'Phạm vi gộp: cùng xã' }}</span>
                    </div>
                    <label class="workspace-field">
                        <span>Số quyết định sáp nhập <small>(không bắt buộc)</small></span>
                        <input
                            v-model="decisionNumber"
                            type="text"
                            maxlength="100"
                            placeholder="Ví dụ: 123/QĐ-UBND"
                            :disabled="saving"
                        >
                    </label>
                    <p v-if="targetId && selectedSourceSchools.length && !mergeCompatible" class="merge-constraint-warning" role="alert">
                        {{ compatibilityMessage }}
                    </p>
                </section>

                <section class="merge-selection-card">
                    <div class="merge-step">
                        <span>02</span>
                        <div>
                            <h3>Trường nguồn <span class="required-indicator" aria-hidden="true">*</span></h3>
                            <p>Chọn các trường cần chuyển hồ sơ</p>
                        </div>
                        <span class="village-merge-selection-count" :class="{ selected: sourceCodes.length > 0 }">
                            {{ sourceCodes.length }} đã chọn
                        </span>
                    </div>
                    <template v-if="targetSchool">
                        <label class="workspace-field">
                            <span>Tìm trường nguồn phù hợp với {{ schoolLevelLabel(targetSchool.level) }}</span>
                            <input v-model="searchQuery" type="search" placeholder="Nhập mã hoặc tên trường" @input="scheduleSearch">
                        </label>
                        <p v-if="sourceNotice" class="muted" role="status">{{ sourceNotice }}</p>
                        <p v-if="loading" class="muted" role="status">Đang tải danh sách trường…</p>
                        <div v-else class="school-selection-list">
                            <label v-for="school in schools" :key="school.code" class="school-option">
                                <input
                                    type="checkbox"
                                    :checked="sourceCodes.includes(school.code)"
                                        :disabled="saving || school.is_active || school.code === targetId || !isCompatible(school, targetSchool)"
                                    @change="toggleSource(school, $event)"
                                >
                                <span>
                                    <strong>{{ school.name || 'Chưa cập nhật tên' }}</strong>
                                        <small>{{ school.code }} · {{ schoolLevelLabel(school.level) }} · {{ schoolTypeLabel(school.type) }} · {{ schoolLocationLabel(school) }} · {{ school.is_active ? 'Đang hoạt động' : 'Không hoạt động' }}</small>
                                </span>
                            </label>
                            <p v-if="!schools.length && !error" class="empty-state">Không có trường phù hợp trong phạm vi quản lý.</p>
                        </div>
                        <div v-if="selectedSourceSchools.length" class="selected-source-list" aria-label="Trường nguồn đã chọn">
                            <span v-for="school in selectedSourceSchools" :key="school.code">
                                {{ school.name }} · {{ school.code }}
                                <button type="button" :disabled="saving" :aria-label="`Bỏ chọn ${school.name}`" @click="removeSource(school.code)">×</button>
                            </span>
                        </div>
                        <div v-if="lastPage > 1" class="pagination-controls" aria-label="Phân trang trường học">
                            <button class="secondary-button" type="button" :disabled="loading || saving || currentPage <= 1" @click="loadSchools(currentPage - 1)">Trước</button>
                            <span>Trang {{ currentPage }} / {{ lastPage }} · {{ totalSchools.toLocaleString('vi-VN') }} trường</span>
                            <button class="secondary-button" type="button" :disabled="loading || saving || currentPage >= lastPage" @click="loadSchools(currentPage + 1)">Sau</button>
                        </div>
                    </template>
                    <p v-else class="muted" role="status">
                        Vui lòng chọn trường đích trước. Sau đó, danh sách chỉ hiển thị trường nguồn đã được chuyển sang trạng thái không hoạt động.
                    </p>
                </section>
            </div>

            <div class="merge-action-row">
                <span>Đã chọn {{ sourceCodes.length }} trường nguồn</span>
                <button class="primary-button" type="submit" :disabled="!canCreate || !mergeCompatible || saving">
                    {{ saving ? 'Đang gộp…' : 'Tiến hành gộp trường' }}
                </button>
            </div>
        </form>

        <div v-if="confirmMerge" class="workspace-modal-backdrop" role="presentation" @click.self="!saving && (confirmMerge = false)" @keydown.esc="!saving && (confirmMerge = false)">
            <section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="school-merge-confirm-title" aria-describedby="school-merge-confirm-message" tabindex="-1">
                <span class="confirm-dialog-icon" aria-hidden="true">!</span>
                <h3 id="school-merge-confirm-title">Xác nhận gộp trường</h3>
                <p id="school-merge-confirm-message">
                    Chuyển hồ sơ học tập từ {{ selectedSourceSchools.length }} trường nguồn đã ngừng hoạt động sang
                    {{ targetSchool?.name }}. Các trường nguồn được giữ trong lịch sử.
                    <template v-if="decisionNumber.trim()">Số quyết định: {{ decisionNumber.trim() }}.</template>
                </p>
                <div class="confirm-dialog-actions">
                    <button class="secondary-button" type="button" :disabled="saving" @click="confirmMerge = false">Hủy</button>
                    <button class="primary-button" type="button" :disabled="saving" @click="submitMerge">
                        {{ saving ? 'Đang gộp…' : 'Xác nhận gộp' }}
                    </button>
                </div>
            </section>
        </div>

        <div
            v-if="successMessage"
            class="workspace-modal-backdrop account-creation-result-backdrop"
            role="presentation"
            @click.self="successMessage = ''"
            @keydown.esc="successMessage = ''"
        >
            <section
                class="confirm-dialog account-creation-result-dialog account-creation-success-dialog"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="school-merge-result-title"
                aria-describedby="school-merge-result-message"
                tabindex="-1"
            >
                <span class="confirm-dialog-icon account-creation-result-icon account-creation-success-icon" aria-hidden="true">✓</span>
                <p class="account-creation-result-eyebrow">HOÀN TẤT</p>
                <h3 id="school-merge-result-title">Gộp trường thành công</h3>
                <p id="school-merge-result-message">{{ successMessage }}</p>
                <div class="confirm-dialog-actions">
                    <button class="primary-button management-tool-button" type="button" autofocus @click="successMessage = ''">
                        Đóng
                    </button>
                </div>
            </section>
        </div>
    </section>
</template>
