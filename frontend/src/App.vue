<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { AxiosError } from 'axios';
import Navbar from './components/Navbar.vue';
import Sidebar, { type ModuleKey } from './components/Sidebar.vue';
import { errorMessage, getSession, login, logout } from './api';
import Workspace from './Workspace.vue';
import Dashboard from './views/Dashboard.vue';
import Login from './views/auth/Login.vue';
import ThonXomList from './views/category/ThonXomList.vue';
import TruongHocList from './views/category/TruongHocList.vue';
import PhieuDieuTra from './views/investigation/PhieuDieuTra.vue';
import TrashBin from './views/investigation/TrashBin.vue';
import GopThon from './views/business/GopThon.vue';
import GopTruong from './views/business/GopTruong.vue';
import ExportReport from './views/investigation/ExportReport.vue';
import type { User } from './types';

const user = ref<User | null>(null);
const sessionChecked = ref(false);
const busy = ref(false);
const error = ref('');
const activeModule = ref<ModuleKey>('');

const moduleKeys: ModuleKey[] = [
    '', 'households', 'schools', 'villages', 'roles', 'users', 'account',
    'imports', 'exports', 'trash', 'merge', 'school-merge',
];

function moduleFromUrl(): ModuleKey {
    const module = window.location.pathname.replace(/^\/+|\/+$/g, '').split('/')[0];
    return moduleKeys.includes(module as ModuleKey) ? module as ModuleKey : '';
}

function syncModuleUrl(module: ModuleKey): void {
    const url = new URL(window.location.href);
    url.pathname = module ? `/${module}` : '/';
    window.history.replaceState({ module }, '', url);
}

function restoreModuleFromUrl(): void {
    activeModule.value = moduleFromUrl();
}

const permissions = computed(() => user.value?.permissions ?? []);
const pageTitles: Record<ModuleKey, string> = {
    '': 'Tổng quan',
    households: 'Phiếu điều tra',
    schools: 'Danh mục trường học',
    villages: 'Danh mục thôn / xóm',
    roles: 'Nhóm người dùng',
    users: 'Tài khoản & phân quyền',
    account: 'Thông tin cá nhân',
    imports: 'Nhập / xuất dữ liệu',
    exports: 'Xuất Excel',
    trash: 'Tiện ích dữ liệu rác',
    merge: 'Gộp thôn / xóm',
    'school-merge': 'Gộp trường học',
};

const activeView = computed(() => {
    switch (activeModule.value) {
        case 'households': return PhieuDieuTra;
        case 'schools': return TruongHocList;
        case 'villages': return ThonXomList;
        case 'trash': return TrashBin;
        case 'merge': return GopThon;
        case 'school-merge': return GopTruong;
        case 'exports': return ExportReport;
        default: return Workspace;
    }
});

onMounted(async () => {
    try {
        user.value = await getSession();
    } catch (cause) {
        if (!(cause instanceof AxiosError) || cause.response?.status !== 401) {
            error.value = errorMessage(cause);
        }
    } finally {
        sessionChecked.value = true;
        restoreModuleFromUrl();
        syncModuleUrl(activeModule.value);
    }
});

watch(activeModule, (module) => syncModuleUrl(module));
window.addEventListener('popstate', restoreModuleFromUrl);
onUnmounted(() => window.removeEventListener('popstate', restoreModuleFromUrl));

async function submitLogin(credentials: { username: string; password: string; remember: boolean }): Promise<void> {
    busy.value = true;
    error.value = '';
    try {
        user.value = await login(credentials.username, credentials.password, credentials.remember);
        activeModule.value = '';
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
        activeModule.value = '';
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main v-if="!sessionChecked" class="session-loading" role="status" aria-live="polite">
        <span class="session-loading-indicator" aria-hidden="true"></span>
        <span>Đang kiểm tra phiên đăng nhập…</span>
    </main>
    <Login v-else-if="!user" :busy="busy" :error="error" @submit="submitLogin" />

    <main v-else class="app-layout">
        <Sidebar
            :active-module="activeModule"
            :permissions="permissions"
            @select="activeModule = $event"
        />
        <div class="app-main">
            <Navbar
                :user="user"
                :title="pageTitles[activeModule]"
                :busy="busy"
                @account="activeModule = 'account'"
                @password="activeModule = 'account'"
                @logout="submitLogout"
            />
            <div class="app-content">
                <p v-if="error" class="notice app-global-notice" role="alert">{{ error }}</p>
                <Dashboard
                    v-if="activeModule === ''"
                    :user="user"
                    @navigate="activeModule = $event"
                />
                <PhieuDieuTra
                    v-else-if="activeModule === 'households'"
                    :user="user"
                    :permissions="permissions"
                    @navigate="activeModule = $event"
                    @close="activeModule = ''"
                />
                <component
                    :is="activeView"
                    v-else
                    :module-key="activeModule"
                    :permissions="permissions"
                    @navigate="activeModule = $event"
                    @close="activeModule = ''"
                />
            </div>
            <footer class="app-footer">
                <span>PCGD - XMC</span>
                <span>Hệ thống quản lý phổ cập giáo dục và xóa mù chữ</span>
            </footer>
        </div>
    </main>
</template>
