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
    HOUSEHOLD_IMPORT_COMPLETED_EVENT,
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
const Dashboard = defineAsyncComponent(() => import("./views/Dashboard.vue"));
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

type ImportNotice = {
    id: number;
    type: "queued" | "success" | "partial" | "failed";
    title: string;
    message: string;
    sourceImportId?: number;
};

const user = ref<User | null>(null);
const sessionChecked = ref(false);
const busy = ref(false);
const error = ref("");
const activeModule = ref<ModuleKey>("");
const accountSection = ref<"profile" | "password">("profile");
const importNotices = ref<ImportNotice[]>([]);
const activeImportNotice = ref<ImportNotice | null>(null);
const notifiedImportIds = new Set<number>();
const pendingImportNotificationAcks = new Set<number>();
let importNotificationTimer: number | null = null;
let importNotificationRequestActive = false;
let importNotificationPollRequested = false;
let nextImportNoticeId = 0;
let activeUploadNotice: ImportNotice | null = null;

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
    if (
        module === "households" &&
        /^\/households\/\d+(?:\/|$)/.test(url.pathname)
    ) {
        window.history.replaceState({ module }, "", url);
        return;
    }
    const path = module === "import-file" ? "imports" : module;
    url.pathname = path ? `/${path}` : "/";
    window.history.replaceState({ module }, "", url);
}

function restoreModuleFromUrl(): void {
    const requestedModule = moduleFromUrl();
    activeModule.value = requestedModule === "" && !canViewDashboard.value
        ? firstAvailableModule()
        : requestedModule;
}

const permissions = computed(() => user.value?.permissions ?? []);
const canViewDashboard = computed(() => user.value?.can_view_dashboard === true);

function firstAvailableModule(): ModuleKey {
    const availablePermissions = permissions.value;
    if (availablePermissions.includes("view-households")) return "households";
    if (availablePermissions.includes("view-villages")) return "villages";
    if (availablePermissions.includes("view-schools")) return "schools";
    if (availablePermissions.includes("view-users")) return "users";
    if (availablePermissions.includes("view-roles")) return "roles";
    if (availablePermissions.includes("view-trash")) return "trash";
    if (availablePermissions.includes("view-imports")) return "import-history";
    if (availablePermissions.includes("create-imports")) return "import-file";
    return "account";
}

function stopImportNotificationPolling(): void {
    if (importNotificationTimer !== null) {
        window.clearTimeout(importNotificationTimer);
        importNotificationTimer = null;
    }
}

function showImportNotice(
    type: ImportNotice["type"],
    title: string,
    message: string,
    sourceImportId?: number,
): number {
    const id = ++nextImportNoticeId;
    const notice = { id, type, title, message, sourceImportId };
    importNotices.value.push(notice);
    if (!activeImportNotice.value) {
        activeImportNotice.value = notice;
    }
    return id;
}

function showNextImportNotice(): void {
    if (!activeImportNotice.value) {
        activeImportNotice.value = importNotices.value[0] ?? null;
    }
}

function dismissImportNotice(id: number): void {
    const notice = importNotices.value.find((item) => item.id === id);
    importNotices.value = importNotices.value.filter(
        (notice) => notice.id !== id,
    );
    if (activeImportNotice.value?.id === id) activeImportNotice.value = null;
    if (notice?.sourceImportId !== undefined) {
        pendingImportNotificationAcks.add(notice.sourceImportId);
        void acknowledgeImportNotifications();
    }
    showNextImportNotice();
}

function closeImportNotice(notice: ImportNotice): void {
    if (activeUploadNotice?.id === notice.id) {
        activeImportNotice.value = null;
        showNextImportNotice();
    } else {
        dismissImportNotice(notice.id);
    }
}

async function acknowledgeImportNotifications(): Promise<void> {
    if (pendingImportNotificationAcks.size === 0) return;

    const ids = [...pendingImportNotificationAcks].slice(0, 5);
    try {
        await api.patch("/household-import-notifications/read", { ids });
        ids.forEach((id) => pendingImportNotificationAcks.delete(id));
    } catch (cause) {
        console.error("Không thể đánh dấu thông báo nhập file đã đọc.", cause);
    }
}

async function pollImportNotifications(): Promise<void> {
    if (!permissions.value.includes("create-imports")) {
        return;
    }
    if (importNotificationRequestActive) {
        importNotificationPollRequested = true;
        return;
    }

    importNotificationRequestActive = true;
    let shouldContinuePolling = false;
    try {
        const response = await api.get<unknown>(
            "/household-import-notifications",
        );
        const payload = apiData<{
            notifications?: unknown;
            pending_count?: unknown;
        }>(response.data);
        if (
            !Array.isArray(payload.notifications) ||
            typeof payload.pending_count !== "number" ||
            !Number.isSafeInteger(payload.pending_count) ||
            payload.pending_count < 0
        ) {
            throw new Error("Máy chủ trả về thông báo nhập file không hợp lệ.");
        }
        shouldContinuePolling = payload.pending_count > 0;

        for (const value of payload.notifications) {
            if (typeof value !== "object" || value === null) continue;
            const notification = value as Record<string, unknown>;
            const id = notification.id;
            const status = notification.status;
            const message = notification.message;
            if (
                typeof id !== "number" ||
                !Number.isSafeInteger(id) ||
                typeof message !== "string" ||
                !["SUCCESS", "PARTIAL", "FAILED"].includes(String(status))
            ) {
                continue;
            }
            if (notifiedImportIds.has(id)) continue;
            notifiedImportIds.add(id);
            window.dispatchEvent(
                new CustomEvent(HOUSEHOLD_IMPORT_COMPLETED_EVENT, {
                    detail: { id },
                }),
            );
            const type: ImportNotice["type"] =
                status === "SUCCESS"
                    ? "success"
                    : status === "PARTIAL"
                      ? "partial"
                      : "failed";
            showImportNotice(
                type,
                status === "SUCCESS"
                    ? "Nhập dữ liệu thành công"
                    : status === "PARTIAL"
                      ? "Đã xử lý xong, cần kiểm tra"
                      : "Chưa thể nhập dữ liệu",
                message,
                id,
            );
        }

        await acknowledgeImportNotifications();
    } catch (cause) {
        console.error("Không thể đồng bộ thông báo nhập file.", cause);
        shouldContinuePolling = true;
    } finally {
        importNotificationRequestActive = false;
        shouldContinuePolling =
            shouldContinuePolling || importNotificationPollRequested;
        importNotificationPollRequested = false;
        if (shouldContinuePolling && permissions.value.includes("create-imports")) {
            importNotificationTimer = window.setTimeout(
                () => {
                    importNotificationTimer = null;
                    void pollImportNotifications();
                },
                5000,
            );
        }
    }
}

function startImportNotificationPolling(): void {
    stopImportNotificationPolling();
    if (!permissions.value.includes("create-imports")) return;
    void pollImportNotifications();
}

function clearImportNotifications(): void {
    stopImportNotificationPolling();
    notifiedImportIds.clear();
    pendingImportNotificationAcks.clear();
    importNotices.value = [];
    activeImportNotice.value = null;
    activeUploadNotice = null;
}

function handleHouseholdImportSubmitted(event: Event): void {
    const detail = (event as CustomEvent<unknown>).detail;
    if (typeof detail !== "object" || detail === null) return;

    const importData = detail as Record<string, unknown>;
    if (importData.stage === "dismiss") {
        if (activeUploadNotice) {
            if (activeImportNotice.value?.id === activeUploadNotice.id) {
                activeImportNotice.value = null;
            }
            activeUploadNotice = null;
        }
        showNextImportNotice();
        return;
    }

    if (importData.stage === "failed") {
        if (activeUploadNotice) {
            if (activeImportNotice.value?.id === activeUploadNotice.id) {
                activeImportNotice.value = null;
            }
            activeUploadNotice = null;
        }
        showImportNotice(
            "failed",
            "Không thể tải tệp lên",
            typeof importData.message === "string"
                ? importData.message
                : "Đã xảy ra lỗi khi gửi tệp. Vui lòng thử lại.",
        );
        return;
    }

    const candidates = Array.isArray(importData.imports)
        ? importData.imports
        : [importData];
    const submittedImports = candidates.flatMap((candidate) => {
        if (typeof candidate !== "object" || candidate === null) return [];
        const item = candidate as Record<string, unknown>;
        const fileName = item.fileName ?? item.file_name;
        return typeof fileName === "string"
            ? [{ fileName }]
            : [];
    });
    if (submittedImports.length === 0) return;

    const count = submittedImports.length;
    if (importData.stage === "uploading") {
        if (activeUploadNotice) {
            if (activeImportNotice.value?.id === activeUploadNotice.id) {
                activeImportNotice.value = null;
            }
        }
        activeUploadNotice = {
            id: ++nextImportNoticeId,
            type: "queued",
            title:
                count === 1
                    ? "Đang tải tệp Excel lên"
                    : `Đang tải ${count} tệp Excel lên`,
            message:
                count === 1
                    ? `Tệp "${submittedImports[0].fileName}" đang được gửi. Bạn có thể tiếp tục làm việc sau khi đóng thông báo này.`
                    : `${count} tệp đang được gửi. Bạn có thể tiếp tục làm việc sau khi đóng thông báo này.`,
        };
        if (!activeImportNotice.value) {
            activeImportNotice.value = activeUploadNotice;
        }
        return;
    }

    if (importData.stage === "accepted") {
        startImportNotificationPolling();
        if (activeUploadNotice) {
            const notice: ImportNotice = {
                ...activeUploadNotice,
                title:
                    count === 1
                        ? "Đã nhận tệp Excel"
                        : `Đã nhận ${count} tệp Excel`,
                message:
                    count === 1
                        ? `Tệp "${submittedImports[0].fileName}" đã được tiếp nhận và đang xử lý. Bạn có thể tiếp tục làm việc; hệ thống sẽ thông báo khi hoàn tất.`
                        : `${count} tệp đã được tiếp nhận và đang xử lý. Bạn có thể tiếp tục làm việc; hệ thống sẽ thông báo khi hoàn tất.`,
            };
            if (activeImportNotice.value?.id === notice.id) {
                activeImportNotice.value = notice;
            }
            activeUploadNotice = null;
        }
        return;
    }

    showImportNotice(
        "queued",
        count === 1 ? "Đã nhận tệp Excel" : `Đã nhận ${count} tệp Excel`,
        count === 1
            ? `Tệp "${submittedImports[0].fileName}" đang chờ hệ thống xử lý. Bạn có thể tiếp tục làm việc.`
            : `${count} tệp đang chờ hệ thống xử lý. Bạn có thể tiếp tục làm việc; hệ thống sẽ thông báo kết quả từng tệp.`,
    );
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
    clearImportNotifications();
    user.value = null;
    activeModule.value = "";
    error.value =
        "Tài khoản đã bị vô hiệu hóa. Vui lòng liên hệ quản trị viên.";
}

function handleSessionExpired(): void {
    if (!user.value) return;
    clearImportNotifications();
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
        startImportNotificationPolling();
    }
});

watch(activeModule, (module) => syncModuleUrl(module));
watch(
    [activeModule, permissions, sessionChecked],
    ([module, availablePermissions, checked]) => {
        if (!checked) return;
        if (user.value && !canViewDashboard.value && module === "") {
            activeModule.value = firstAvailableModule();
            return;
        }

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
    clearImportNotifications();
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
        startImportNotificationPolling();
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
        clearImportNotifications();
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
            :can-view-dashboard="canViewDashboard"
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
                        'trash',
                        'merge',
                        'school-merge',
                    ].includes(activeModule) ||
                    (activeModule === '' && canViewDashboard),
                }"
            >
                <p v-if="error" class="notice app-global-notice" role="alert">
                    {{ error }}
                </p>
                <div
                    v-if="activeImportNotice"
                    class="workspace-modal-backdrop account-creation-result-backdrop import-result-backdrop"
                    role="presentation"
                    @click.self="closeImportNotice(activeImportNotice)"
                    @keydown.esc="closeImportNotice(activeImportNotice)"
                >
                    <section
                        class="confirm-dialog account-creation-result-dialog import-result-dialog"
                        :class="`import-result-${activeImportNotice.type}`"
                        role="alertdialog"
                        aria-modal="true"
                        aria-labelledby="import-result-title"
                        aria-describedby="import-result-message"
                        tabindex="-1"
                    >
                        <span
                            class="confirm-dialog-icon account-creation-result-icon"
                            :class="{
                                'account-creation-success-icon':
                                    activeImportNotice.type === 'success',
                                'import-result-pending-icon':
                                    activeImportNotice.type === 'queued',
                                'import-result-partial-icon':
                                    activeImportNotice.type === 'partial',
                                'account-action-failure-icon':
                                    activeImportNotice.type === 'failed',
                            }"
                            aria-hidden="true"
                        >
                            {{
                                activeImportNotice.type === "success"
                                    ? "✓"
                                    : activeImportNotice.type === "failed"
                                      ? "!"
                                      : activeImportNotice.type === "partial"
                                        ? "•"
                                        : "↻"
                            }}
                        </span>
                        <p class="account-creation-result-eyebrow">
                            {{
                                activeImportNotice.type === "success"
                                    ? "HOÀN TẤT"
                                    : activeImportNotice.type === "failed"
                                      ? "CHƯA THÀNH CÔNG"
                                      : activeImportNotice.type === "partial"
                                        ? "CẦN KIỂM TRA"
                                        : "ĐANG XỬ LÝ"
                            }}
                        </p>
                        <h3 id="import-result-title">
                            {{ activeImportNotice.title }}
                        </h3>
                        <p id="import-result-message">
                            {{ activeImportNotice.message }}
                        </p>
                        <div class="confirm-dialog-actions">
                            <button
                                class="primary-button management-tool-button"
                                type="button"
                                autofocus
                                @click="closeImportNotice(activeImportNotice)"
                            >
                                {{
                                    activeImportNotice.type === "queued"
                                        ? "Tiếp tục làm việc"
                                        : "Đóng"
                                }}
                            </button>
                        </div>
                    </section>
                </div>
                <Dashboard
                    v-if="activeModule === '' && canViewDashboard"
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
