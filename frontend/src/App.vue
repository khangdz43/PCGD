<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { AxiosError } from 'axios';
import {
    errorMessage,
    getHousehold,
    getHouseholds,
    getRoleMembers,
    getRoles,
    getSession,
    login,
    logout,
} from './api';
import Workspace from './Workspace.vue';
import type { HouseholdDetail, HouseholdSummary, RoleMember, RoleSummary, User } from './types';

const user = ref<User | null>(null);
const username = ref('');
const password = ref('');
const remember = ref(false);
const busy = ref(false);
const error = ref('');
const activeModule = ref<'' | 'households' | 'schools' | 'villages' | 'roles' | 'users' | 'account' | 'imports' | 'trash' | 'merge'>('');
const households = ref<HouseholdSummary[]>([]);
const householdCount = ref(0);
const loadingHouseholds = ref(false);
const householdQuery = ref('');
const schoolYear = ref('all');
const currentPage = ref(1);
const lastPage = ref(1);
const selectedHousehold = ref<HouseholdDetail | null>(null);
const roles = ref<RoleSummary[]>([]);
const roleMembers = ref<RoleMember[]>([]);
const selectedRole = ref<RoleSummary | null>(null);
const roleMembersPage = ref(1);
const roleMembersLastPage = ref(1);
const roleMembersTotal = ref(0);
const scopeLabels: Record<string, string> = {
    global: 'Trung ương',
    province: 'Cấp tỉnh',
    commune: 'Cấp xã',
    village: 'Cấp thôn / xóm',
};
const permissionLabels: Record<string, string> = {
    'view-households': 'Xem phiếu điều tra và nhân khẩu',
    'create-households': 'Tạo phiếu điều tra và nhân khẩu',
    'update-households': 'Cập nhật phiếu điều tra và nhân khẩu',
    'delete-households': 'Xóa phiếu điều tra và nhân khẩu',
    'import-households': 'Nhập dữ liệu phiếu điều tra',
    'export-households': 'Xuất dữ liệu phiếu điều tra',
    'manage-villages': 'Quản lý danh mục thôn / xóm',
    'manage-schools': 'Quản lý danh mục trường học',
    'manage-access-control': 'Quản lý nhóm người dùng',
    'manage-user-accounts': 'Quản lý tài khoản',
    'view-organization-accounts': 'Xem cây đơn vị',
};

async function loadHouseholds(page = 1): Promise<void> {
    if (!user.value?.permissions?.includes('view-households')) return;

    loadingHouseholds.value = true;
    try {
        const result = await getHouseholds(page, {
            q: householdQuery.value,
            school_year: schoolYear.value,
            province_code: '',
            commune_code: '',
            village_code: '',
        });
        households.value = result.data;
        householdCount.value = result.total;
        currentPage.value = result.current_page;
        lastPage.value = result.last_page;
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        loadingHouseholds.value = false;
    }
}

async function openHousehold(household: HouseholdSummary): Promise<void> {
    error.value = '';
    try {
        selectedHousehold.value = await getHousehold(household.id);
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function loadRoles(): Promise<void> {
    if (!user.value?.permissions?.includes('manage-access-control')) return;
    try {
        roles.value = await getRoles();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function openRole(role: RoleSummary, page = 1): Promise<void> {
    error.value = '';
    try {
        const result = await getRoleMembers(role.id, page);
        selectedRole.value = role;
        roleMembers.value = result.data;
        roleMembersPage.value = result.current_page;
        roleMembersLastPage.value = result.last_page;
        roleMembersTotal.value = result.total;
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

onMounted(async () => {
    try {
        user.value = await getSession();
        await loadHouseholds();
        await loadRoles();
    } catch (cause) {
        if (!(cause instanceof AxiosError) || cause.response?.status !== 401) {
            error.value = errorMessage(cause);
        }
    }
});

async function submitLogin(): Promise<void> {
    busy.value = true;
    error.value = '';

    try {
        user.value = await login(username.value, password.value, remember.value);
        password.value = '';
        await loadHouseholds();
        await loadRoles();
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

async function submitLogout(): Promise<void> {
    busy.value = true;
    error.value = '';

    try {
        await logout();
        user.value = null;
        households.value = [];
        householdCount.value = 0;
        selectedHousehold.value = null;
        roles.value = [];
        roleMembers.value = [];
        selectedRole.value = null;
        activeModule.value = '';
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main class="page-shell">
        <section v-if="!user" class="panel login-panel">
            <div class="brand-mark" aria-hidden="true">PC</div>
            <p class="eyebrow">PHỔ CẬP GIÁO DỤC - XÓA MÙ CHỮ</p>
            <h1>Đăng nhập hệ thống</h1>
            <p class="muted">Sử dụng tài khoản được cấp để tiếp tục.</p>

            <div v-if="error" class="notice" role="alert">{{ error }}</div>

            <form class="login-form" @submit.prevent="submitLogin">
                <label for="username">Tên đăng nhập</label>
                <input
                    id="username"
                    v-model.trim="username"
                    name="username"
                    autocomplete="username"
                    required
                >

                <label for="password">Mật khẩu</label>
                <input
                    id="password"
                    v-model="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >

                <label class="remember-option">
                    <input v-model="remember" type="checkbox" name="remember">
                    <span>Ghi nhớ đăng nhập</span>
                </label>

                <button class="primary-button" type="submit" :disabled="busy">
                    {{ busy ? 'Đang đăng nhập…' : 'Đăng nhập' }}
                </button>
            </form>
        </section>

        <section v-else class="panel dashboard-panel">
            <header class="topbar">
                <div class="app-brand">
                    <div class="brand-mark" aria-hidden="true">PC</div>
                    <div>
                        <p class="eyebrow">PHỔ CẬP GIÁO DỤC - XÓA MÙ CHỮ</p>
                        <h1>Hệ thống PCGD - XMC</h1>
                        <p class="muted">Xin chào, {{ user.name }}</p>
                    </div>
                </div>
                <button class="secondary-button" type="button" :disabled="busy" @click="submitLogout">
                    Đăng xuất
                </button>
            </header>

            <div v-if="error" class="notice" role="alert">{{ error }}</div>

            <nav class="module-nav" aria-label="Chức năng hệ thống">
                <button type="button" :class="{ selected: !activeModule }" @click="activeModule = ''">Trang chủ</button>
                <button v-if="user.permissions?.includes('view-households')" type="button" :class="{ selected: activeModule === 'households' }" @click="activeModule = 'households'">Phiếu điều tra</button>
                <button v-if="user.permissions?.includes('manage-schools')" type="button" :class="{ selected: activeModule === 'schools' }" @click="activeModule = 'schools'">Trường học</button>
                <button v-if="user.permissions?.includes('manage-villages')" type="button" :class="{ selected: activeModule === 'villages' }" @click="activeModule = 'villages'">Thôn / xóm</button>
                <button v-if="user.permissions?.includes('manage-access-control')" type="button" :class="{ selected: activeModule === 'roles' }" @click="activeModule = 'roles'">Nhóm quyền</button>
                <button v-if="user.permissions?.includes('manage-user-accounts')" type="button" :class="{ selected: activeModule === 'users' }" @click="activeModule = 'users'">Tài khoản</button>
                <button v-if="user.permissions?.includes('import-households')" type="button" :class="{ selected: activeModule === 'imports' }" @click="activeModule = 'imports'">Nhập / xuất Excel</button>
                <button v-if="user.permissions?.includes('delete-households')" type="button" :class="{ selected: activeModule === 'trash' }" @click="activeModule = 'trash'">Thùng rác</button>
                <button type="button" :class="{ selected: activeModule === 'account' }" @click="activeModule = 'account'">Tài khoản của tôi</button>
                <button v-if="user.scope_level === 'commune' && user.permissions?.includes('manage-villages')" type="button" :class="{ selected: activeModule === 'merge' }" @click="activeModule = 'merge'">Gộp thôn</button>
            </nav>

            <Workspace
                v-if="activeModule"
                :module-key="activeModule"
                :permissions="user.permissions ?? []"
                @close="activeModule = ''"
            />

            <div v-else>
            <div class="account-card">
                <span class="account-avatar">{{ user.name.slice(0, 1).toLocaleUpperCase('vi') }}</span>
                <div>
                    <strong>{{ user.username }}</strong>
                    <p class="muted">Phạm vi tài khoản: {{ scopeLabels[user.scope_level ?? ''] ?? 'Chưa xác định' }}</p>
                </div>
            </div>

            <h2>Quyền được cấp</h2>
            <ul v-if="user.permissions?.length" class="permission-list">
                <li v-for="permission in user.permissions" :key="permission">{{ permissionLabels[permission] ?? permission }}</li>
            </ul>
            <p v-else class="muted">API phiên làm việc chưa trả về danh sách quyền.</p>

            <section v-if="user.permissions?.includes('view-households')" class="household-section">
                <div class="section-heading">
                    <div>
                        <h2>Phiếu điều tra</h2>
                        <p class="muted">{{ householdCount }} hộ trong phạm vi được phép truy cập</p>
                    </div>
                    <span v-if="loadingHouseholds" class="muted">Đang tải…</span>
                </div>
                <form class="household-filters" @submit.prevent="loadHouseholds(1)">
                    <input
                        v-model.trim="householdQuery"
                        type="search"
                        aria-label="Tìm theo mã phiếu hoặc chủ hộ"
                        placeholder="Mã phiếu / Chủ hộ"
                    >
                    <select v-model="schoolYear" aria-label="Lọc theo năm học">
                        <option value="all">Tất cả năm học</option>
                        <option :value="new Date().getMonth() >= 7 ? `${new Date().getFullYear()}-${new Date().getFullYear() + 1}` : `${new Date().getFullYear() - 1}-${new Date().getFullYear()}`">
                            Năm học hiện tại
                        </option>
                    </select>
                    <button class="secondary-button" type="submit" :disabled="loadingHouseholds">Lọc</button>
                    <button
                        class="secondary-button"
                        type="button"
                        :disabled="loadingHouseholds || currentPage <= 1"
                        @click="loadHouseholds(currentPage - 1)"
                    >
                        Trang trước
                    </button>
                    <span class="muted">Trang {{ currentPage }} / {{ lastPage }}</span>
                    <button
                        class="secondary-button"
                        type="button"
                        :disabled="loadingHouseholds || currentPage >= lastPage"
                        @click="loadHouseholds(currentPage + 1)"
                    >
                        Trang sau
                    </button>
                </form>
                <div v-if="households.length" class="household-list">
                    <button
                        v-for="household in households"
                        :key="household.id"
                        class="household-item"
                        type="button"
                        @click="openHousehold(household)"
                    >
                        <div>
                            <strong>{{ household.head_name || 'Chưa có thông tin chủ hộ' }}</strong>
                            <p class="muted">
                                Phiếu {{ household.household_code }} · {{ household.school_year }}
                            </p>
                        </div>
                        <span class="member-count">{{ household.members_count }} nhân khẩu</span>
                    </button>
                </div>
                <p v-else-if="!loadingHouseholds" class="empty-state">Chưa có phiếu điều tra trong phạm vi này.</p>

                <section v-if="selectedHousehold" class="household-detail">
                    <button class="close-detail" type="button" @click="selectedHousehold = null">Đóng</button>
                    <h2>Phiếu {{ selectedHousehold.household_code }} · {{ selectedHousehold.school_year }}</h2>
                    <p class="muted">
                        {{ [selectedHousehold.village?.name, selectedHousehold.commune?.name, selectedHousehold.province?.name].filter(Boolean).join(', ') || 'Chưa khai báo địa bàn' }}
                    </p>
                    <p>{{ selectedHousehold.address || 'Chưa có địa chỉ' }}</p>
                    <h3>Nhân khẩu ({{ selectedHousehold.persons.length }})</h3>
                    <ul class="detail-person-list">
                        <li v-for="person in selectedHousehold.persons" :key="person.id">
                            <span>{{ person.name }}</span>
                            <span class="muted">{{ person.relationship_with_head || '—' }} · {{ person.dob || 'Chưa có ngày sinh' }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="user.permissions?.includes('manage-access-control')" class="household-section">
                    <div class="section-heading">
                        <div>
                            <h2>Nhóm người dùng</h2>
                            <p class="muted">Chọn nhóm để xem tài khoản thành viên trong phạm vi quản lý.</p>
                        </div>
                    </div>
                    <div v-if="roles.length" class="role-list">
                        <button
                            v-for="role in roles"
                            :key="role.id"
                            type="button"
                            class="role-item"
                            :class="{ 'is-selected': selectedRole?.id === role.id }"
                            @click="openRole(role)"
                        >
                            <span>
                                <strong>{{ role.name }}</strong>
                                <small>{{ role.description || role.scope_level }}</small>
                            </span>
                            <span class="member-count">{{ role.users_count }} tài khoản</span>
                        </button>
                    </div>
                    <p v-else class="empty-state">Không có nhóm người dùng trong phạm vi quản lý.</p>

                    <section v-if="selectedRole" class="household-detail">
                        <div class="section-heading">
                            <div>
                                <h2>Thành viên: {{ selectedRole.name }}</h2>
                                <p class="muted">{{ roleMembersTotal }} tài khoản</p>
                            </div>
                            <div class="pagination-actions">
                                <button
                                    class="secondary-button"
                                    type="button"
                                    :disabled="roleMembersPage <= 1"
                                    @click="openRole(selectedRole, roleMembersPage - 1)"
                                >Trước</button>
                                <span class="muted">{{ roleMembersPage }} / {{ roleMembersLastPage }}</span>
                                <button
                                    class="secondary-button"
                                    type="button"
                                    :disabled="roleMembersPage >= roleMembersLastPage"
                                    @click="openRole(selectedRole, roleMembersPage + 1)"
                                >Sau</button>
                            </div>
                        </div>
                        <div v-if="roleMembers.length" class="role-member-list">
                            <article v-for="member in roleMembers" :key="member.id" class="role-member">
                                <div>
                                    <strong>{{ member.name }}</strong>
                                    <p class="muted">{{ member.username || 'Chưa có tên đăng nhập' }} · {{ member.scope_level || 'Chưa xác định' }}</p>
                                    <p class="muted">
                                        {{ [member.province, member.commune, ...member.villages].filter(Boolean).join(' · ') || 'Chưa gán địa bàn' }}
                                    </p>
                                </div>
                                <span class="status-pill" :class="{ inactive: !member.is_active }">
                                    {{ member.is_active ? 'Đang hoạt động' : 'Đã vô hiệu hóa' }}
                                </span>
                            </article>
                        </div>
                        <p v-else class="empty-state">Nhóm này chưa có thành viên.</p>
                    </section>
                </section>
            </section>
            </div>
        </section>
    </main>
</template>
