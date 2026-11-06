<script setup lang="ts">
import {
    computed,
    defineAsyncComponent,
    onMounted,
    onUnmounted,
    ref,
    watch,
} from "vue";
import { AxiosError } from "axios";
import Navbar from "./components/Navbar.vue";
import Sidebar, { type ModuleKey } from "./components/Sidebar.vue";
import {
    ACCOUNT_DISABLED_EVENT,
    HOUSEHOLD_IMPORT_SUBMITTED_EVENT,
    SESSION_EXPIRED_EVENT,
    api,
    apiData,
    errorMessage,
    getSession,
    login,
    logout,
} from "./api";
import Login from "./views/auth/Login.vue";
import type { User } from "./types";

const Workspace = defineAsyncComponent(() => import("./Workspace.vue"));
const Dashboard = defineAsyncComponent(
    () => import("./views/Dashboard.vue"),
);
const ThonXomList = defineAsyncComponent(
    () => import("./views/category/ThonXomList.vue"),
);
const TruongHocList = defineAsyncComponent(
    () => import("./views/category/TruongHocList.vue"),
);
const PhieuDieuTra = defineAsyncComponent(
    () => import("./views/investigation/PhieuDieuTra.vue"),
);
const TrashBin = defineAsyncComponent(
    () => import("./views/investigation/TrashBin.vue"),
);
const GopThon = defineAsyncComponent(
    () => import("./views/business/GopThon.vue"),
);
const GopTruong = defineAsyncComponent(
    () => import("./views/business/GopTruong.vue"),
);

type ImportStatus = {
    status: string;
    success_rows?: number;
    error_rows?: number;
};
type ImportNotice = {
    id: number;
    type: "queued" | "success" | "partial" | "failed";
    title: string;
    message: string;
};

const user = ref<User | null>(null);
const sessionChecked = ref(false);
const busy = ref(false);
const error = ref("");
const activeModule = ref<ModuleKey>("");
const accountSection = ref<"profile" | "password">("profile");
const trackedImports = new Map<number, string>();
const importNotices = ref<ImportNotice[]>([]);
const importNoticeTimers = new Map<number, number>();
let importStatusTimer: number | null = null;
let importStatusRequestActive = false;
let nextImportNoticeId = 0;

const moduleKeys: ModuleKey[] = [
    "",
    "households",
    "schools",
    "villages",
    "roles",
    "users",
    "account",
    "import-file",
    "import-history",
    "trash",
    "merge",
    "school-merge",
];

function moduleFromUrl(): ModuleKey {
    const module = window.location.pathname
        .replace(/^\/+|\/+$/g, "")
        .split("/")[0];
    if (module === "imports") return "import-file";
    return moduleKeys.includes(module as ModuleKey)
        ? (module as ModuleKey)
        : "";
}

function syncModuleUrl(module: ModuleKey): void {
    const url = new URL(window.location.href);
    const path = module === "import-file" ? "imports" : module;
    url.pathname = path ? `/${path}` : "/";
    window.history.replaceState({ module }, "", url);
}

function restoreModuleFromUrl(): void {
    activeModule.value = moduleFromUrl();
}

const permissions = computed(() => user.value?.permissions ?? []);

function stopImportStatusPolling(): void {
    if (importStatusTimer !== null) {
        window.clearInterval(importStatusTimer);
        importStatusTimer = null;
    }
}

function showImportNotice(
    type: ImportNotice["type"],
    title: string,
    message: string,
): void {
    const id = ++nextImportNoticeId;
    importNotices.value.push({ id, type, title, message });
    if (importNotices.value.length > 4) {
        dismissImportNotice(importNotices.value[0].id);
    }

    importNoticeTimers.set(
        id,
        window.setTimeout(
            () => dismissImportNotice(id),
            type === "queued" ? 6000 : 10000,
        ),
    );
}

function dismissImportNotice(id: number): void {
    const timer = importNoticeTimers.get(id);
    if (timer !== undefined) window.clearTimeout(timer);
    importNoticeTimers.delete(id);
    importNotices.value = importNotices.value.filter(
        (notice) => notice.id !== id,
    );
}

function importCompletionMessage(
    fileName: string,
    status: ImportStatus,
): string {
    const successRows = status.success_rows ?? 0;
    const errorRows = status.error_rows ?? 0;

    if (status.status === "SUCCESS") {
        return `File "${fileName}" đã xử lý xong. Nhập thành công ${successRows} dòng.`;
    }
    if (status.status === "PARTIAL") {
        return `File "${fileName}" đã xử lý xong: ${successRows} dòng thành công, ${errorRows} dòng lỗi.`;
    }

    return errorRows > 0
        ? `File "${fileName}" đã xử lý xong nhưng không có dòng nào được nhập: ${errorRows} dòng lỗi. Hãy xem file dòng lỗi trong lịch sử nhập.`
        : `Hệ thống không thể đọc nội dung file "${fileName}". Hãy kiểm tra lại file Excel.`;
}

async function pollTrackedImports(): Promise<void> {
    if (importStatusRequestActive || trackedImports.size === 0) return;
    if (!user.value) {
        trackedImports.clear();
        stopImportStatusPolling();
        return;
    }

    importStatusRequestActive = true;
    try {
        const response = await api.get<unknown>(
            "/household-imports/statuses",
            { params: { ids: [...trackedImports.keys()] } },
        );
        const payload = apiData<{ statuses?: unknown }>(response.data);
        if (
            typeof payload.statuses !== "object" ||
            payload.statuses === null ||
            Array.isArray(payload.statuses)
        ) {
            throw new Error("Máy chủ trả về trạng thái file nhập không hợp lệ.");
        }

        const statuses = payload.statuses as Record<string, unknown>;
        for (const [id, fileName] of trackedImports) {
            const value = statuses[String(id)];
            if (typeof value !== "object" || value === null) continue;

            const status = value as ImportStatus;
            if (!["SUCCESS", "PARTIAL", "FAILED"].includes(status.status)) {
                continue;
            }

            trackedImports.delete(id);
            const type: ImportNotice["type"] =
                status.status === "SUCCESS"
                    ? "success"
                    : status.status === "PARTIAL"
                      ? "partial"
                      : "failed";
            showImportNotice(
                type,
                status.status === "SUCCESS"
                    ? "Nhập dữ liệu thành công"
                    : status.status === "PARTIAL"
                      ? "Đã xử lý xong, cần kiểm tra"
                      : "Chưa thể nhập dữ liệu",
                importCompletionMessage(fileName, status),
            );
        }
        if (trackedImports.size === 0) stopImportStatusPolling();
    } catch (cause) {
        console.error("Không thể kiểm tra trạng thái file nhập.", cause);
    } finally {
        importStatusRequestActive = false;
    }
}

function handleHouseholdImportSubmitted(event: Event): void {
    const detail = (event as CustomEvent<unknown>).detail;
    if (typeof detail !== "object" || detail === null) return;

    const importData = detail as Record<string, unknown>;
    if (
        typeof importData.id !== "number" ||
        typeof importData.fileName !== "string"
    ) {
        return;
    }

    trackedImports.set(importData.id, importData.fileName);
    showImportNotice(
        "queued",
        "Đã nhận tệp Excel",
        `Tệp "${importData.fileName}" đang chờ hệ thống xử lý. Bạn có thể tiếp tục làm việc.`,
    );
    if (importStatusTimer === null) {
        importStatusTimer = window.setInterval(
            () => void pollTrackedImports(),
            10000,
        );
    }
    void pollTrackedImports();
}

const pageTitles: Record<ModuleKey, string> = {
    "": "Tổng quan",
    households: "Phiếu điều tra",
    schools: "Danh mục trường học",
    villages: "Danh mục thôn / xóm",
    roles: "Nhóm người dùng",
    users: "Tài khoản & phân quyền",
    account: "Thông tin cá nhân",
    "import-file": "Import file",
    "import-history": "Lịch sử file",
    trash: "Tiện ích dữ liệu rác",
    merge: "Gộp thôn / xóm",
    "school-merge": "Gộp trường học",
};

const activeView = computed(() => {
    switch (activeModule.value) {
        case "households":
            return PhieuDieuTra;
        case "schools":
            return TruongHocList;
        case "villages":
            return ThonXomList;
        case "trash":
            return TrashBin;
        case "merge":
            return GopThon;
        case "school-merge":
            return GopTruong;
        default:
            return Workspace;
    }
});

function handleAccountDisabled(): void {
    trackedImports.clear();
    stopImportStatusPolling();
    user.value = null;
    activeModule.value = "";
    error.value =
        "Tài khoản đã bị vô hiệu hóa. Vui lòng liên hệ quản trị viên.";
}

function handleSessionExpired(): void {
    if (!user.value) return;
    trackedImports.clear();
    stopImportStatusPolling();
    user.value = null;
    activeModule.value = "";
    error.value = "Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.";
}

onMounted(async () => {
    window.addEventListener(ACCOUNT_DISABLED_EVENT, handleAccountDisabled);
    window.addEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
    window.addEventListener(
        HOUSEHOLD_IMPORT_SUBMITTED_EVENT,
        handleHouseholdImportSubmitted,
    );
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
watch(
    [activeModule, permissions, sessionChecked],
    ([module, availablePermissions, checked]) => {
        if (!checked) return;

        const requiredPermission =
            module === "merge"
                ? "view-village-merges"
                : module === "school-merge"
                  ? "view-school-merges"
                  : null;
        if (
            requiredPermission &&
            (user.value?.scope_level === "global" ||
                !availablePermissions.includes(requiredPermission))
        ) {
            activeModule.value = "";
        }
    },
);
window.addEventListener("popstate", restoreModuleFromUrl);
onUnmounted(() => {
    stopImportStatusPolling();
    for (const timer of importNoticeTimers.values())
        window.clearTimeout(timer);
    importNoticeTimers.clear();
    window.removeEventListener("popstate", restoreModuleFromUrl);
    window.removeEventListener(ACCOUNT_DISABLED_EVENT, handleAccountDisabled);
    window.removeEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
    window.removeEventListener(
        HOUSEHOLD_IMPORT_SUBMITTED_EVENT,
        handleHouseholdImportSubmitted,
    );
});

async function submitLogin(credentials: {
    username: string;
    password: string;
    remember: boolean;
}): Promise<void> {
    busy.value = true;
    error.value = "";
    try {
        user.value = await login(
            credentials.username,
            credentials.password,
            credentials.remember,
        );
        activeModule.value = "";
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

async function submitLogout(): Promise<void> {
    busy.value = true;
    error.value = "";
    try {
        await logout();
        trackedImports.clear();
        stopImportStatusPolling();
        user.value = null;
        activeModule.value = "";
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

function openAccount(section: "profile" | "password"): void {
    accountSection.value = section;
    activeModule.value = "account";
}

function updateCurrentUserName(profile: { name: string }): void {
    if (user.value) user.value.name = profile.name;
}
</script>

<template>
    <main
        v-if="!sessionChecked"
        class="session-loading"
        role="status"
        aria-live="polite"
    >
        <span class="session-loading-indicator" aria-hidden="true"></span>
        <span>Đang kiểm tra phiên đăng nhập…</span>
    </main>
    <Login
        v-else-if="!user"
        :busy="busy"
        :error="error"
        @submit="submitLogin"
    />

    <main v-else class="app-layout">
        <Sidebar
            :active-module="activeModule"
            :permissions="permissions"
            :scope-level="user?.scope_level ?? null"
            @select="activeModule = $event"
        />
        <div class="app-main">
            <Navbar
                :user="user"
                :title="pageTitles[activeModule]"
                :busy="busy"
                @account="openAccount('profile')"
                @change-password="openAccount('password')"
                @logout="submitLogout"
            />
            <div
                class="app-content"
                :class="{
                    'app-content-wide': [
                        'users',
                        'roles',
                        'villages',
                        'schools',
                        'households',
                    ].includes(activeModule),
                }"
            >
                <p v-if="error" class="notice app-global-notice" role="alert">
                    {{ error }}
                </p>
                <div
                    class="import-toast-stack"
                    aria-live="polite"
                    aria-relevant="additions"
                >
                    <section
                        v-for="notice in importNotices"
                        :key="notice.id"
                        class="import-toast"
                        :class="`import-toast-${notice.type}`"
                        role="status"
                    >
                        <span class="import-toast-icon" aria-hidden="true">
                            {{ notice.type === "success" ? "✓" : notice.type === "failed" ? "!" : notice.type === "partial" ? "•" : "↻" }}
                        </span>
                        <div class="import-toast-content">
                            <strong>{{ notice.title }}</strong>
                            <p>{{ notice.message }}</p>
                            <button
                                v-if="notice.type !== 'queued'"
                                class="import-toast-history"
                                type="button"
                                @click="
                                    activeModule = 'import-history';
                                    dismissImportNotice(notice.id);
                                "
                            >
                                Xem lịch sử nhập
                            </button>
                        </div>
                        <button
                            type="button"
                            aria-label="Đóng thông báo"
                            @click="dismissImportNotice(notice.id)"
                        >
                            ×
                        </button>
                    </section>
                </div>
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
                    :module-key="
                        activeModule === 'import-file' ||
                        activeModule === 'import-history'
                            ? 'imports'
                            : activeModule
                    "
                    :permissions="permissions"
                    :scope-level="user?.scope_level ?? null"
                    :import-view="
                        activeModule === 'import-history' ? 'history' : 'upload'
                    "
                    :account-section="accountSection"
                    @navigate="activeModule = $event"
                    @profile-updated="updateCurrentUserName"
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
