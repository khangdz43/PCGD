<script setup lang="ts">
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    ref,
    watch,
    type Directive,
} from "vue";
import {
    api,
    apiData,
    apiPage,
    apiRows,
    errorMessage,
    getLocationOptions,
    HOUSEHOLD_IMPORT_COMPLETED_EVENT,
    HOUSEHOLD_IMPORT_SUBMITTED_EVENT,
    validationErrors,
} from "./api";

type ModuleKey =
    | "households"
    | "schools"
    | "villages"
    | "roles"
    | "users"
    | "account"
    | "imports"
    | "trash"
    | "merge";
type Row = Record<string, unknown>;
type PermissionAction = "view" | "create" | "update" | "delete";
type PermissionMatrixNode = {
    key: string;
    label: string;
    actions?: Partial<Record<PermissionAction, string[]>>;
    children?: PermissionMatrixNode[];
};
type PermissionMatrixRow = {
    key: string;
    label: string;
    depth: number;
    section: boolean;
    actions: Partial<Record<PermissionAction, string[]>>;
    permissionNames: string[];
};
type AccountAction = "status" | "password";
type AccountActionDialog = {
    mode: "confirm" | "password-form" | "result";
    action: AccountAction;
    row: Row;
    accountName: string;
    success?: boolean;
    message?: string;
};
type DeleteResult = {
    success: boolean;
    message: string;
    title?: string;
};
type TrashActionDialog = {
    operation: "restore" | "force_delete";
    row?: Row;
};

const props = defineProps<{
    moduleKey: ModuleKey;
    permissions: string[];
    scopeLevel?: string | null;
    importView?: "upload" | "history";
    accountSection?: "profile" | "password";
    householdFilters?: {
        province_code: string;
        commune_code: string;
        village_code: string;
    };
    villageFilters?: { province_code: string; commune_code: string; q: string };
}>();
const emit = defineEmits<{
    close: [];
    navigate: [module: "import-file" | "import-history"];
    profileUpdated: [profile: { name: string }];
    villageOptions: [
        data: {
            provinces?: unknown;
            province_code?: string;
            commune_code?: string;
        },
    ];
}>();

const busy = ref(false);
const error = ref("");
const notice = ref("");
const configurationFormOpen = ref(false);
const formErrors = ref<Record<string, string>>({});
const userFormSubmitted = ref(false);
const roleFormSubmitted = ref(false);
const schoolFormSubmitted = ref(false);
const villageFormSubmitted = ref(false);
const householdFormSubmitted = ref(false);
const initialVillageFormState = ref<string | null>(null);
const initialSchoolFormState = ref<string | null>(null);
const initialHouseholdFormState = ref<string | null>(null);
const initialUserFormState = ref<string | null>(null);
const rows = ref<Row[]>([]);
const selected = ref<Row | null>(null);
const selectedRolePermissions = ref<Row | null>(null);
const pendingDelete = ref<Row | null>(null);
const pendingHouseholdBulkDelete = ref(false);
const selectedHouseholdIds = ref<number[]>([]);
const selectedTrashKeys = ref<string[]>([]);
const trashFilterType = ref("household");
const trashFilterQuery = ref("");
const trashPageSize = ref(10);
const deleteResult = ref<DeleteResult | null>(null);
const trashActionDialog = ref<TrashActionDialog | null>(null);
const accountActionDialog = ref<AccountActionDialog | null>(null);
const accountPassword = ref("");
const accountPasswordConfirmation = ref("");
const accountPasswordError = ref("");
const showAccountPassword = ref(false);
const showAccountPasswordConfirmation = ref(false);
const userDetail = ref<Row | null>(null);
const userDetailRow = ref<Row | null>(null);
const userDetailLoading = ref(false);
const userDetailError = ref("");
const managementResult = ref<{
    success: boolean;
    title: string;
    message: string;
    username?: string;
} | null>(null);
const userFormRequestError = ref("");
const roleFormRequestError = ref("");
const form = ref<Record<string, string>>({});
const showUserPassword = ref(false);
const showUserPasswordConfirmation = ref(false);
const permissionIds = ref<string[]>([]);
const initialRoleFormState = ref<string | null>(null);
const permissionOptions = ref<Row[]>([]);
const roleScopeLevels = ref<string[]>(["global", "province", "commune"]);
const accountLevels = ref<string[]>([]);
const roleOptions = ref<Row[]>([]);
const availablePrimaryAdminScopes = ref<Record<string, boolean>>({});
const formOptionsBusy = ref(false);
const provinces = ref<Row[]>([]);
const formCommunes = ref<Row[]>([]);
const formVillages = ref<Row[]>([]);
const selectedUserVillageCodes = ref<string[]>([]);
const schoolLevelOptions = ref(["MN", "TH", "THCS", "THPT"]);
const schoolFilterCommunes = ref<Row[]>([]);
const schoolFilterProvince = ref("");
const schoolFilterCommune = ref("");
const schoolActorScopeLevel = ref<string | null>(null);
const schoolActorCommuneCode = ref("");
const schoolFilterLevel = ref("");
const schoolFilterQuery = ref("");
const appliedSchoolFilters = ref({
    province_code: "",
    commune_code: "",
    level: "",
    q: "",
});
const userFilterCommunes = ref<Row[]>([]);
const userFilterVillages = ref<Row[]>([]);
const userFilterProvince = ref("");
const userFilterCommune = ref("");
const userFilterVillage = ref("");
const userFilterRole = ref("");
const userFilterScopeLevel = ref("");
const userFilterQuery = ref("");
const userFilterRoles = ref<Row[]>([]);
const userAccountScopeLevels = ref<string[]>([]);
const userFiltersInitialized = ref(false);
let syncingVillageOptions = false;
const currentActorScopeLevel = ref<string | null>(null);
const canEditAccountConfigurations = ref(false);
const accountForm = ref({
    name: "",
    email: "",
    phone: "",
    citizen_id: "",
    date_of_birth: "",
});
const initialAccountForm = ref({
    name: "",
    email: "",
    phone: "",
    citizen_id: "",
    date_of_birth: "",
});
const accountFormErrors = ref<Record<string, string>>({});
const passwordForm = ref({
    current_password: "",
    password: "",
    password_confirmation: "",
});
const passwordFormErrors = ref<Record<string, string>>({});
const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showPasswordConfirmation = ref(false);
const localToday = new Date();
localToday.setMinutes(localToday.getMinutes() - localToday.getTimezoneOffset());
const latestBirthDate = localToday.toISOString().slice(0, 10);
const currentImportSchoolYear = (() => {
    const currentDate = new Date();
    const startYear =
        currentDate.getFullYear() - (currentDate.getMonth() < 7 ? 1 : 0);
    return `${startYear}-${startYear + 1}`;
})();

function formatImportDate(value: unknown): string {
    if (typeof value !== "string") return "Không rõ thời gian";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "Không rõ thời gian";

    return new Intl.DateTimeFormat("vi-VN", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(date);
}

function formatTrashDeletedAt(value: unknown): string {
    if (typeof value !== "string" || !value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";

    return new Intl.DateTimeFormat("vi-VN", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23",
    }).format(date);
}
const selectedVillageMergeSources = computed(() =>
    rows.value.filter((village) =>
        selectedSourceCodes.value.includes(String(village.code)),
    ),
);
const villageMergeSourceSearch = ref("");
const selectableVillageMergeSources = computed(() =>
    rows.value.filter(
        (village) =>
            !village.merged_into_code &&
            String(village.code) !== String(form.value.target_code ?? ""),
    ),
);
const filteredVillageMergeSources = computed(() => {
    const query = villageMergeSourceSearch.value.trim().toLocaleLowerCase("vi");
    if (!query) return selectableVillageMergeSources.value;

    return selectableVillageMergeSources.value.filter((village) =>
        [village.name, village.code].some((value) =>
            String(value ?? "")
                .toLocaleLowerCase("vi")
                .includes(query),
        ),
    );
});
const selectedSourcesPaused = computed(
    () =>
        selectedVillageMergeSources.value.length > 0 &&
        selectedVillageMergeSources.value.every((village) =>
            Boolean(village.intake_paused_at),
        ),
);
const selectedSourcesHaveActive = computed(() =>
    selectedVillageMergeSources.value.some(
        (village) => !village.intake_paused_at,
    ),
);

function isAvailableVillageMergeTarget(village: Row): boolean {
    return (
        !village.intake_paused_at &&
        !village.merged_into_code
    );
}

function villageOperationalStatusLabel(status: unknown): string {
    return status === "active" ? "Đang hoạt động" : "Không hoạt động";
}

function changeVillageMergeTarget(): void {
    selectedSourceCodes.value = selectedSourceCodes.value.filter(
        (code) => code !== String(form.value.target_code ?? ""),
    );
}

function clearVillageMergeSelection(): void {
    selectedSourceCodes.value = [];
    form.value.target_code = "";
    villageMergeSourceSearch.value = "";
    error.value = "";
}

async function loadVillageMergeOptions(): Promise<void> {
    const previousTarget = String(form.value.target_code ?? "");
    const previousSources = [...selectedSourceCodes.value];
    const response = await api.get<unknown>("/village-merges");
    rows.value = apiRows<Row>(apiData<unknown>(response.data));
    const availableTargets = rows.value.filter(isAvailableVillageMergeTarget);
    form.value = {
        target_code: availableTargets.some(
            (village) => String(village.code) === previousTarget,
        )
            ? previousTarget
            : "",
        source_codes: "",
    };
    selectedSourceCodes.value = previousSources.filter(
        (code) =>
            code !== form.value.target_code &&
            rows.value.some(
                (village) =>
                    String(village.code) === code &&
                    !village.merged_into_code,
            ),
    );
}

async function changeSelectedVillageIntake(
    action: "pause" | "resume",
): Promise<void> {
    if (selectedSourceCodes.value.length === 0 || villageMergeBusy.value)
        return;
    villageMergeBusy.value = true;
    error.value = "";
    notice.value = "";
    try {
        const response = await api.post<unknown>(`/village-merges/${action}`, {
            source_codes: selectedSourceCodes.value,
        });
        const result = apiData<Row>(response.data);
        deleteResult.value = {
            success: true,
            title:
                action === "pause"
                    ? "Đã tạm dừng nhận phiếu"
                    : "Đã mở lại nhận phiếu",
            message: String(
                result.message ?? "Trạng thái nhận phiếu đã được cập nhật.",
            ),
        };
        await loadVillageMergeOptions();
    } catch (cause) {
        formErrors.value = validationErrors(cause);
        error.value = errorMessage(cause);
    } finally {
        villageMergeBusy.value = false;
    }
}

function formatImportRows(value: unknown): string {
    const count = Number(value ?? 0);
    return Number.isFinite(count)
        ? new Intl.NumberFormat("vi-VN").format(count)
        : "0";
}

function displayImportFileName(value: unknown): string {
    if (typeof value !== "string") return "Không rõ tên tệp";
    const readableName = value
        .replace(/^_?\d{9,}_/, "")
        .replace(/_/g, " ")
        .trim();
    return readableName || value;
}

function importStatusLabel(status: unknown): string {
    if (status === "SUCCESS") return "Thành công";
    if (status === "PARTIAL") return "Hoàn tất một phần";
    if (status === "FAILED") return "Thất bại";
    if (status === "PROCESSING") return "Đang xử lý";
    return "Đang chờ";
}

const MAX_IMPORT_FILES = 5;
const MAX_IMPORT_FILE_BYTES = 20 * 1024 * 1024;
const MAX_IMPORT_BATCH_BYTES = 35 * 1024 * 1024;
const importFiles = ref<File[]>([]);
const importFileError = ref("");
const importFileInput = ref<HTMLInputElement | null>(null);
const importDropActive = ref(false);
const importSubmitAttempted = ref(false);
const importHistory = ref<Row[]>([]);
const importFormLoaded = ref(false);
let importHistoryRefreshActive = false;
let importHistoryRefreshPending = false;
const profile = ref<Row | null>(null);
const selectedSourceCodes = ref<string[]>([]);
const villageMergeBusy = ref(false);
const currentPage = ref(1);
const lastPage = ref(1);
const totalRecords = ref(0);
const userPageSize = ref(10);
const rolePageSize = ref(10);
const roleFilterQuery = ref("");
const villagePageSize = ref(10);
const schoolPageSize = ref(10);
const householdPageSize = ref(10);
const householdDetailPageSize = 15;
const householdEditPageSize = 15;
const householdDetail = ref<Row | null>(null);
const householdDetailReadOnly = ref(false);
const householdDetailFromTrash = ref(false);
const trashedHouseholdPersons = ref<Row[]>([]);
const personDetailFromTrash = ref(false);
const householdListRow = ref<Row | null>(null);
const householdDetailPage = ref(1);
const householdDetailLastPage = ref(1);
const householdDetailTotal = ref(0);
const householdEditPage = ref(1);
const householdEditPersons = ref<Row[]>([]);
const inlineHouseholdPersonBaseline = ref<Record<string, string>>({});
const householdLoading = ref(false);
const householdDetailPageLoading = ref(false);
const selectedHouseholdPerson = ref<Row | null>(null);
const pendingHouseholdPersonDelete = ref<Row | null>(null);
const memberBusy = ref(false);
const memberFormErrors = ref<Record<string, string>>({});
const memberFormSubmitted = ref(false);
const memberEditorForm = ref<HTMLFormElement | null>(null);
const restoringHouseholdMemberDraft = ref(false);
const householdRouteRestoreActive = ref(false);
const householdRouteInitialLoading = ref(
    props.moduleKey === "households" &&
        /^\/households\/\d+\/persons(?:\/create|\/\d+\/edit)?\/?$/.test(
            window.location.pathname,
        ),
);
const householdMemberDraftPrefix = "pcgd:household-member-draft";
const householdEditPageCount = computed(() =>
    Math.max(
        1,
        Math.ceil(householdEditPersons.value.length / householdEditPageSize),
    ),
);
const memberClientValidationErrors = computed<Record<string, string>>(() => {
    if (!memberFormSubmitted.value) return {};

    const errors: Record<string, string> = {};
    const requiredFields: Record<string, string> = {
        last_name: "Vui lòng nhập họ và tên đệm.",
        first_name: "Vui lòng nhập tên.",
        dob: "Vui lòng nhập ngày sinh.",
        gender: "Vui lòng chọn giới tính.",
        ethnicity_code: "Vui lòng chọn dân tộc.",
        "education.school_year": "Không xác định được năm học hiện hành.",
    };
    if (!memberIsHead.value) {
        requiredFields.relationship_with_head =
            "Vui lòng chọn quan hệ với chủ hộ.";
    }

    for (const [field, message] of Object.entries(requiredFields)) {
        const value =
            field === "education.school_year"
                ? educationForm.value.school_year
                : field === "dob"
                  ? memberForm.value.dob
                : memberForm.value[field];
        if (!String(value ?? "").trim()) errors[field] = message;
    }

    const personNamePattern = /^[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u;
    for (const [field, label] of [
        ["last_name", "Họ và tên đệm"],
        ["first_name", "Tên"],
        ["parent_name", "Họ tên cha hoặc mẹ"],
    ]) {
        const value = String(memberForm.value[field] ?? "").trim();
        if (value && !personNamePattern.test(value)) {
            errors[field] =
                `${label} chỉ được chứa chữ cái, khoảng trắng, dấu nháy hoặc dấu gạch nối.`;
        }
    }

    const phone = String(memberForm.value.phone ?? "").trim();
    if (phone && !/^\+?[0-9]+$/.test(phone)) {
        errors.phone =
            "Số điện thoại chỉ gồm chữ số và có thể bắt đầu bằng dấu +, ví dụ +84912345678.";
    }

    const dateOfBirth = String(memberForm.value.dob ?? "");
    const today = new Date();
    const todayString = `${today.getFullYear()}-${String(
        today.getMonth() + 1,
    ).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
    if (
        dateOfBirth &&
        (!/^\d{4}-\d{2}-\d{2}$/.test(dateOfBirth) ||
            Number.isNaN(Date.parse(`${dateOfBirth}T00:00:00`)))
    ) {
        errors.dob = "Ngày sinh không hợp lệ.";
    } else if (dateOfBirth && dateOfBirth > todayString) {
        errors.dob = "Ngày sinh không được lớn hơn ngày hiện tại.";
    }

    if (!memberIsHead.value) {
        const relationshipError = relationshipSlotError(
            memberForm.value.relationship_with_head,
            memberId.value,
        );
        if (relationshipError) {
            errors.relationship_with_head = relationshipError;
        }
    }

    for (const [field, label] of [
        ["current_class", "Lớp học"],
        ["finished_class", "Lớp đã hoàn thành"],
        ["dropped_class", "Lớp đã bỏ học"],
    ]) {
        const value = String(educationForm.value[field] ?? "");
        const gradeMatch = value.match(/^\s*(?:lớp\s*)?(\d+)/iu);
        if (gradeMatch && Number(gradeMatch[1]) > 12) {
            errors[`education.${field}`] =
                `${label} không được lớn hơn lớp 12.`;
        }
    }

    for (const [field, label] of [
        ["graduation_year", "Năm tốt nghiệp"],
        ["vocational_grad_year", "Năm tốt nghiệp nghề"],
        ["finished_year", "Năm hoàn thành lớp"],
        ["dropped_year", "Năm bỏ học"],
    ]) {
        const value = String(educationForm.value[field] ?? "").trim();
        if (!value) continue;
        if (!/^\d{4}(?:-\d{4})?$/.test(value)) {
            errors[`education.${field}`] =
                `${label} phải có dạng YYYY hoặc YYYY-YYYY.`;
        } else if (
            value.split("-").some((year) => Number(year) > today.getFullYear()) &&
            !(
                [
                    "graduation_year",
                    "vocational_grad_year",
                    "finished_year",
                    "dropped_year",
                ].includes(field) &&
                value === `${today.getFullYear()}-${today.getFullYear() + 1}`
            )
        ) {
            errors[`education.${field}`] =
                `${label} không được lớn hơn năm hiện tại.`;
        }
    }

    return errors;
});
watch(householdEditPageCount, (lastPage) => {
    if (householdEditPage.value > lastPage) {
        householdEditPage.value = lastPage;
    }
});
const householdQuery = ref("");
const householdSchoolYear = ref("");
const householdFormSchoolYears = ref<string[]>([]);
const personSchools = ref<Row[]>([]);
const personSchoolOptionsError = ref("");
const personSchoolOptionsLoading = ref(false);
let personSchoolOptionsTimer: number | undefined;
let personSchoolOptionsSequence = 0;
const inlineSchoolOptions = ref<Record<string, Row[]>>({});
const inlineSchoolOptionsLoading = ref<Record<string, boolean>>({});
const inlineSchoolOptionsErrors = ref<Record<string, string>>({});
const inlineSchoolRequests = new Map<string, Promise<void>>();
const disabilityForm = ref<Record<string, string | boolean>>({});
const householdSchoolYearOptions = Array.from({ length: 11 }, (_, index) => {
    const year = 2020 + index;
    return `${year}-${year + 1}`;
});
function currentSchoolYearValue(): string {
    const now = new Date();
    const year = now.getFullYear();
    const firstYear = now.getMonth() + 1 >= 8 ? year : year - 1;
    return `${firstYear}-${firstYear + 1}`;
}

const memberSchoolYearOptions = Array.from(
    { length: Math.max(1, new Date().getFullYear() - 2019) },
    (_, index) => {
        const year = 2020 + index;
        return `${year}-${year + 1}`;
    },
);
type HouseholdDatePickerTarget =
    | { kind: "member" }
    | { kind: "inline"; person: Row };
type HouseholdDatePickerState = {
    target: HouseholdDatePickerTarget;
    year: number;
    month: number;
    left: number;
    top: number;
};
const householdDatePicker = ref<HouseholdDatePickerState | null>(null);
const householdDatePickerWeekdays = ["T2", "T3", "T4", "T5", "T6", "T7", "CN"];
const householdDatePickerMonths = Array.from({ length: 12 }, (_, month) =>
    new Intl.DateTimeFormat("vi-VN", {
        month: "long",
        timeZone: "UTC",
    }).format(new Date(Date.UTC(2020, month, 1))),
);
const householdDatePickerYears = computed(() =>
    Array.from(
        { length: new Date().getFullYear() - 1899 },
        (_, index) => new Date().getFullYear() - index,
    ),
);
const householdDatePickerDays = computed(() => {
    const picker = householdDatePicker.value;
    if (!picker) return [];

    const firstWeekday =
        (new Date(Date.UTC(picker.year, picker.month, 1)).getUTCDay() + 6) % 7;
    const daysInMonth = new Date(
        Date.UTC(picker.year, picker.month + 1, 0),
    ).getUTCDate();
    return [
        ...Array.from({ length: firstWeekday }, () => null),
        ...Array.from({ length: daysInMonth }, (_, index) => index + 1),
    ];
});
const memberRelationships = [
    "Vợ",
    "Chồng",
    "Con",
    "Cha",
    "Mẹ",
    "Anh",
    "Chị",
    "Em",
    "Ông",
    "Bà",
    "Cháu",
    "Khác",
];
const relationshipSlotGroups = [
    { relationships: ["Vợ", "Chồng"], label: "vợ hoặc chồng" },
    { relationships: ["Cha", "Mẹ"], label: "cha hoặc mẹ" },
];
const memberDisabilityOptions = [
    { key: "mobility_disability", label: "Khuyết tật vận động" },
    { key: "hearing_speech_disability", label: "Khuyết tật nghe / nói" },
    { key: "visual_disability", label: "Khuyết tật thị giác" },
    { key: "mental_disability", label: "Khuyết tật tâm thần" },
    { key: "intellectual_disability", label: "Khuyết tật trí tuệ" },
    { key: "learning_disability", label: "Khuyết tật học tập" },
    { key: "autism", label: "Tự kỷ" },
    { key: "other_disability", label: "Khuyết tật khác" },
    { key: "has_disability_cert", label: "Có giấy xác nhận khuyết tật" },
];
const memberEditorOpen = ref(false);
const createHouseholdWizardStep = ref(1);
const createdHouseholdId = ref<number | null>(null);
const memberId = ref<number | null>(null);
const memberIsHead = ref(false);
const headMemberEducationStep = ref(false);
const memberForm = ref<Record<string, string>>({});
const ethnicities = ref<Row[]>([]);
const educationForm = ref<Record<string, string>>({});
function createEducationYearStart(field: string) {
    return computed({
        get(): string {
            const value = String(educationForm.value[field] ?? "");
            return value.match(/^(\d{4})(?:-\d{4})?$/)?.[1] ?? value;
        },
        set(value: string): void {
            const year = value.trim();
            educationForm.value[field] = /^\d{4}$/.test(year)
                ? `${year}-${Number(year) + 1}`
                : year;
        },
    });
}
function educationYearEnd(field: string): string {
    const value = String(educationForm.value[field] ?? "");
    const range = value.match(/^\d{4}-(\d{4})$/);
    if (range) return range[1];

    return /^\d{4}$/.test(value) ? String(Number(value) + 1) : "";
}
const graduationYearStart = createEducationYearStart("graduation_year");
const vocationalGraduationYearStart = createEducationYearStart(
    "vocational_grad_year",
);
const finishedYearStart = createEducationYearStart("finished_year");
const droppedYearStart = createEducationYearStart("dropped_year");

const moduleInfo: Record<
    ModuleKey,
    { title: string; endpoint: string; columns: string[]; fields: string[] }
> = {
    households: {
        title: "Phiếu điều tra",
        endpoint: "/households",
        columns: [
            "household_code",
            "head_name",
            "province_name",
            "commune_name",
            "village_name",
            "members_count",
        ],
        fields: [
            "household_code",
            "school_year",
            "head_last_name",
            "head_first_name",
            "province_code",
            "commune_code",
            "village_code",
            "address",
            "residence_type",
            "residence_status",
        ],
    },
    schools: {
        title: "Trường học",
        endpoint: "/schools",
        columns: ["name", "code", "province.name", "commune.name", "is_active"],
        fields: [
            "name",
            "type",
            "level",
            "province_code",
            "commune_code",
            "is_active",
        ],
    },
    villages: {
        title: "Danh mục Thôn / Xóm / Tổ dân phố",
        endpoint: "/villages",
        columns: [
            "name",
            "code",
            "commune.province.name",
            "commune.name",
            "operational_status",
        ],
        fields: ["name", "province_code", "commune_code", "operational_status"],
    },
    roles: {
        title: "Nhóm người dùng",
        endpoint: "/roles",
        columns: ["name", "description", "scope_level", "users_count"],
        fields: ["name", "description", "scope_level"],
    },
    users: {
        title: "Tài khoản",
        endpoint: "/users",
        columns: ["name", "username", "email", "phone", "role_name"],
        fields: [
            "name",
            "username",
            "citizen_id",
            "date_of_birth",
            "email",
            "phone",
            "scope_level",
            "role_id",
            "province_code",
            "commune_code",
            "is_active",
            "village_codes",
        ],
    },
    account: {
        title: "Tài khoản của tôi",
        endpoint: "/account",
        columns: [],
        fields: [],
    },
    imports: {
        title: "Nhập / xuất Excel",
        endpoint: "/household-imports",
        columns: [],
        fields: [],
    },
    trash: {
        title: "Thùng rác",
        endpoint: "/trash",
        columns: ["item_type", "deleted_at", "record.household_code"],
        fields: [],
    },
    merge: {
        title: "Gộp thôn / xóm",
        endpoint: "/village-merges",
        columns: [],
        fields: ["target_code", "source_codes"],
    },
};

const info = computed(() => moduleInfo[props.moduleKey]);
const editing = computed(() => selected.value !== null);
const isCompactManagement = computed(() =>
    ["users", "roles", "trash"].includes(props.moduleKey),
);
const isDialogForm = computed(() =>
    ["users", "roles", "villages", "schools", "households"].includes(
        props.moduleKey,
    ),
);
const isHouseholdCreatePage = computed(
    () => props.moduleKey === "households" && !editing.value,
);
const isHouseholdCreateContinuationPage = computed(
    () =>
        isHouseholdCreatePage.value &&
        createdHouseholdId.value !== null &&
        [2, 3].includes(createHouseholdWizardStep.value),
);
const isHouseholdWizardMemberPage = computed(
    () => isHouseholdCreateContinuationPage.value && memberEditorOpen.value,
);
const isNewHouseholdHead = computed(
    () => isHouseholdWizardMemberPage.value && memberIsHead.value,
);
const householdCreationPageActive = computed(
    () =>
        isHouseholdCreatePage.value &&
        (configurationFormOpen.value ||
            isHouseholdCreateContinuationPage.value),
);
const showUserCommuneFilter = computed(
    () =>
        currentActorScopeLevel.value === "province" &&
        userFilterCommunes.value.length > 0,
);
const showUserVillageFilter = computed(
    () =>
        currentActorScopeLevel.value === "commune" &&
        userFilterVillages.value.length > 0,
);
const showUserProvinceFilter = computed(
    () =>
        currentActorScopeLevel.value === "global" && provinces.value.length > 0,
);
const showSchoolProvinceFilter = computed(() => provinces.value.length > 1);
const showSchoolCommuneFilter = computed(
    () => schoolFilterCommunes.value.length > 1,
);
const hasConfigurationForm = computed(() =>
    isDialogForm.value
        ? configurationFormOpen.value
        : selected.value !== null ||
          (["households", "schools", "villages", "merge"].includes(
              props.moduleKey,
          ) &&
              Object.keys(form.value).length > 0),
);
const scopeLevelOptions = computed(() =>
    props.moduleKey === "roles" ? roleScopeLevels.value : accountLevels.value,
);
const assignableUserRoles = computed(() => {
    const level = form.value.scope_level;
    if (!level) return [];

    return roleOptions.value.filter(
        (role) =>
            Array.isArray(role.assignable_scope_levels) &&
            role.assignable_scope_levels.includes(level),
    );
});
const selectedRoleIsCommuneAdmin = computed(() =>
    assignableUserRoles.value.some(
        (role) =>
            String(role.id) === form.value.role_id &&
            role.scope_level === "commune" &&
            role.is_primary_admin === true,
    ),
);
const fieldsForForm = computed(() => {
    const fields = [...info.value.fields];
    const selectedRole = assignableUserRoles.value.find(
        (role) => String(role.id) === form.value.role_id,
    );

    if (
        props.moduleKey !== "users" ||
        form.value.scope_level !== "province" ||
        selectedRole?.is_primary_admin !== true
    ) {
        return fields;
    }

    const provinceFieldIndex = fields.indexOf("province_code");
    const roleFieldIndex = fields.indexOf("role_id");
    if (provinceFieldIndex !== -1 && roleFieldIndex !== -1) {
        fields.splice(provinceFieldIndex, 1);
        fields.splice(fields.indexOf("role_id") + 1, 0, "province_code");
    }

    return fields;
});
const selectedRoleManagesAllVillages = computed(() =>
    assignableUserRoles.value.some(
        (role) =>
            String(role.id) === form.value.role_id &&
            role.manages_all_villages === true,
    ),
);
const selectedRoleUsesVillageScope = computed(() =>
    assignableUserRoles.value.some(
        (role) =>
            String(role.id) === form.value.role_id &&
            role.uses_village_scope === true,
    ),
);
const primaryAdminUnavailableMessage = computed(() => {
    if (props.moduleKey !== "users" || !form.value.scope_level) return "";

    const hasPrimaryAdminRole = assignableUserRoles.value.some(
        (role) =>
            role.is_primary_admin === true &&
            role.scope_level === form.value.scope_level,
    );
    if (
        !hasPrimaryAdminRole ||
        availablePrimaryAdminScopes.value[form.value.scope_level] !== false
    )
        return "";

    return form.value.scope_level === "province"
        ? "Đã đủ người quản trị tỉnh; không thể chọn thêm nhóm quyền Quản trị tỉnh."
        : "Đã đủ người quản trị xã; không thể chọn thêm nhóm quyền Quản trị xã.";
});
const userFormValidationErrors = computed<Record<string, string>>(() => {
    if (props.moduleKey !== "users") return {};

    const errors: Record<string, string> = {};
    const requiredFields: Record<string, string> = {
        name: "Vui lòng nhập họ và tên.",
        username: "Vui lòng nhập tên đăng nhập.",
        citizen_id: "Vui lòng nhập số CCCD gồm 12 chữ số.",
        role_id: "Vui lòng chọn nhóm quyền.",
        scope_level: "Vui lòng chọn cấp quản lý.",
    };
    for (const [field, message] of Object.entries(requiredFields)) {
        if (!form.value[field]?.trim()) errors[field] = message;
    }
    if ([...(form.value.name ?? "")].length > 255) {
        errors.name = "Họ và tên không được vượt quá 255 ký tự.";
    }
    const username = form.value.username ?? "";
    const unchangedLegacyUsername =
        editing.value &&
        username === String(selected.value?.username ?? "") &&
        username.length > 12;
    if (
        username.trim() &&
        !unchangedLegacyUsername &&
        (username.length < 7 || username.length > 12)
    ) {
        errors.username = "Tên đăng nhập phải có từ 7 đến 12 ký tự.";
    }
    if (
        form.value.citizen_id?.trim() &&
        !/^\d{12}$/.test(form.value.citizen_id.trim())
    ) {
        errors.citizen_id = "Số CCCD phải gồm đúng 12 chữ số.";
    }
    if (form.value.phone?.trim() && !/^\+?\d+$/.test(form.value.phone.trim())) {
        errors.phone = "Số điện thoại chỉ được chứa chữ số hoặc dấu + ở đầu.";
    }
    if ([...(form.value.phone ?? "")].length > 20) {
        errors.phone = "Số điện thoại không được vượt quá 20 ký tự.";
    }
    const email = form.value.email?.trim() ?? "";
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.email = "Email không đúng định dạng.";
    }
    if ([...email].length > 255) {
        errors.email = "Email không được vượt quá 255 ký tự.";
    }
    if (
        form.value.date_of_birth &&
        form.value.date_of_birth > latestBirthDate
    ) {
        errors.date_of_birth = "Ngày sinh không được lớn hơn ngày hiện tại.";
    }

    if (form.value.scope_level !== "global" && !form.value.province_code) {
        errors.province_code = "Vui lòng chọn tỉnh/thành phố.";
    }
    if (form.value.scope_level === "commune" && !form.value.commune_code) {
        errors.commune_code = "Vui lòng chọn xã/phường.";
    }
    const passwordValue = form.value.password ?? "";
    if (!editing.value && !passwordValue) {
        errors.password = "Vui lòng nhập mật khẩu.";
    } else if (passwordValue && passwordValue.length < 12) {
        errors.password = "Mật khẩu phải có từ 12 đến 18 ký tự.";
    } else if (passwordValue.length > 18) {
        errors.password = "Mật khẩu không được vượt quá 18 ký tự.";
    }
    if (
        (!editing.value || passwordValue) &&
        passwordValue !== (form.value.password_confirmation ?? "")
    ) {
        errors.password_confirmation = form.value.password_confirmation
            ? "Mật khẩu xác nhận chưa khớp."
            : "Vui lòng nhập mật khẩu xác nhận.";
    }

    if (
        form.value.role_id &&
        !assignableUserRoles.value.some(
            (role) => String(role.id) === form.value.role_id,
        )
    ) {
        errors.role_id = "Nhóm quyền không phù hợp với cấp quản lý đã chọn.";
    }

    if (form.value.role_id) {
        const selectedRole = assignableUserRoles.value.find(
            (role) => String(role.id) === form.value.role_id,
        );
        if (
            selectedRole?.is_primary_admin === true &&
            availablePrimaryAdminScopes.value[form.value.scope_level] === false
        ) {
            errors.role_id = primaryAdminUnavailableMessage.value;
        }
    }

    return errors;
});

function sanitizeUserNumericInput(event: Event, field: string): void {
    if (
        props.moduleKey !== "users" ||
        !["phone", "citizen_id"].includes(field)
    ) {
        return;
    }
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const hasInternationalPrefix =
        field === "phone" && input.value.trimStart().startsWith("+");
    const digits = input.value.replace(/\D/g, "");
    input.value = hasInternationalPrefix ? `+${digits}` : digits;
    form.value[field] = input.value;
}

function formFieldMaxLength(field: string): number | undefined {
    if (field === "citizen_id") return 12;
    if (props.moduleKey === "users") {
        if (field === "name" || field === "email") return 255;
        if (field === "phone") return 20;
        if (field === "username") {
            const existingUsername = String(selected.value?.username ?? "");
            return editing.value &&
                existingUsername === form.value.username &&
                existingUsername.length > 12
                ? existingUsername.length
                : 12;
        }
    }
    if (props.moduleKey === "roles") {
        if (field === "name") return 50;
        if (field === "description") return 255;
    }

    return undefined;
}

const userFormReady = computed(
    () =>
        props.moduleKey !== "users" ||
        (!formOptionsBusy.value &&
            Object.keys(userFormValidationErrors.value).length === 0),
);
const villageFormReady = computed(
    () =>
        props.moduleKey !== "villages" ||
        Boolean(
            form.value.name?.trim() &&
            form.value.province_code &&
            form.value.commune_code &&
            form.value.operational_status,
        ),
);
const householdFormValidationErrors = computed<Record<string, string>>(() => {
    if (props.moduleKey !== "households") return {};

    const errors: Record<string, string> = {};
    const requiredFields: Record<string, string> = {
        school_year: "Vui lòng chọn năm học.",
        head_last_name: "Vui lòng nhập họ chủ hộ.",
        head_first_name: "Vui lòng nhập tên chủ hộ.",
        province_code: "Vui lòng chọn tỉnh / thành phố.",
        commune_code: "Vui lòng chọn xã / phường.",
        village_code: "Vui lòng chọn thôn / xóm.",
        residence_type: "Vui lòng chọn loại cư trú.",
    };
    if (editing.value) {
        requiredFields.household_code = "Vui lòng nhập mã phiếu.";
    }

    for (const [field, message] of Object.entries(requiredFields)) {
        if (!String(form.value[field] ?? "").trim()) {
            errors[field] = message;
        }
    }

    return errors;
});
const villageFormValidationErrors = computed<Record<string, string>>(() => {
    if (props.moduleKey !== "villages") return {};

    const errors: Record<string, string> = {};
    if (!String(form.value.name ?? "").trim())
        errors.name = "Vui lòng nhập tên thôn / xóm.";
    if (!form.value.province_code)
        errors.province_code = "Vui lòng chọn tỉnh / thành phố.";
    if (!form.value.commune_code)
        errors.commune_code = "Vui lòng chọn xã / phường.";
    if (!form.value.operational_status)
        errors.operational_status = "Vui lòng chọn trạng thái.";
    return errors;
});
const schoolFormReady = computed(
    () =>
        props.moduleKey !== "schools" ||
        Boolean(
            form.value.name?.trim() &&
            form.value.type &&
            form.value.level &&
            form.value.is_active !== "" &&
            form.value.is_active !== null &&
            form.value.is_active !== undefined &&
            form.value.province_code &&
            form.value.commune_code,
        ),
);
const schoolFormValidationErrors = computed<Record<string, string>>(() => {
    if (props.moduleKey !== "schools") return {};

    const errors: Record<string, string> = {};
    if (!String(form.value.name ?? "").trim())
        errors.name = "Vui lòng nhập tên trường.";
    if (!form.value.type) errors.type = "Vui lòng chọn loại trường.";
    if (!form.value.level) errors.level = "Vui lòng chọn cấp học.";
    if (!form.value.province_code)
        errors.province_code = "Vui lòng chọn tỉnh / thành phố.";
    if (!form.value.commune_code)
        errors.commune_code = "Vui lòng chọn xã / phường.";
    if (!form.value.is_active) {
        errors.is_active = "Vui lòng chọn trạng thái hoạt động.";
    }
    return errors;
});
const roleFormValidationErrors = computed<Record<string, string>>(() => {
    if (props.moduleKey !== "roles") return {};

    const errors: Record<string, string> = {};
    if (!form.value.name?.trim())
        errors.name = "Vui lòng nhập tên nhóm người dùng.";
    else if ([...form.value.name].length > 50)
        errors.name = "Tên nhóm người dùng không được vượt quá 50 ký tự.";
    if ([...(form.value.description ?? "")].length > 255) {
        errors.description = "Mô tả không được vượt quá 255 ký tự.";
    }
    if (!form.value.scope_level)
        errors.scope_level = "Vui lòng chọn cấp quản lý.";
    if (permissionIds.value.length === 0) {
        errors.permissions = "Chọn ít nhất một quyền cho nhóm người dùng.";
    }
    return errors;
});
const roleFormReady = computed(
    () =>
        props.moduleKey !== "roles" ||
        Object.keys(roleFormValidationErrors.value).length === 0,
);
const rolePermissionError = computed(
    () =>
        Object.entries(formErrors.value).find(
            ([field]) =>
                field === "permissions" ||
                field === "permission_groups" ||
                field.startsWith("permissions."),
        )?.[1] ?? "",
);
const sectionDescription = computed(
    () =>
        ({
            households:
                "Danh sách phiếu điều tra trong địa bàn bạn được phân quyền.",
            schools: "Danh mục trường học phục vụ công tác phổ cập giáo dục.",
            villages: "Danh mục thôn, xóm thuộc địa bàn quản lý.",
            roles: "Nhóm quyền và phạm vi quản lý được cấp cho người dùng.",
            users: "Tài khoản và phạm vi địa bàn được phân công.",
            account: "Thông tin đăng nhập và bảo mật tài khoản của bạn.",
            imports: "Nhập dữ liệu từ tệp Excel hoặc tải dữ liệu đang quản lý.",
            trash: "Các dữ liệu đã xóa có thể được khôi phục hoặc xóa vĩnh viễn.",
            merge: "Gộp các thôn, xóm vào một địa bàn đích.",
        })[props.moduleKey],
);
const fieldLabels: Record<string, string> = {
    household_code: "Mã phiếu",
    school_year: "Năm học",
    head_name: "Chủ hộ",
    head_first_name: "Tên chủ hộ",
    head_last_name: "Họ chủ hộ",
    first_name: "Tên",
    last_name: "Họ và tên đệm",
    dob: "Ngày sinh",
    gender: "Giới tính",
    ethnicity_code: "Dân tộc",
    relationship_with_head: "Quan hệ với chủ hộ",
    religion: "Tôn giáo",
    priority_type: "Diện ưu tiên",
    parent_name: "Tên cha / mẹ",
    note: "Ghi chú",
    academic_block: "Khối",
    current_class: "Lớp hiện tại",
    school_code: "Trường học",
    graduation_level: "Trình độ phổ cập",
    graduation_year: "Năm hoàn thành",
    province_name: "Tỉnh / thành phố",
    commune_name: "Xã / phường",
    village_name: "Thôn / xóm",
    operational_status: "Trạng thái",
    target_code: "Thôn đích",
    province_code: "Tỉnh / thành phố",
    commune_code: "Xã / phường",
    village_code: "Thôn / xóm",
    address: "Địa chỉ",
    residence_type: "Loại cư trú",
    residence_status: "Tình trạng cư trú",
    members_count: "Nhân khẩu",
    code: "Mã",
    name: "Tên",
    type: "Loại trường",
    level: "Cấp học",
    description: "Mô tả",
    scope_level: "Cấp quản lý",
    users_count: "Thành viên",
    username: "Tên đăng nhập",
    citizen_id: "Số CCCD",
    date_of_birth: "Ngày sinh",
    email: "Gmail",
    phone: "Số điện thoại",
    role_name: "Nhóm người dùng",
    role_id: "Nhóm quyền",
    password: "Mật khẩu",
    password_confirmation: "Nhập lại mật khẩu",
    village_codes: "Giới hạn dữ liệu theo thôn/xóm (không chọn = toàn xã)",
    is_active: "Trạng thái hoạt động",
    item_type: "Loại dữ liệu",
    deleted_at: "Ngày xóa",
    "record.household_code": "Mã phiếu",
    "province.name": "Tỉnh / thành phố",
    "commune.name": "Xã / phường",
    "commune.province.name": "Tỉnh / thành phố",
};
const schoolLevelLabels: Record<string, string> = {
    MN: "Mầm non",
    TH: "Tiểu học",
    THCS: "Trung học cơ sở",
    THPT: "Trung học phổ thông",
};
function schoolLevelSummary(row: Row): string {
    const level = String(row.level ?? "");
    if (row.type !== "multi_level") return level;
    if (level === "MN") return level;

    const generalLevels = ["TH", "THCS", "THPT"];
    const levelIndex = generalLevels.indexOf(level);
    return levelIndex < 0
        ? level
        : generalLevels
              .slice(0, levelIndex + 1)
              .reverse()
              .join(", ");
}
const scopeLabels: Record<string, string> = {
    global: "Trung ương",
    province: "Cấp tỉnh",
    commune: "Cấp xã",
};
const permissionLabels: Record<string, string> = {
    "view-households": "Xem phiếu điều tra",
    "create-households": "Tạo phiếu điều tra",
    "update-households": "Cập nhật phiếu điều tra",
    "delete-households": "Xóa phiếu điều tra",
    "import-households": "Nhập dữ liệu phiếu",
    "export-households": "Xuất dữ liệu phiếu",
    "manage-villages": "Quản lý thôn / xóm",
    "manage-schools": "Quản lý trường học",
    "manage-access-control": "Quản lý nhóm người dùng",
    "manage-user-accounts": "Quản lý tài khoản",
    "view-organization-accounts": "Xem cây đơn vị",
    "view-users": "Xem tài khoản",
    "create-users": "Tạo tài khoản",
    "update-users": "Sửa tài khoản, đặt lại mật khẩu",
    "delete-users": "Thu hồi tài khoản",
    "view-roles": "Xem nhóm người dùng",
    "create-roles": "Tạo nhóm người dùng",
    "update-roles": "Sửa nhóm người dùng",
    "delete-roles": "Xóa nhóm người dùng",
    "view-schools": "Xem danh mục trường học",
    "create-schools": "Thêm trường học",
    "update-schools": "Sửa trường học",
    "delete-schools": "Xóa trường học",
    "view-school-merges": "Xem lịch sử gộp trường học",
    "create-school-merges": "Gộp trường học",
    "view-villages": "Xem danh mục thôn / xóm",
    "create-villages": "Thêm thôn / xóm",
    "update-villages": "Sửa thôn / xóm",
    "delete-villages": "Xóa thôn / xóm",
    "view-village-merges": "Xem lịch sử gộp thôn / xóm",
    "create-village-merges": "Gộp thôn / xóm",
    "view-imports": "Xem lịch sử nhập dữ liệu",
    "create-imports": "Nhập dữ liệu",
    "delete-imports": "Xóa lịch sử nhập dữ liệu",
    "view-exports": "Xem và tải dữ liệu xuất",
    "create-exports": "Xuất dữ liệu",
    "view-trash": "Xem thùng rác",
    "update-trash": "Khôi phục dữ liệu",
    "delete-trash": "Xóa dữ liệu vĩnh viễn",
};
const permissionGroups = [
    {
        key: "accounts",
        label: "Tài khoản",
        permissions: [
            "manage-user-accounts",
            "view-users",
            "create-users",
            "update-users",
            "delete-users",
            "view-organization-accounts",
        ],
    },
    {
        key: "roles",
        label: "Nhóm người dùng",
        permissions: [
            "manage-access-control",
            "view-roles",
            "create-roles",
            "update-roles",
            "delete-roles",
        ],
    },
    {
        key: "villages",
        label: "Thôn / xóm",
        permissions: [
            "manage-villages",
            "view-villages",
            "create-villages",
            "update-villages",
            "delete-villages",
            "view-village-merges",
            "create-village-merges",
        ],
    },
    {
        key: "schools",
        label: "Trường học",
        permissions: [
            "manage-schools",
            "view-schools",
            "create-schools",
            "update-schools",
            "delete-schools",
            "view-school-merges",
            "create-school-merges",
        ],
    },
    {
        key: "households",
        label: "Phiếu điều tra",
        permissions: [
            "view-households",
            "create-households",
            "update-households",
            "delete-households",
        ],
    },
    {
        key: "imports",
        label: "Nhập dữ liệu",
        permissions: [
            "import-households",
            "view-imports",
            "create-imports",
            "delete-imports",
        ],
    },
    {
        key: "exports",
        label: "Xuất dữ liệu",
        permissions: ["export-households", "view-exports", "create-exports"],
    },
    {
        key: "trash",
        label: "Thùng rác",
        permissions: ["view-trash", "update-trash", "delete-trash"],
    },
];
const permissionsByScope: Record<string, string[]> = {
    global: [
        "view-households",
        "create-households",
        "update-households",
        "delete-households",
        "import-households",
        "export-households",
        "manage-access-control",
        "manage-user-accounts",
        "view-organization-accounts",
        "view-users",
        "create-users",
        "update-users",
        "delete-users",
        "view-roles",
        "create-roles",
        "update-roles",
        "delete-roles",
        "view-schools",
        "create-schools",
        "update-schools",
        "delete-schools",
        "view-villages",
        "create-villages",
        "update-villages",
        "delete-villages",
        "view-imports",
        "create-imports",
        "delete-imports",
        "view-exports",
        "create-exports",
        "view-trash",
        "update-trash",
        "delete-trash",
    ],
    province: [
        "manage-schools",
        "manage-access-control",
        "manage-user-accounts",
        "view-users",
        "create-users",
        "update-users",
        "delete-users",
        "view-roles",
        "create-roles",
        "update-roles",
        "delete-roles",
        "view-schools",
        "create-schools",
        "update-schools",
        "delete-schools",
        "view-school-merges",
        "create-school-merges",
    ],
    commune: [
        "view-households",
        "create-households",
        "update-households",
        "delete-households",
        "import-households",
        "export-households",
        "manage-villages",
        "manage-schools",
        "manage-access-control",
        "manage-user-accounts",
        "view-users",
        "create-users",
        "update-users",
        "delete-users",
        "view-roles",
        "create-roles",
        "update-roles",
        "delete-roles",
        "view-schools",
        "create-schools",
        "update-schools",
        "delete-schools",
        "view-school-merges",
        "create-school-merges",
        "view-villages",
        "create-villages",
        "update-villages",
        "delete-villages",
        "view-village-merges",
        "create-village-merges",
        "view-imports",
        "create-imports",
        "delete-imports",
        "view-exports",
        "create-exports",
        "view-trash",
        "update-trash",
        "delete-trash",
    ],
};
const permissionMatrix: PermissionMatrixNode[] = [
    {
        key: "system",
        label: "Hệ thống",
        children: [
            {
                key: "organization",
                label: "Thông tin đơn vị",
                actions: { view: ["view-organization-accounts"] },
            },
            {
                key: "users",
                label: "Tài khoản & phân quyền",
                actions: {
                    view: ["view-users"],
                    create: ["create-users"],
                    update: ["update-users"],
                    delete: ["delete-users"],
                },
            },
            {
                key: "roles",
                label: "Nhóm người dùng",
                actions: {
                    view: ["view-roles"],
                    create: ["create-roles"],
                    update: ["update-roles"],
                    delete: ["delete-roles"],
                },
            },
        ],
    },
    {
        key: "catalog",
        label: "Danh mục",
        children: [
            {
                key: "villages",
                label: "Thôn / xóm",
                actions: {
                    view: ["view-villages"],
                    create: ["create-villages"],
                    update: ["update-villages"],
                    delete: ["delete-villages"],
                },
            },
            {
                key: "schools",
                label: "Trường học",
                actions: {
                    view: ["view-schools"],
                    create: ["create-schools"],
                    update: ["update-schools"],
                    delete: ["delete-schools"],
                },
            },
        ],
    },
    {
        key: "business",
        label: "Nghiệp vụ",
        children: [
            {
                key: "households",
                label: "Phiếu điều tra",
                actions: {
                    view: ["view-households"],
                    create: ["create-households"],
                    update: ["update-households"],
                    delete: ["delete-households"],
                },
            },
            {
                key: "imports",
                label: "Import từ Excel",
                actions: {
                    view: ["view-imports"],
                    create: ["create-imports"],
                    delete: ["delete-imports"],
                },
            },
            {
                key: "exports",
                label: "Xuất Excel",
                actions: { view: ["view-exports"], create: ["create-exports"] },
            },
        ],
    },
    {
        key: "special",
        label: "Tiện ích",
        children: [
            {
                key: "village-merges",
                label: "Gộp thôn / xóm",
                actions: {
                    view: ["view-village-merges"],
                    create: ["create-village-merges"],
                },
            },
            {
                key: "school-merges",
                label: "Gộp trường học",
                actions: {
                    view: ["view-school-merges"],
                    create: ["create-school-merges"],
                },
            },
            {
                key: "trash",
                label: "Thùng rác",
                actions: {
                    view: ["view-trash"],
                    update: ["update-trash"],
                    delete: ["delete-trash"],
                },
            },
        ],
    },
];
const permissionMatrixActions: PermissionAction[] = [
    "view",
    "create",
    "update",
    "delete",
];
const permissionMatrixActionLabels: Record<PermissionAction, string> = {
    view: "Xem",
    create: "Thêm",
    update: "Sửa",
    delete: "Xóa",
};
const legacyPermissionAliases: Record<string, string[]> = {
    "manage-user-accounts": [
        "view-users",
        "create-users",
        "update-users",
        "delete-users",
        "manage-access-control",
        "view-roles",
        "create-roles",
        "update-roles",
        "delete-roles",
    ],
    "manage-access-control": [
        "view-roles",
        "create-roles",
        "update-roles",
        "delete-roles",
    ],
    "manage-villages": [
        "view-villages",
        "create-villages",
        "update-villages",
        "delete-villages",
        "view-village-merges",
        "create-village-merges",
    ],
    "manage-schools": [
        "view-schools",
        "create-schools",
        "update-schools",
        "delete-schools",
        "view-school-merges",
        "create-school-merges",
    ],
    "import-households": ["view-imports", "create-imports", "delete-imports"],
    "export-households": ["view-exports", "create-exports"],
    "delete-households": ["view-trash", "update-trash", "delete-trash"],
};
const matrixOptionsByName = computed(() => {
    const allowed = new Set(permissionsByScope[form.value.scope_level] ?? []);
    return new Map(
        permissionOptions.value
            .filter((option) => allowed.has(permissionName(option)))
            .map((option) => [permissionName(option), String(option.id)]),
    );
});
const permissionMatrixRows = computed(() => {
    const optionIds = matrixOptionsByName.value;
    const visit = (
        node: PermissionMatrixNode,
        depth: number,
    ): PermissionMatrixRow[] => {
        const childRows = (node.children ?? [])
            .map((child) => visit(child, depth + 1))
            .flat();
        const ownActions = Object.fromEntries(
            permissionMatrixActions.map((action) => [
                action,
                (node.actions?.[action] ?? []).filter((name) =>
                    optionIds.has(name),
                ),
            ]),
        ) as Partial<Record<PermissionAction, string[]>>;
        const ownNames = permissionMatrixActions.flatMap(
            (action) => ownActions[action] ?? [],
        );
        const names = [
            ...new Set([
                ...ownNames,
                ...childRows.flatMap((row) => row.permissionNames),
            ]),
        ];
        if (names.length === 0) return childRows;

        const row: PermissionMatrixRow = {
            key: node.key,
            label: node.label,
            depth,
            section: Boolean(node.children?.length),
            actions: Object.fromEntries(
                permissionMatrixActions.map((action) => [
                    action,
                    [
                        ...new Set([
                            ...(ownActions[action] ?? []),
                            ...childRows.flatMap(
                                (child) => child.actions[action] ?? [],
                            ),
                        ]),
                    ],
                ]),
            ) as Partial<Record<PermissionAction, string[]>>,
            permissionNames: names,
        };
        return [row, ...childRows];
    };

    return permissionMatrix.flatMap((section) => visit(section, 0));
});
const permissionMatrixOptionIds = computed(() => [
    ...matrixOptionsByName.value.values(),
]);
const selectedPermissionNames = computed(() => {
    const optionNamesById = new Map(
        [...matrixOptionsByName.value.entries()].map(([name, id]) => [
            id,
            name,
        ]),
    );
    return new Set(
        permissionIds.value
            .map((id) => optionNamesById.get(id))
            .filter((name): name is string => Boolean(name)),
    );
});
const permissionMatrixOverallState = computed(() => {
    const total = permissionMatrixOptionIds.value.length;
    const selected = permissionMatrixOptionIds.value.filter((id) =>
        permissionIds.value.includes(id),
    ).length;
    return {
        checked: total > 0 && selected === total,
        indeterminate: selected > 0 && selected < total,
    };
});
function matrixPermissionSources(name: string): string[] {
    return [
        name,
        ...Object.entries(legacyPermissionAliases)
            .filter(([, aliases]) => aliases.includes(name))
            .map(([legacy]) => legacy),
    ];
}
function matrixRowState(row: PermissionMatrixRow): {
    selected: number;
    total: number;
    checked: boolean;
    indeterminate: boolean;
} {
    const names = [
        ...new Set(
            row.permissionNames
                .flatMap(matrixPermissionSources)
                .filter((name) => matrixOptionsByName.value.has(name)),
        ),
    ];
    const selected = names.filter((name) =>
        selectedPermissionNames.value.has(name),
    ).length;
    return {
        selected,
        total: names.length,
        checked: names.length > 0 && selected === names.length,
        indeterminate: selected > 0 && selected < names.length,
    };
}
function matrixActionState(
    row: PermissionMatrixRow,
    action: PermissionAction,
): boolean {
    return (row.actions[action] ?? []).some((name) =>
        matrixPermissionSources(name).some((source) =>
            selectedPermissionNames.value.has(source),
        ),
    );
}
function setMatrixPermissions(names: string[], checked: boolean): void {
    const availableNames = matrixOptionsByName.value;
    const selectedNames = new Set<string>();

    for (const id of permissionIds.value) {
        const name = [...availableNames.entries()].find(
            ([, optionId]) => optionId === id,
        )?.[0];
        if (!name) continue;
        const expanded = legacyPermissionAliases[name] ?? [name];
        expanded.forEach((permission) => {
            if (availableNames.has(permission)) selectedNames.add(permission);
        });
    }

    names.forEach((name) => {
        if (checked) selectedNames.add(name);
        else selectedNames.delete(name);
    });
    permissionIds.value = [...selectedNames]
        .map((name) => availableNames.get(name))
        .filter((id): id is string => Boolean(id));
}
function toggleMatrixRow(row: PermissionMatrixRow, checked: boolean): void {
    setMatrixPermissions(row.permissionNames, checked);
}
function toggleMatrixAction(
    row: PermissionMatrixRow,
    action: PermissionAction,
    checked: boolean,
): void {
    setMatrixPermissions(row.actions[action] ?? [], checked);
}
function toggleAllMatrixPermissions(checked: boolean): void {
    setMatrixPermissions([...matrixOptionsByName.value.keys()], checked);
}
const scopedPermissionGroups = computed(() => {
    const allowed = permissionsByScope[form.value.scope_level] ?? [];
    return permissionGroups
        .map((group) => ({
            ...group,
            options: permissionOptions.value.filter(
                (option) =>
                    group.permissions.includes(permissionName(option)) &&
                    allowed.includes(permissionName(option)),
            ),
        }))
        .filter((group) => group.options.length > 0);
});
const effectivePermissions = computed(() =>
    scopedPermissionGroups.value.flatMap((group) => group.options),
);
function permissionGroupState(groupKey: string): {
    selected: number;
    total: number;
    allSelected: boolean;
} {
    const group = scopedPermissionGroups.value.find(
        (item) => item.key === groupKey,
    );
    if (!group || group.options.length === 0)
        return { selected: 0, total: 0, allSelected: false };

    const selectedIds = new Set(permissionIds.value);
    const selected = group.options.filter((option) =>
        selectedIds.has(String(option.id)),
    ).length;
    return {
        selected,
        total: group.options.length,
        allSelected: selected === group.options.length,
    };
}
function togglePermissionGroup(groupKey: string, checked: boolean): void {
    const group = scopedPermissionGroups.value.find(
        (item) => item.key === groupKey,
    );
    if (!group) return;

    const groupIds = new Set(group.options.map((option) => String(option.id)));
    const retainedIds = permissionIds.value.filter((id) => !groupIds.has(id));
    permissionIds.value = checked ? [...retainedIds, ...groupIds] : retainedIds;
}
const inlineEditableAccountIds = computed(() => {
    const levels = ["global", "province", "commune", "village"];
    const actorLevelIndex = levels.indexOf(currentActorScopeLevel.value ?? "");

    return new Set(
        rows.value
            .filter((row) => {
                if (!canEditAccountConfigurations.value) return false;
                if (actorLevelIndex !== 0) return true;

                const rowScopeIndex = levels.indexOf(
                    String(row.scope_level ?? ""),
                );
                return rowScopeIndex >= 0 && rowScopeIndex <= actorLevelIndex;
            })
            .map((row) => String(row.id)),
    );
});
const canCreate = computed(() =>
    props.permissions.includes(
        props.moduleKey === "households"
            ? "create-households"
            : props.moduleKey === "schools"
              ? "create-schools"
              : props.moduleKey === "villages"
                ? "create-villages"
                : props.moduleKey === "roles"
                  ? "create-roles"
                  : props.moduleKey === "users"
                    ? "create-users"
                    : "",
    ),
);
const canUpdate = computed(() =>
    props.permissions.includes(
        props.moduleKey === "households"
            ? "update-households"
            : props.moduleKey === "schools"
              ? "update-schools"
              : props.moduleKey === "villages"
                ? "update-villages"
                : props.moduleKey === "roles"
                  ? "update-roles"
                  : props.moduleKey === "users"
                    ? "update-users"
                    : props.moduleKey === "trash"
                      ? "update-trash"
                      : "",
    ),
);
const canDelete = computed(() =>
    props.permissions.includes(
        props.moduleKey === "households"
            ? "delete-households"
            : props.moduleKey === "schools"
              ? "delete-schools"
              : props.moduleKey === "villages"
                ? "delete-villages"
                : props.moduleKey === "roles"
                  ? "delete-roles"
                  : props.moduleKey === "trash"
                    ? "delete-trash"
                    : "",
    ),
);

function asRows(value: unknown): Row[] {
    if (Array.isArray(value))
        return value.filter(
            (item): item is Row => typeof item === "object" && item !== null,
        );
    if (typeof value === "object" && value !== null && "data" in value) {
        const nested = (value as { data?: unknown }).data;
        return Array.isArray(nested)
            ? nested.filter(
                  (item): item is Row =>
                      typeof item === "object" && item !== null,
              )
            : [];
    }
    return [];
}

const householdPersons = computed(() => {
    const persons = asRows(householdDetail.value?.persons);
    const head = asRow(householdDetail.value?.head);
    let members = persons;
    if (householdDetailPage.value === 1 && Object.keys(head).length) {
        const headId = head.id;
        const headIndex =
            headId === undefined
                ? persons.findIndex(
                      (person) => person.relationship_with_head === "Chủ hộ",
                  )
                : persons.findIndex(
                      (person) => String(person.id) === String(headId),
                  );
        if (headIndex === -1) {
            members = [
                { ...head, relationship_with_head: "Chủ hộ" },
                ...persons,
            ];
        } else {
            members = persons.map((person, index) =>
                index === headIndex
                    ? { ...person, relationship_with_head: "Chủ hộ" }
                    : person,
            );
        }
    }
    return [...members].sort(
        (left, right) =>
            Number(right.relationship_with_head === "Chủ hộ") -
            Number(left.relationship_with_head === "Chủ hộ"),
    );
});

function asRow(value: unknown): Row {
    return typeof value === "object" && value !== null && !Array.isArray(value)
        ? (value as Row)
        : {};
}

function latestPersonEducation(person: Row): Row {
    return personEducations(person)[0] ?? {};
}

function personEducations(person: Row): Row[] {
    return asRows(person.educations).sort((left, right) =>
        String(right.school_year ?? "").localeCompare(
            String(left.school_year ?? ""),
        ),
    );
}

function educationGraduationLabel(education: Row): string {
    const levelLabels: Record<string, string> = {
        MN: "Mầm non",
        TH: "Tiểu học",
        THCS: "THCS",
        THPT: "THPT",
    };
    const level =
        typeof education.graduation_level === "string"
            ? (levelLabels[education.graduation_level] ??
              education.graduation_level)
            : "";
    const year = education.graduation_year
        ? `năm ${String(education.graduation_year)}`
        : "";
    return [level, year].filter(Boolean).join(" · ");
}

function educationLiteracyLabels(education: Row): string[] {
    const labels: string[] = [];
    const completed =
        education.literacy_completed_class ?? education.completed_class;
    if (completed) labels.push(`Đã hoàn thành lớp ${String(completed)}`);
    if (education.literacy_current_class)
        labels.push(
            `Đang học XMC lớp ${String(education.literacy_current_class)}`,
        );
    if (education.learning_capacity)
        labels.push(`Khả năng học tập: ${String(education.learning_capacity)}`);
    if (education.literacy_relapse_level)
        labels.push(`Tái mù chữ: ${String(education.literacy_relapse_level)}`);
    if (education.finished_class)
        labels.push(
            `Hoàn thành lớp ${String(education.finished_class)}${education.finished_year ? ` (${String(education.finished_year)})` : ""}`,
        );
    if (education.dropped_class)
        labels.push(
            `Bỏ học lớp ${String(education.dropped_class)}${education.dropped_year ? ` (${String(education.dropped_year)})` : ""}`,
        );
    if (education.vocational_grad_level) {
        labels.push(
            `Tốt nghiệp nghề: ${String(education.vocational_grad_level)}${education.vocational_grad_year ? ` (${String(education.vocational_grad_year)})` : ""}`,
        );
    }
    if (education.is_complementary === true || education.is_complementary === 1)
        labels.push("Học bổ túc");
    return labels;
}

function personDisabilityDetails(person: Row): string[] {
    const disability =
        typeof person.disability === "object" && person.disability !== null
            ? (person.disability as Row)
            : {};
    const labels: Record<string, string> = {
        mobility_disability: "Vận động",
        hearing_speech_disability: "Nghe / nói",
        visual_disability: "Thị giác",
        mental_disability: "Tâm thần",
        intellectual_disability: "Trí tuệ",
        learning_disability: "Học tập",
        autism: "Tự kỷ",
        other_disability: "Khác",
    };
    const active = Object.entries(labels)
        .filter(
            ([field]) => disability[field] === true || disability[field] === 1,
        )
        .map(([, label]) => label);
    if (
        disability.has_disability_cert === true ||
        disability.has_disability_cert === 1
    ) {
        active.push("Có giấy xác nhận");
    }
    if (disability.can_study === false || disability.can_study === 0) {
        active.push("Không có khả năng học tập");
    } else if (disability.can_study === true || disability.can_study === 1) {
        active.push("Có khả năng học tập");
    }
    if (
        typeof disability.special_circumstance === "string" &&
        disability.special_circumstance
    ) {
        active.push(`Hoàn cảnh: ${disability.special_circumstance}`);
    }
    if (
        typeof disability.special_circumstance_detail === "string" &&
        disability.special_circumstance_detail
    ) {
        active.push(disability.special_circumstance_detail);
    }
    return active.length ? active : ["Chưa ghi nhận khuyết tật"];
}

function personGender(person: Row): string {
    return person.gender === "NU"
        ? "Nữ"
        : person.gender === "NAM"
          ? "Nam"
          : "Chưa cập nhật";
}

function personEthnicity(person: Row): string {
    const ethnicity = person.ethnicity;
    if (
        typeof ethnicity === "object" &&
        ethnicity !== null &&
        "name" in ethnicity
    ) {
        return String((ethnicity as Row).name ?? "Chưa cập nhật");
    }
    return typeof ethnicity === "string" && ethnicity
        ? ethnicity
        : String(person.ethnicity_code ?? "Chưa cập nhật");
}

function displayDate(value: unknown): string {
    if (typeof value !== "string" || !value) return "Chưa cập nhật";
    const date = value.slice(0, 10).split("-");
    return date.length === 3 ? `${date[2]}/${date[1]}/${date[0]}` : value;
}

function datePickerDisplayValue(value: unknown): string {
    const date = String(value ?? "").slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return "";
    const [year, month, day] = date.split("-");
    return `${day}/${month}/${year}`;
}

function openHouseholdDatePicker(
    event: MouseEvent,
    target: HouseholdDatePickerTarget,
): void {
    const trigger = event.currentTarget;
    if (!(trigger instanceof HTMLElement)) return;

    const value =
        target.kind === "member"
            ? String(memberForm.value.dob ?? "")
            : String(target.person.dob ?? "");
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const now = new Date();
    const year = match ? Number(match[1]) : now.getFullYear();
    const month = match ? Number(match[2]) - 1 : now.getMonth();
    const bounds = trigger.getBoundingClientRect();
    const width = 300;
    const height = 350;

    householdDatePicker.value = {
        target,
        year,
        month,
        left: Math.max(8, Math.min(bounds.left, window.innerWidth - width - 8)),
        top: Math.max(
            8,
            Math.min(bounds.bottom + 4, window.innerHeight - height - 8),
        ),
    };
}

function closeHouseholdDatePicker(): void {
    householdDatePicker.value = null;
}

function householdDatePickerValue(): string {
    const picker = householdDatePicker.value;
    if (!picker) return "";
    return picker.target.kind === "member"
        ? String(memberForm.value.dob ?? "")
        : String(picker.target.person.dob ?? "");
}

function isHouseholdDatePickerDayFuture(day: number): boolean {
    const picker = householdDatePicker.value;
    if (!picker) return true;

    const candidate = `${picker.year}-${String(picker.month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
    const now = new Date();
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
    return candidate > today;
}

function shiftHouseholdDatePickerMonth(amount: number): void {
    const picker = householdDatePicker.value;
    if (!picker) return;

    const date = new Date(Date.UTC(picker.year, picker.month + amount, 1));
    picker.year = date.getUTCFullYear();
    picker.month = date.getUTCMonth();
}

function selectHouseholdDate(day: number): void {
    const picker = householdDatePicker.value;
    if (!picker) return;

    const date = `${picker.year}-${String(picker.month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
    if (picker.target.kind === "member") {
        memberForm.value.dob = date;
    } else {
        picker.target.person.dob = date;
    }
    closeHouseholdDatePicker();
}

function relationshipSlotError(
    relationship: unknown,
    currentPerson?: Row | string | number | null,
): string {
    const selectedRelationship = String(relationship ?? "");
    const slot = relationshipSlotGroups.find(({ relationships }) =>
        relationships.includes(selectedRelationship),
    );
    if (!slot) return "";

    const conflict = householdEditPersons.value.some(
        (person) =>
            (typeof currentPerson === "object" && currentPerson !== null
                ? person !== currentPerson
                : currentPerson === null || currentPerson === undefined
                  ? true
                  : String(person.id ?? "") !== String(currentPerson)) &&
            slot.relationships.includes(
                String(person.relationship_with_head ?? ""),
            ),
    );
    return conflict
        ? `Phiếu chỉ được có một người là ${slot.label}.`
        : "";
}

function displayFormValue(value: unknown): string {
    if (value === null || value === undefined) return "Chưa cập nhật";
    if (typeof value === "boolean") return value ? "Có" : "Không";
    const text = String(value).trim();
    return text || "Chưa cập nhật";
}

function displayHouseholdViewValue(value: unknown): string {
    const text = displayFormValue(value);
    return text === "Chưa cập nhật" || text === "—" ? "-" : text;
}

function displayFormBoolean(value: unknown): string {
    if (value === true || value === 1 || value === "1" || value === "true") {
        return "Có";
    }
    if (value === false || value === 0 || value === "0" || value === "false") {
        return "Không";
    }
    return "Chưa cập nhật";
}

const householdViewMemberColumns = [
    { key: "last_name", label: "Họ và tên đệm", required: true },
    { key: "first_name", label: "Tên", required: true },
    { key: "relationship_with_head", label: "Quan hệ", required: true },
    { key: "dob", label: "Ngày sinh", required: true },
    { key: "gender", label: "Giới tính", required: true },
    { key: "ethnicity", label: "Dân tộc", required: true },
    { key: "religion", label: "Tôn giáo" },
    { key: "priority_type", label: "Diện ưu tiên" },
    { key: "parent_name", label: "Cha/mẹ hoặc người thân" },
    { key: "phone", label: "Số điện thoại" },
    { key: "note", label: "Ghi chú" },
    { key: "school_year", label: "Năm học", required: true },
    { key: "academic_block", label: "Khối học" },
    { key: "current_class", label: "Lớp đang học" },
    { key: "school", label: "Trường đang học" },
    { key: "graduation_level", label: "Tốt nghiệp văn hóa" },
    { key: "is_complementary", label: "Bổ túc" },
    { key: "graduation_year", label: "Năm tốt nghiệp văn hóa" },
    { key: "vocational_grad_level", label: "Tốt nghiệp nghề" },
    { key: "vocational_grad_year", label: "Năm tốt nghiệp nghề" },
    { key: "finished_class", label: "Lớp dở dang" },
    { key: "finished_year", label: "Năm dở dang" },
    { key: "dropped_class", label: "Lớp bỏ học" },
    { key: "dropped_year", label: "Năm bỏ học" },
    { key: "literacy_current_class", label: "Lớp mù chữ đang học" },
    { key: "literacy_completed_class", label: "Lớp đã hoàn thành" },
    { key: "literacy_relapse_level", label: "Mức tái mù chữ" },
    { key: "learning_capacity", label: "Năng lực học tập" },
    { key: "mobility_disability", label: "Vận động" },
    { key: "hearing_speech_disability", label: "Nghe nói" },
    { key: "visual_disability", label: "Thị giác" },
    { key: "mental_disability", label: "Thần kinh" },
    { key: "intellectual_disability", label: "Trí tuệ" },
    { key: "learning_disability", label: "Học tập" },
    { key: "autism", label: "Tự kỷ" },
    { key: "other_disability", label: "Khuyết tật khác" },
    { key: "has_disability_cert", label: "Có giấy xác nhận" },
    { key: "can_study", label: "Có khả năng học" },
    { key: "special_circumstance", label: "Hoàn cảnh đặc biệt" },
    { key: "special_circumstance_detail", label: "Chi tiết hoàn cảnh" },
] as const;

const memberColumnResizeCleanup = new WeakMap<HTMLTableElement, () => void>();
const vMemberColumnResize: Directive<HTMLTableElement> = {
    mounted(table) {
        let activeCleanup: (() => void) | null = null;
        const preferenceKey = table.classList.contains(
            "household-view-member-table",
        )
            ? "pcgd-household-view-member-column-widths-fit-v5"
            : "pcgd-household-edit-member-column-widths-fit-v1";
        const isViewTable = table.classList.contains(
            "household-view-member-table",
        );
        const viewDetailColumnIndex =
            householdViewMemberColumns.findIndex(
                ({ key }) => key === "special_circumstance_detail",
            ) + 1;
        const columns =
            table.querySelectorAll<HTMLTableColElement>("colgroup col");
        const loadWidths = (): number[] | null => {
            try {
                const stored = window.localStorage.getItem(preferenceKey);
                if (!stored) return null;
                const widths: unknown = JSON.parse(stored);
                if (
                    !Array.isArray(widths) ||
                    !widths.every(
                        (width) =>
                            typeof width === "number" &&
                            Number.isFinite(width) &&
                            width >= 48,
                    )
                ) {
                    return null;
                }
                return widths;
            } catch (error) {
                console.warn(
                    "Không thể đọc độ rộng cột nhân khẩu đã lưu.",
                    error,
                );
                return null;
            }
        };
        const syncStickyColumns = () => {
            const firstWidth = columns[0]?.getBoundingClientRect().width ?? 38;
            const secondWidth =
                columns[1]?.getBoundingClientRect().width ?? 105;
            table.style.setProperty(
                "--member-sticky-first-width",
                `${firstWidth}px`,
            );
            table.style.setProperty(
                "--member-sticky-second-width",
                `${secondWidth}px`,
            );
        };
        const resizeTableToColumns = () => {
            const totalWidth = Array.from(columns).reduce(
                (sum, column) => sum + column.getBoundingClientRect().width,
                0,
            );
            const tableWidth = Math.ceil(totalWidth);
            table.style.width = `${tableWidth}px`;
            table.style.minWidth = `${tableWidth}px`;
        };
        const fitColumnsToContent = () => {
            const canvas = document.createElement("canvas");
            const context = canvas.getContext("2d");
            if (!context) {
                throw new Error("Không thể đo nội dung các cột nhân khẩu.");
            }

            const bodyRows = table.querySelectorAll<HTMLTableRowElement>(
                "tbody tr",
            );
            columns.forEach((column, columnIndex) => {
                let width =
                    columnIndex === 0
                        ? 38
                        : columnIndex === 4
                          ? 136
                          : 56;
                const cells = Array.from(
                    bodyRows,
                    (row) => row.cells[columnIndex],
                ).filter(
                    (cell): cell is HTMLTableCellElement =>
                        cell !== undefined,
                );

                for (const cell of cells) {
                    const style = window.getComputedStyle(cell);
                    context.font = style.font;
                    const controls = Array.from(
                        cell.querySelectorAll<
                            HTMLInputElement | HTMLSelectElement | HTMLButtonElement
                        >("input, select, button"),
                    );
                    const contentWidths = [
                        context.measureText(
                            (isViewTable
                                ? cell.textContent
                                : cell.innerText
                            )?.trim() ?? "",
                        ).width,
                        ...controls.map((control) => {
                            if (
                                control instanceof HTMLInputElement &&
                                control.type === "checkbox"
                            ) {
                                return 40;
                            }
                            const text =
                                control instanceof HTMLSelectElement
                                    ? control.selectedOptions[0]?.textContent ?? ""
                                    : control instanceof HTMLInputElement
                                      ? control.value ||
                                        control.placeholder ||
                                        ""
                                      : control.innerText;
                            const controlStyle =
                                window.getComputedStyle(control);
                            context.font = controlStyle.font;
                            const extra =
                                control instanceof HTMLSelectElement
                                    ? 28
                                    : control instanceof HTMLInputElement &&
                                        control.type === "date"
                                      ? 112
                                      : 18;
                            const minimum =
                                control instanceof HTMLInputElement &&
                                control.type !== "checkbox"
                                    ? 64
                                    : 0;
                            return Math.max(
                                minimum,
                                context.measureText(text.trim()).width + extra,
                            );
                        }),
                    ];
                    const cellContentWidth =
                        contentWidths.length > 0
                            ? Math.max(...contentWidths) +
                              Number.parseFloat(style.paddingLeft) +
                              Number.parseFloat(style.paddingRight) +
                              10
                            : 48;
                    width = Math.max(
                        width,
                        isViewTable && columnIndex === 0
                            ? Math.min(cellContentWidth, 56)
                            : cellContentWidth,
                    );
                }

                column.style.width = `${Math.ceil(
                    Math.min(width, isViewTable ? 220 : 280),
                )}px`;
            });
        };
        const savedWidths = loadWidths();
        fitColumnsToContent();
        savedWidths?.slice(0, columns.length).forEach((width, index) => {
            if (isViewTable && index === viewDetailColumnIndex) return;
            columns[index].style.width = `${Math.max(
                isViewTable && index === 0 ? Math.min(width, 56) : width,
                index === 0 ? 38 : index === 4 ? 136 : 48,
            )}px`;
        });
        resizeTableToColumns();
        syncStickyColumns();

        const onPointerDown = (event: PointerEvent) => {
            if (event.button !== 0) return;
            const header = (event.target as HTMLElement).closest("th");
            if (!header || !table.contains(header)) return;
            const rect = header.getBoundingClientRect();
            if (
                event.clientX < rect.right - 9 ||
                event.clientX > rect.right + 1
            ) {
                return;
            }

            const headerIndex = Array.prototype.indexOf.call(
                header.parentElement?.children ?? [],
                header,
            ) as number;
            const column = columns[headerIndex];
            if (!column) return;

            const startX = event.clientX;
            const startWidth = column.getBoundingClientRect().width;
            const originalCursor = document.body.style.cursor;
            const originalUserSelect = document.body.style.userSelect;
            event.preventDefault();
            document.body.style.cursor = "col-resize";
            document.body.style.userSelect = "none";

            const onPointerMove = (moveEvent: PointerEvent) => {
                const width = Math.max(
                    headerIndex === 4 ? 136 : 48,
                    startWidth + moveEvent.clientX - startX,
                );
                column.style.width = `${width}px`;
                resizeTableToColumns();
                syncStickyColumns();
            };
            const finishResize = () => {
                window.removeEventListener("pointermove", onPointerMove);
                window.removeEventListener("pointerup", finishResize);
                window.removeEventListener("pointercancel", finishResize);
                document.body.style.cursor = originalCursor;
                document.body.style.userSelect = originalUserSelect;
                if (activeCleanup === finishResize) activeCleanup = null;
                try {
                    window.localStorage.setItem(
                        preferenceKey,
                        JSON.stringify(
                            Array.from(columns, (item) =>
                                Math.round(item.getBoundingClientRect().width),
                            ),
                        ),
                    );
                } catch (error) {
                    console.warn("Không thể lưu độ rộng cột nhân khẩu.", error);
                }
            };

            activeCleanup = finishResize;
            window.addEventListener("pointermove", onPointerMove);
            window.addEventListener("pointerup", finishResize);
            window.addEventListener("pointercancel", finishResize);
        };

        table.addEventListener("pointerdown", onPointerDown);
        memberColumnResizeCleanup.set(table, () => {
            table.removeEventListener("pointerdown", onPointerDown);
            activeCleanup?.();
        });
    },
    unmounted(table) {
        memberColumnResizeCleanup.get(table)?.();
        memberColumnResizeCleanup.delete(table);
    },
};

function householdViewMemberValue(person: Row, key: string): string {
    const education = latestPersonEducation(person);
    const disability = asRow(person.disability);
    if (key === "ethnicity") return personEthnicity(person);
    if (key === "dob") return displayDate(person.dob);
    if (key === "gender") return personGender(person);
    if (
        [
            "mobility_disability",
            "hearing_speech_disability",
            "visual_disability",
            "mental_disability",
            "intellectual_disability",
            "learning_disability",
            "autism",
            "other_disability",
            "has_disability_cert",
            "can_study",
            "is_complementary",
        ].includes(key)
    ) {
        return displayFormBoolean(
            key === "is_complementary" ? education[key] : disability[key],
        );
    }
    if (key === "school") {
        return displayFormValue(
            pathValue(education, "school.name") || education.school_code,
        );
    }
    if (key === "graduation_level") {
        return displayFormValue(
            educationGraduationLabel(education) || education.graduation_level,
        );
    }
    if (key === "literacy_relapse_level") {
        return education.literacy_relapse_level
            ? `Mức độ ${String(education.literacy_relapse_level)}`
            : "Chưa cập nhật";
    }
    if (
        key === "special_circumstance" ||
        key === "special_circumstance_detail"
    ) {
        return displayFormValue(disability[key]);
    }
    if (
        [
            "school_year",
            "academic_block",
            "current_class",
            "graduation_year",
            "vocational_grad_level",
            "vocational_grad_year",
            "finished_class",
            "finished_year",
            "dropped_class",
            "dropped_year",
            "literacy_current_class",
            "literacy_completed_class",
            "learning_capacity",
        ].includes(key)
    ) {
        return displayFormValue(
            key === "literacy_completed_class"
                ? (education[key] ?? education.completed_class)
                : education[key],
        );
    }
    return displayFormValue(person[key]);
}

function householdViewMemberDisplayValue(person: Row, key: string): string {
    return displayHouseholdViewValue(householdViewMemberValue(person, key));
}

function pathValue(source: Row, path: string): string {
    const value = path.split(".").reduce<unknown>((current, segment) => {
        if (
            typeof current !== "object" ||
            current === null ||
            !(segment in current)
        )
            return undefined;
        return (current as Row)[segment];
    }, source);
    if (typeof value === "boolean")
        return value ? "Đang hoạt động" : "Đã vô hiệu hóa";
    if (Array.isArray(value)) return value.join(", ");
    if (typeof value === "string" && path === "scope_level")
        return scopeLabels[value] ?? value;
    if (typeof value === "string" && path === "date_of_birth" && value)
        return displayDate(value);
    if (typeof value === "string" && path === "level") {
        return (
            (
                {
                    MN: "Mầm non",
                    TH: "Tiểu học",
                    THCS: "Trung học cơ sở",
                    THPT: "Trung học phổ thông",
                } as Record<string, string>
            )[value] ?? value
        );
    }
    if (typeof value === "string" && path === "type") {
        return (
            (
                { single_level: "Một cấp", multi_level: "Liên cấp" } as Record<
                    string,
                    string
                >
            )[value] ?? value
        );
    }
    if (typeof value === "string" && path === "item_type") {
        return value === "household"
            ? "Phiếu điều tra"
            : value === "person"
              ? "Nhân khẩu"
              : value;
    }
    return value === null || value === undefined || value === ""
        ? "—"
        : String(value);
}

function fieldLabel(field: string): string {
    return fieldLabels[field] ?? field.replaceAll("_", " ");
}

function permissionLabel(permission: unknown): string {
    return permissionLabels[String(permission)] ?? String(permission);
}

function permissionName(permission: unknown): string {
    if (typeof permission === "object" && permission !== null) {
        const value = permission as Row;
        return String(value.name ?? value.key ?? "");
    }

    return String(permission);
}

function roleFormState(): string {
    return JSON.stringify({
        name: form.value.name ?? "",
        description: form.value.description ?? "",
        scope_level: form.value.scope_level ?? "",
        permissions: [...permissionIds.value].sort(),
    });
}

function permissionGroupsForDisplay(
    value: unknown,
): { key: string; label: string; options: unknown[] }[] {
    const permissions = Array.isArray(value) ? value : [];

    return permissionGroups
        .map((group) => ({
            key: group.key,
            label: group.label,
            options: permissions.filter((permission) =>
                group.permissions.includes(permissionName(permission)),
            ),
        }))
        .filter((group) => group.options.length > 0);
}

function shouldShowFormField(field: string): boolean {
    if (props.moduleKey === "villages" && field === "operational_status")
        return editing.value;
    if (props.moduleKey !== "users") return true;

    const level = form.value.scope_level;
    if (field === "role_id") return Boolean(level);
    if (["province_code", "commune_code", "village_codes"].includes(field)) {
        if (!form.value.role_id || !level || level === "global") return false;
        if (
            field === "province_code" &&
            currentActorScopeLevel.value === "commune"
        )
            return false;
        if (
            field === "commune_code" &&
            currentActorScopeLevel.value === "commune"
        )
            return false;
        if (field === "commune_code") return level === "commune";
        if (field === "village_codes") {
            return (
                currentActorScopeLevel.value === "commune" &&
                level === "commune" &&
                selectedRoleUsesVillageScope.value &&
                !selectedRoleManagesAllVillages.value
            );
        }
    }

    return true;
}

async function loadFormCommunes(code: string): Promise<void> {
    if (!code) {
        formCommunes.value = [];
        return;
    }
    formCommunes.value = await getLocationOptions<Row>(
        `/provinces/${encodeURIComponent(code)}/communes`,
    );
}

async function loadUserFormCommunes(): Promise<void> {
    const code = form.value.province_code;
    if (!code || form.value.scope_level !== "commune") {
        formCommunes.value = [];
        return;
    }

    const response = await api.get<unknown>(
        `/provinces/${encodeURIComponent(code)}/communes`,
        {
            params: selectedRoleIsCommuneAdmin.value
                ? {
                      available_for: "commune-admin",
                      except: selected.value?.id,
                  }
                : undefined,
        },
    );
    formCommunes.value = apiRows<Row>(apiData<unknown>(response.data));
    if (
        selectedRoleIsCommuneAdmin.value &&
        form.value.commune_code &&
        !formCommunes.value.some(
            (commune) => String(commune.code) === form.value.commune_code,
        )
    ) {
        form.value.commune_code = "";
        form.value.village_codes = "";
        selectedUserVillageCodes.value = [];
    }
}

async function loadFormVillages(code: string): Promise<void> {
    if (!code) {
        formVillages.value = [];
        return;
    }
    formVillages.value = await getLocationOptions<Row>(
        `/communes/${encodeURIComponent(code)}/villages`,
    );
}

async function loadSchoolFilterCommunes(code: string): Promise<void> {
    schoolFilterCommunes.value = code
        ? await getLocationOptions<Row>(
              `/provinces/${encodeURIComponent(code)}/communes`,
          )
        : [];
}

async function changeSchoolFilterProvince(): Promise<void> {
    schoolFilterCommune.value = "";
    try {
        await loadSchoolFilterCommunes(schoolFilterProvince.value);
        if (schoolFilterCommunes.value.length === 1) {
            schoolFilterCommune.value = String(
                schoolFilterCommunes.value[0].code,
            );
        }
        applySchoolFilters();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

function applySchoolFilters(): void {
    appliedSchoolFilters.value = {
        province_code: schoolFilterProvince.value,
        commune_code: schoolFilterCommune.value,
        level: schoolFilterLevel.value,
        q: schoolFilterQuery.value.trim(),
    };
    currentPage.value = 1;
    void load();
}

function changeSchoolType(): void {
    if (
        form.value.type === "multi_level" &&
        !["THCS", "THPT"].includes(String(form.value.level))
    ) {
        form.value.level = "";
    }
}

async function changeFormProvince(): Promise<void> {
    form.value.commune_code = "";
    form.value.village_code = "";
    if (props.moduleKey === "users") {
        form.value.village_codes = "";
        selectedUserVillageCodes.value = [];
    }
    formCommunes.value = [];
    formVillages.value = [];
    try {
        if (
            ["villages", "households", "imports", "schools"].includes(
                props.moduleKey,
            ) ||
            form.value.scope_level === "commune"
        ) {
            if (props.moduleKey === "users") {
                await loadUserFormCommunes();
            } else {
                await loadFormCommunes(form.value.province_code);
            }
            if (
                props.moduleKey === "households" &&
                !form.value.commune_code &&
                formCommunes.value.length === 1
            ) {
                form.value.commune_code = String(
                    formCommunes.value[0].code ?? "",
                );
                await changeHouseholdFormCommune();
            }
        } else {
            formCommunes.value = [];
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function changeFormCommune(): Promise<void> {
    form.value.village_code = "";
    if (props.moduleKey === "users") {
        form.value.village_codes = "";
        selectedUserVillageCodes.value = [];
    }
    formVillages.value = [];
    try {
        await loadFormVillages(form.value.commune_code);
    } catch (cause) {
        formErrors.value.commune_code = errorMessage(cause);
    }
}

async function changeUserFormRole(): Promise<void> {
    try {
        await loadUserFormCommunes();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function changeHouseholdFormCommune(): Promise<void> {
    form.value.village_code = "";
    formVillages.value = [];
    if (!form.value.commune_code) return;
    try {
        await loadFormVillages(form.value.commune_code);
        if (!form.value.village_code && formVillages.value.length === 1) {
            form.value.village_code = String(formVillages.value[0].code ?? "");
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function applyHouseholdLocationDefaults(): Promise<void> {
    if (!form.value.province_code && provinces.value.length === 1) {
        form.value.province_code = String(provinces.value[0].code ?? "");
    }

    if (!form.value.province_code) return;
    await loadFormCommunes(form.value.province_code);
    if (!form.value.commune_code && formCommunes.value.length === 1) {
        form.value.commune_code = String(formCommunes.value[0].code ?? "");
    }

    if (!form.value.commune_code) return;
    await loadFormVillages(form.value.commune_code);
    if (!form.value.village_code && formVillages.value.length === 1) {
        form.value.village_code = String(formVillages.value[0].code ?? "");
    }
}

async function loadUserFilterCommunes(code: string): Promise<void> {
    userFilterCommunes.value = code
        ? await getLocationOptions<Row>(
              `/provinces/${encodeURIComponent(code)}/communes`,
          )
        : [];
}

async function changeUserProvince(): Promise<void> {
    userFilterCommune.value = "";
    userFilterVillage.value = "";
    userFilterRole.value = "";
    currentPage.value = 1;
    await load();
}

async function loadUserFilterVillages(code: string): Promise<void> {
    userFilterVillages.value = code
        ? await getLocationOptions<Row>(
              `/communes/${encodeURIComponent(code)}/villages`,
          )
        : [];
}

async function changeUserCommune(): Promise<void> {
    userFilterVillage.value = "";
    currentPage.value = 1;
    try {
        await loadUserFilterVillages(userFilterCommune.value);
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function changeUserVillage(): Promise<void> {
    currentPage.value = 1;
    await load();
}

async function changeUserRole(): Promise<void> {
    currentPage.value = 1;
    await load();
}

async function changeUserScopeLevel(): Promise<void> {
    userFilterRole.value = "";
    if (userFilterScopeLevel.value === "global") {
        userFilterProvince.value = "";
    }
    currentPage.value = 1;
    await load();
}

async function searchUsers(): Promise<void> {
    currentPage.value = 1;
    await load();
}

async function searchRoles(): Promise<void> {
    currentPage.value = 1;
    await load();
}

watch(
    () => form.value.scope_level,
    async (level) => {
        if (props.moduleKey === "users") {
            if (
                !assignableUserRoles.value.some(
                    (role) => String(role.id) === form.value.role_id,
                )
            ) {
                form.value.role_id = "";
            }
            if (level === "global") {
                form.value.province_code = "";
                form.value.commune_code = "";
                form.value.village_codes = "";
                selectedUserVillageCodes.value = [];
                formCommunes.value = [];
            } else if (level === "province") {
                form.value.commune_code = "";
                form.value.village_codes = "";
                selectedUserVillageCodes.value = [];
                formCommunes.value = [];
            } else if (!level) {
                form.value.province_code = "";
                form.value.commune_code = "";
                form.value.village_codes = "";
                selectedUserVillageCodes.value = [];
                formCommunes.value = [];
            }

            if (level === "commune" && form.value.province_code) {
                try {
                    await loadUserFormCommunes();
                    if (
                        currentActorScopeLevel.value === "commune" &&
                        form.value.commune_code
                    ) {
                        await loadFormVillages(form.value.commune_code);
                    }
                } catch (cause) {
                    error.value = errorMessage(cause);
                }
            }
        }
        const allowedIds = new Set(
            effectivePermissions.value.map((option) => String(option.id)),
        );
        permissionIds.value = permissionIds.value.filter((id) =>
            allowedIds.has(id),
        );
    },
);

watch(
    form,
    () => {
        formErrors.value = {};
        userFormRequestError.value = "";
        roleFormRequestError.value = "";
        if (
            props.moduleKey === "households" &&
            !editing.value &&
            configurationFormOpen.value &&
            !busy.value
        ) {
            saveHouseholdCreationDraft(
                createdHouseholdId.value ?? "new",
                form.value,
            );
        }
    },
    { deep: true },
);

watch(permissionIds, () => {
    delete formErrors.value.permissions;
    delete formErrors.value["permissions.0"];
    roleFormRequestError.value = "";
});

async function load(): Promise<void> {
    busy.value = true;
    error.value = "";
    try {
        if (props.moduleKey === "account") {
            profile.value = apiData<Row>(
                (await api.get<unknown>("/account")).data,
            );
            const user =
                typeof profile.value.user === "object" &&
                profile.value.user !== null
                    ? (profile.value.user as Row)
                    : {};
            accountForm.value = {
                name: String(user.name ?? ""),
                email: String(user.email ?? ""),
                phone: String(user.phone ?? ""),
                citizen_id: String(user.citizen_id ?? ""),
                date_of_birth: String(user.date_of_birth ?? ""),
            };
            initialAccountForm.value = { ...accountForm.value };
        } else if (props.moduleKey === "imports") {
            if (
                props.importView === "history" &&
                props.permissions.includes("view-imports")
            ) {
                const historyResponse = await api.get<unknown>(
                    "/household-imports",
                    { params: { page: currentPage.value, per_page: 4 } },
                );
                const history = apiData<Row>(historyResponse.data);
                importHistory.value = asRows(history.imports);
                const historyPage = apiPage<Row>(
                    historyResponse.data,
                    history.imports,
                );
                currentPage.value = historyPage.current_page;
                lastPage.value = historyPage.last_page;
                totalRecords.value = historyPage.total;
            } else {
                importHistory.value = [];
                lastPage.value = 1;
            }
            if (
                props.importView !== "history" &&
                props.permissions.includes("create-imports") &&
                !importFormLoaded.value
            ) {
                const settingsResponse = await api.get<unknown>(
                    "/household-imports/form",
                );
                const settingsData = apiData<Row>(settingsResponse.data);
                provinces.value = asRows(settingsData.provinces);
                form.value = {
                    province_code: String(settingsData.province_code ?? ""),
                    commune_code: String(settingsData.commune_code ?? ""),
                    village_code: String(settingsData.village_code ?? ""),
                };
                const initialCommune =
                    typeof settingsData.initial_commune === "object" &&
                    settingsData.initial_commune !== null
                        ? (settingsData.initial_commune as Row)
                        : null;
                if (initialCommune) formCommunes.value = [initialCommune];
                const hasInitialVillages = Array.isArray(
                    settingsData.initial_villages,
                );
                if (hasInitialVillages) {
                    formVillages.value = asRows(settingsData.initial_villages);
                }
                if (form.value.province_code && !initialCommune) {
                    await loadFormCommunes(form.value.province_code);
                }
                if (form.value.commune_code && !hasInitialVillages)
                    await loadFormVillages(form.value.commune_code);
                importFormLoaded.value = true;
            }
        } else if (props.moduleKey === "merge") {
            await loadVillageMergeOptions();
        } else {
            const response = (
                await api.get<unknown>(info.value.endpoint, {
                    params: {
                        page: currentPage.value,
                        ...(props.moduleKey === "households"
                            ? {
                                  ...(householdQuery.value.trim()
                                      ? { q: householdQuery.value.trim() }
                                      : {}),
                                  ...(householdSchoolYear.value
                                      ? {
                                            school_year:
                                                householdSchoolYear.value,
                                        }
                                      : {}),
                                  per_page: householdPageSize.value,
                                  ...(props.householdFilters?.province_code
                                      ? {
                                            province_code:
                                                props.householdFilters
                                                    .province_code,
                                        }
                                      : {}),
                                  ...(props.householdFilters?.commune_code
                                      ? {
                                            commune_code:
                                                props.householdFilters
                                                    .commune_code,
                                        }
                                      : {}),
                                  ...(props.householdFilters?.village_code
                                      ? {
                                            village_code:
                                                props.householdFilters
                                                    .village_code,
                                        }
                                      : {}),
                              }
                            : {}),
                        ...(props.moduleKey === "villages"
                            ? {
                                  ...props.villageFilters,
                                  per_page: villagePageSize.value,
                              }
                            : {}),
                        ...(props.moduleKey === "schools"
                            ? {
                                  ...appliedSchoolFilters.value,
                                  per_page: schoolPageSize.value,
                              }
                            : {}),
                        ...(props.moduleKey === "users"
                            ? {
                                  scope_level:
                                      userFilterScopeLevel.value || undefined,
                                  province_code:
                                      userFilterProvince.value || undefined,
                                  commune_code:
                                      userFilterCommune.value || undefined,
                                  village_code:
                                      userFilterVillage.value || undefined,
                                  role_id: userFilterRole.value || undefined,
                                  q: userFilterQuery.value.trim() || undefined,
                              }
                            : {}),
                        ...(props.moduleKey === "roles"
                            ? { q: roleFilterQuery.value.trim() || undefined }
                            : {}),
                        ...(isCompactManagement.value
                            ? {
                                  per_page:
                                      props.moduleKey === "users"
                                          ? userPageSize.value
                                          : props.moduleKey === "roles"
                                            ? rolePageSize.value
                                            : 10,
                              }
                            : {}),
                        ...(props.moduleKey === "trash"
                            ? { per_page: trashPageSize.value }
                            : {}),
                        ...(props.moduleKey === "trash"
                            ? {
                                  type: trashFilterType.value || undefined,
                                  q: trashFilterQuery.value.trim() || undefined,
                              }
                            : {}),
                    },
                })
            ).data;
            const result = apiData<Row | unknown[]>(response);
            const data =
                typeof result === "object" &&
                result !== null &&
                !Array.isArray(result)
                    ? (result as Row)
                    : {};
            if (props.moduleKey === "households")
                rows.value = apiPage<Row>(response, result).data;
            else if (props.moduleKey === "schools")
                rows.value = asRows(data.schools);
            else if (props.moduleKey === "villages")
                rows.value = asRows(data.villages);
            else if (props.moduleKey === "roles")
                rows.value = apiPage<Row>(response, result).data;
            else if (props.moduleKey === "users")
                rows.value = asRows(data.allUsers);
            else rows.value = asRows(data.items);

            const collection =
                props.moduleKey === "households"
                    ? result
                    : props.moduleKey === "schools"
                      ? data.schools
                      : props.moduleKey === "villages"
                        ? data.villages
                        : props.moduleKey === "roles"
                          ? result
                          : props.moduleKey === "users"
                            ? data.allUsers
                            : data.items;
            const page = apiPage<Row>(response, collection);
            lastPage.value = page.last_page;
            currentPage.value = page.current_page;
            totalRecords.value = page.total;
            if (Array.isArray(data.provinces))
                provinces.value = asRows(data.provinces);
            if (props.moduleKey === "schools") {
                schoolActorScopeLevel.value =
                    typeof data.actor_scope_level === "string"
                        ? data.actor_scope_level
                        : null;
                schoolActorCommuneCode.value =
                    typeof data.commune_code === "string"
                        ? data.commune_code
                        : "";
            }
            if (props.moduleKey === "schools" && provinces.value.length === 1) {
                schoolFilterProvince.value = String(provinces.value[0].code);
                if (
                    schoolActorScopeLevel.value !== "commune" &&
                    !schoolFilterCommunes.value.length
                ) {
                    await loadSchoolFilterCommunes(schoolFilterProvince.value);
                }
                if (schoolActorScopeLevel.value === "commune") {
                    schoolFilterCommune.value = schoolActorCommuneCode.value;
                } else if (schoolFilterCommunes.value.length === 1) {
                    schoolFilterCommune.value = String(
                        schoolFilterCommunes.value[0].code,
                    );
                }
                appliedSchoolFilters.value = {
                    ...appliedSchoolFilters.value,
                    province_code: schoolFilterProvince.value,
                    commune_code: schoolFilterCommune.value,
                };
            }
            if (
                props.moduleKey === "schools" &&
                Array.isArray(data.school_levels)
            ) {
                schoolLevelOptions.value = data.school_levels.map(String);
                if (
                    schoolFilterLevel.value &&
                    !schoolLevelOptions.value.includes(schoolFilterLevel.value)
                ) {
                    schoolFilterLevel.value = "";
                    appliedSchoolFilters.value.level = "";
                }
            }
            if (props.moduleKey === "villages") {
                syncingVillageOptions = true;
                emit("villageOptions", {
                    provinces: data.provinces,
                    province_code:
                        typeof data.province_code === "string"
                            ? data.province_code
                            : "",
                    commune_code:
                        typeof data.commune_code === "string"
                            ? data.commune_code
                            : "",
                });
                await nextTick();
                syncingVillageOptions = false;
            }
            if (props.moduleKey === "users") {
                userFilterRoles.value = asRows(data.roles);
                userAccountScopeLevels.value = Array.isArray(
                    data.account_scope_levels,
                )
                    ? data.account_scope_levels.filter(
                          (level): level is string => typeof level === "string",
                      )
                    : [];
                currentActorScopeLevel.value =
                    typeof data.actor_scope_level === "string"
                        ? data.actor_scope_level
                        : null;
                canEditAccountConfigurations.value =
                    data.can_edit_account_configurations !== false;
                if (!userFiltersInitialized.value) {
                    const selectedFilters =
                        typeof data.selected_filters === "object" &&
                        data.selected_filters !== null
                            ? (data.selected_filters as Row)
                            : {};
                    userFilterProvince.value = String(
                        selectedFilters.province_code ?? "",
                    );
                    userFilterCommune.value = String(
                        selectedFilters.commune_code ?? "",
                    );
                    userFilterVillage.value = String(
                        selectedFilters.village_code ?? "",
                    );
                    userFilterRole.value = String(
                        selectedFilters.role_id ?? "",
                    );
                    userFilterScopeLevel.value = String(
                        selectedFilters.scope_level ?? "",
                    );
                    if (currentActorScopeLevel.value === "global") {
                        userFilterCommune.value = "";
                        userFilterVillage.value = "";
                    } else if (currentActorScopeLevel.value === "province") {
                        userFilterVillage.value = "";
                    }
                    userFiltersInitialized.value = true;
                    if (currentActorScopeLevel.value === "province") {
                        await loadUserFilterCommunes(userFilterProvince.value);
                    }
                    if (currentActorScopeLevel.value === "commune") {
                        await loadUserFilterVillages(userFilterCommune.value);
                    }
                }
            }
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
        if (importHistoryRefreshPending) {
            void refreshImportHistory();
        }
    }
}

function newFormValues(): Record<string, string> {
    if (props.moduleKey === "households")
        return {
            household_code: "",
            school_year: "",
            head_first_name: "",
            head_last_name: "",
            province_code: "",
            commune_code: "",
            village_code: "",
            address: "",
            residence_type: "THUONG_TRU",
            residence_status: "",
        };
    if (props.moduleKey === "schools")
        return {
            name: "",
            type: "",
            level: "",
            is_active: "1",
            province_code: "",
            commune_code: "",
        };
    if (props.moduleKey === "villages")
        return {
            name: "",
            province_code: "",
            commune_code: "",
            operational_status: "active",
        };
    if (props.moduleKey === "roles")
        return { name: "", description: "", scope_level: "", permissions: "" };
    if (props.moduleKey === "users")
        return {
            name: "",
            username: "",
            phone: "",
            citizen_id: "",
            date_of_birth: "",
            email: "",
            password: "",
            password_confirmation: "",
            role_id: "",
            scope_level: "",
            province_code: "",
            commune_code: "",
            village_codes: "",
            is_active: "1",
        };
    if (props.moduleKey === "merge")
        return { target_code: "", source_codes: "" };
    if (props.moduleKey === "imports")
        return { province_code: "", commune_code: "", village_code: "" };
    return {};
}

async function beginEdit(row?: Row): Promise<void> {
    householdEditPage.value = 1;
    householdDetailReadOnly.value = false;
    configurationFormOpen.value = true;
    selected.value = row ?? null;
    showUserPassword.value = false;
    showUserPasswordConfirmation.value = false;
    error.value = "";
    notice.value = "";
    deleteResult.value = null;
    initialVillageFormState.value = null;
    initialSchoolFormState.value = null;
    initialHouseholdFormState.value = null;
    initialUserFormState.value = null;
    formErrors.value = {};
    userFormSubmitted.value = false;
    roleFormSubmitted.value = false;
    schoolFormSubmitted.value = false;
    villageFormSubmitted.value = false;
    householdFormSubmitted.value = false;
    userFormRequestError.value = "";
    roleFormRequestError.value = "";
    managementResult.value = null;
    initialRoleFormState.value = null;
    permissionOptions.value = [];
    roleOptions.value = [];
    accountLevels.value = [];
    availablePrimaryAdminScopes.value = {};
    formOptionsBusy.value = false;
    createHouseholdWizardStep.value = 1;
    createdHouseholdId.value = null;
    const id = row?.id ?? row?.code;
    const route =
        props.moduleKey === "schools"
            ? ""
            : props.moduleKey === "villages"
              ? id === undefined
                  ? "/villages/form"
                  : `/villages/${id}/form`
              : props.moduleKey === "roles"
                ? id === undefined
                    ? "/roles/create"
                    : `/roles/${id}/edit`
                : props.moduleKey === "users"
                  ? id === undefined
                      ? "/users/create"
                      : `/users/${id}/edit`
                  : props.moduleKey === "households"
                    ? id === undefined
                        ? "/households/form"
                        : `/households/${id}/form`
                    : "";
    form.value = newFormValues();
    selectedUserVillageCodes.value = [];
    if (props.moduleKey === "households" && row) {
        for (const key of Object.keys(form.value)) {
            const value = row[key];
            if (value !== undefined && value !== null)
                form.value[key] = String(value);
        }
        const cachedPersons = asRows(row.persons);
        if (cachedPersons.length) {
            householdEditPersons.value =
                normalizeInlineHouseholdPersons(cachedPersons);
            saveInlineHouseholdPersonBaseline(householdEditPersons.value);
        }
    }
    if (props.moduleKey === "schools") {
        formCommunes.value = [];
        if (row) {
            form.value.name = String(row.name ?? "");
            form.value.type = String(row.type ?? "");
            form.value.level = String(row.level ?? "");
            form.value.is_active =
                row.is_active === false || row.is_active === 0 ? "0" : "1";
            form.value.province_code = String(row.province_code ?? "");
            form.value.commune_code = String(row.commune_code ?? "");
        } else if (provinces.value.length === 1) {
            form.value.province_code = String(provinces.value[0].code ?? "");
        }
        if (schoolActorScopeLevel.value === "commune") {
            form.value.commune_code = schoolActorCommuneCode.value;
        }
        if (form.value.province_code) {
            busy.value = true;
            try {
                if (
                    schoolFilterProvince.value === form.value.province_code &&
                    schoolFilterCommunes.value.length
                ) {
                    formCommunes.value = schoolFilterCommunes.value;
                } else {
                    await loadFormCommunes(form.value.province_code);
                }
            } catch (cause) {
                error.value = errorMessage(cause);
            } finally {
                busy.value = false;
            }
        }
        initialSchoolFormState.value = schoolFormState();
        return;
    }
    if (!route) return;
    const loadingUserForm = props.moduleKey === "users";
    if (loadingUserForm) formOptionsBusy.value = true;
    else busy.value = true;
    try {
        const data = apiData<Row>((await api.get<unknown>(route)).data);
        if (Array.isArray(data.provinces))
            provinces.value = asRows(data.provinces);
        if (props.moduleKey === "roles") {
            permissionOptions.value = asRows(data.permissions);
            if (Array.isArray(data.scope_levels))
                roleScopeLevels.value = data.scope_levels.map(String);
            const role =
                typeof data.role === "object" && data.role !== null
                    ? (data.role as Row)
                    : {};
            for (const key of ["name", "description", "scope_level"]) {
                form.value[key] =
                    role[key] === null || role[key] === undefined
                        ? ""
                        : String(role[key]);
            }
            permissionIds.value = Array.isArray(role.permissions)
                ? role.permissions.map(String)
                : [];
            if (data.default_scope_level !== undefined)
                form.value.scope_level = String(data.default_scope_level);
            if (data.selected_scope_level !== undefined)
                form.value.scope_level = String(data.selected_scope_level);
            if (scopeLevelOptions.value.length === 1)
                form.value.scope_level = scopeLevelOptions.value[0];
            await nextTick();
            initialRoleFormState.value = roleFormState();
        } else if (props.moduleKey === "users") {
            currentActorScopeLevel.value =
                typeof data.actor_scope_level === "string"
                    ? data.actor_scope_level
                    : currentActorScopeLevel.value;
            roleOptions.value = asRows(data.roles);
            accountLevels.value = Array.isArray(data.account_levels)
                ? data.account_levels.map(String)
                : [];
            availablePrimaryAdminScopes.value =
                typeof data.available_primary_admin_scopes === "object" &&
                data.available_primary_admin_scopes !== null
                    ? (data.available_primary_admin_scopes as Record<
                          string,
                          boolean
                      >)
                    : {};
            const user =
                typeof data.user === "object" && data.user !== null
                    ? (data.user as Row)
                    : {};
            const assignedRoles = asRows(user.roles);
            for (const key of Object.keys(form.value)) {
                const value = user[key];
                if (Array.isArray(value))
                    form.value[key] = value.map(String).join(", ");
                else if (value !== undefined && value !== null) {
                    const stringValue = String(value);
                    form.value[key] =
                        key === "date_of_birth"
                            ? stringValue.slice(0, 10)
                            : key === "is_active"
                              ? value === true || value === 1 || value === "1"
                                  ? "1"
                                  : "0"
                              : stringValue;
                }
            }
            form.value.scope_level = String(
                user.scope_level ??
                    assignedRoles[0]?.scope_level ??
                    accountLevels.value[0] ??
                    "",
            );
            if (assignedRoles[0]?.id !== undefined)
                form.value.role_id = String(assignedRoles[0].id);
            const locations =
                typeof data.selected_locations === "object" &&
                data.selected_locations !== null
                    ? (data.selected_locations as Row)
                    : {};
            selectedUserVillageCodes.value = Array.isArray(
                locations.village_codes,
            )
                ? locations.village_codes.map(String)
                : [];
            form.value.village_codes =
                selectedUserVillageCodes.value.join(", ");
            for (const key of [
                "province_code",
                "commune_code",
                "village_codes",
            ]) {
                if (!form.value[key] && locations[key] !== undefined) {
                    const value = locations[key];
                    form.value[key] = Array.isArray(value)
                        ? value.map(String).join(", ")
                        : String(value);
                }
            }
            if (
                form.value.scope_level === "commune" &&
                form.value.province_code
            ) {
                await loadUserFormCommunes();
                if (
                    currentActorScopeLevel.value === "commune" &&
                    form.value.commune_code
                ) {
                    await loadFormVillages(form.value.commune_code);
                }
            }
            if (row) {
                await nextTick();
                initialUserFormState.value = userFormState();
            }
        } else {
            const modelKey =
                props.moduleKey === "villages" ? "village" : "household";
            const model =
                typeof data[modelKey] === "object" && data[modelKey] !== null
                    ? (data[modelKey] as Row)
                    : {};
            for (const key of Object.keys(form.value)) {
                const value = model[key];
                if (value !== undefined && value !== null)
                    form.value[key] = String(value);
            }
            if (props.moduleKey === "households") {
                householdFormSchoolYears.value = Array.isArray(
                    data.school_years,
                )
                    ? data.school_years.map(String)
                    : householdSchoolYearOptions;
                householdEditPersons.value = normalizeInlineHouseholdPersons(
                    ensureLegacyHouseholdHead(
                        asRows(data.persons),
                        model,
                        String(form.value.school_year ?? ""),
                    ),
                );
                saveInlineHouseholdPersonBaseline(householdEditPersons.value);
                ethnicities.value = asRows(data.ethnicities);
            }
            if (data.province_code !== undefined)
                form.value.province_code = String(data.province_code);
            if (data.commune_code !== undefined)
                form.value.commune_code = String(data.commune_code);
            if (props.moduleKey === "households" && !row) {
                restoreHouseholdCreationDraft("new");
            }
            if (props.moduleKey === "households") {
                if (!row || !form.value.school_year) {
                    form.value.school_year = currentSchoolYearValue();
                }
                householdFormSchoolYears.value = [
                    ...new Set(
                        [
                            ...householdFormSchoolYears.value,
                            form.value.school_year,
                        ].filter(Boolean),
                    ),
                ].sort();
                await applyHouseholdLocationDefaults();
            } else if (
                props.moduleKey === "villages" &&
                form.value.province_code
            ) {
                await loadFormCommunes(form.value.province_code);
            }
            if (props.moduleKey === "households" && row) {
                initialHouseholdFormState.value = householdFormState();
            }
        }
        if (props.moduleKey === "villages") {
            initialVillageFormState.value = villageFormState();
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        if (loadingUserForm) formOptionsBusy.value = false;
        else busy.value = false;
    }
}

async function openHousehold(row: Row): Promise<void> {
    const id = row.id;
    if (id === undefined) return;
    householdDetailReadOnly.value = true;
    householdDetailFromTrash.value = false;
    trashedHouseholdPersons.value = [];
    householdLoading.value = true;
    householdListRow.value = row;
    householdDetailPage.value = 1;
    householdDetailLastPage.value = 1;
    householdDetailTotal.value = Number(
        row.members_count ?? row.persons_count ?? 0,
    );
    householdDetail.value = {
        ...(householdDetail.value?.id === id ? householdDetail.value : row),
        persons: [],
    };
    try {
        const [householdResponse, personsResponse] = await Promise.all([
            api.get<unknown>(`/households/${id}`),
            api.get<unknown>(`/households/${id}/persons`, {
                params: { page: 1, per_page: householdDetailPageSize },
            }),
        ]);
        const responseData = apiData<Row>(householdResponse.data);
        const household = asRow(responseData.household ?? responseData);
        const personsPage = apiPage<Row>(personsResponse.data);
        householdDetail.value = {
            ...household,
            head: responseData.head,
            has_household_head: responseData.has_household_head,
            persons: personsPage.data,
        };
        householdDetailPage.value = personsPage.current_page;
        householdDetailLastPage.value = personsPage.last_page;
        householdDetailTotal.value = personsPage.total;
        memberEditorOpen.value = false;
        error.value = "";
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        householdLoading.value = false;
    }
}

async function openTrashedHousehold(row: Row): Promise<void> {
    if (row.item_type !== "household" || row.id === undefined) return;

    householdDetailReadOnly.value = true;
    householdDetailFromTrash.value = true;
    householdLoading.value = true;
    householdListRow.value = row;
    householdDetailPage.value = 1;
    householdDetailLastPage.value = 1;
    householdDetailTotal.value = 0;
    householdDetail.value = null;
    error.value = "";

    try {
        const response = await api.get<unknown>(`/trash/households/${row.id}`);
        const household = asRow(apiData<unknown>(response.data));
        trashedHouseholdPersons.value = asRows(household.persons);
        householdDetailTotal.value = trashedHouseholdPersons.value.length;
        householdDetailLastPage.value = Math.max(
            1,
            Math.ceil(
                householdDetailTotal.value / householdDetailPageSize,
            ),
        );
        householdDetail.value = {
            ...household,
            persons: trashedHouseholdPersons.value.slice(
                0,
                householdDetailPageSize,
            ),
        };
    } catch (cause) {
        householdDetailFromTrash.value = false;
        error.value = errorMessage(cause);
    } finally {
        householdLoading.value = false;
    }
}

async function openTrashedPerson(row: Row): Promise<void> {
    if (row.item_type !== "person" || row.id === undefined) return;

    memberBusy.value = true;
    error.value = "";
    try {
        const response = await api.get<unknown>(`/trash/persons/${row.id}`);
        selectedHouseholdPerson.value = asRow(apiData<unknown>(response.data));
        personDetailFromTrash.value = true;
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        memberBusy.value = false;
    }
}

function closeHouseholdPersonDetail(): void {
    selectedHouseholdPerson.value = null;
    personDetailFromTrash.value = false;
}

async function loadHouseholdDetailPage(
    page: number,
    forceReload = false,
): Promise<void> {
    const householdId = householdDetail.value?.id;
    if (
        householdId === undefined ||
        page < 1 ||
        page > householdDetailLastPage.value ||
        (!forceReload && page === householdDetailPage.value) ||
        householdDetailPageLoading.value
    ) {
        return;
    }

    householdDetailPageLoading.value = true;
    error.value = "";
    try {
        if (householdDetailFromTrash.value) {
            householdDetailPage.value = page;
            if (householdDetail.value) {
                const start = (page - 1) * householdDetailPageSize;
                householdDetail.value.persons =
                    trashedHouseholdPersons.value.slice(
                        start,
                        start + householdDetailPageSize,
                    );
            }
            return;
        }
        const response = await api.get<unknown>(
            `/households/${householdId}/persons`,
            { params: { page, per_page: householdDetailPageSize } },
        );
        const personsPage = apiPage<Row>(response.data);
        if (householdDetail.value?.id !== householdId) return;
        householdDetail.value.persons = personsPage.data;
        householdDetailPage.value = personsPage.current_page;
        householdDetailLastPage.value = personsPage.last_page;
        householdDetailTotal.value = personsPage.total;
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        householdDetailPageLoading.value = false;
    }
}

async function openUserDetail(row: Row): Promise<void> {
    const id = row.id;
    if (id === undefined) return;

    userDetailRow.value = row;
    userDetail.value = null;
    userDetailError.value = "";
    userDetailLoading.value = true;
    try {
        const response = await api.get<unknown>(`/users/${id}`);
        const payload = asRow(apiData<unknown>(response.data));
        userDetail.value = asRow(payload.data ?? payload);
    } catch (cause) {
        userDetailError.value = errorMessage(cause);
    } finally {
        userDetailLoading.value = false;
    }
}

function closeUserDetail(): void {
    userDetail.value = null;
    userDetailRow.value = null;
    userDetailError.value = "";
}

function editUserDetail(): void {
    const row = userDetailRow.value;
    closeUserDetail();
    if (row) void beginEdit(row);
}

function closeHouseholdDetail(): void {
    notice.value = "";
    householdDetail.value = null;
    householdDetailReadOnly.value = false;
    householdDetailFromTrash.value = false;
    trashedHouseholdPersons.value = [];
    householdListRow.value = null;
    householdDetailPage.value = 1;
    householdDetailLastPage.value = 1;
    householdDetailTotal.value = 0;
    selectedHouseholdPerson.value = null;
    memberEditorOpen.value = false;
}

function editHousehold(): void {
    const row = householdListRow.value;
    if (row) void beginEdit(row);
}

function printHousehold(): void {
    window.print();
}

function toggleHouseholdSelection(id: number, checked: boolean): void {
    selectedHouseholdIds.value = checked
        ? [...new Set([...selectedHouseholdIds.value, id])]
        : selectedHouseholdIds.value.filter((selectedId) => selectedId !== id);
}

const allHouseholdRowsSelected = computed(
    () =>
        rows.value.length > 0 &&
        rows.value.every((row) =>
            selectedHouseholdIds.value.includes(Number(row.id)),
        ),
);

function toggleAllHouseholdRows(checked: boolean): void {
    const pageIds = rows.value
        .map((row) => Number(row.id))
        .filter(Number.isInteger);
    selectedHouseholdIds.value = checked
        ? [...new Set([...selectedHouseholdIds.value, ...pageIds])]
        : selectedHouseholdIds.value.filter((id) => !pageIds.includes(id));
}

const someHouseholdRowsSelected = computed(
    () =>
        rows.value.some((row) =>
            selectedHouseholdIds.value.includes(Number(row.id)),
        ) && !allHouseholdRowsSelected.value,
);

function applyHouseholdFilters(): void {
    selectedHouseholdIds.value = [];
    currentPage.value = 1;
    void load();
}

async function updateHouseholdPageAfterDelete(): Promise<void> {
    lastPage.value = Math.max(
        1,
        Math.ceil(totalRecords.value / householdPageSize.value),
    );
    if (currentPage.value > lastPage.value) {
        currentPage.value = lastPage.value;
        await load();
    }
}

async function confirmBulkHouseholdDelete(): Promise<void> {
    if (!selectedHouseholdIds.value.length || busy.value) return;
    busy.value = true;
    error.value = "";
    const ids = [...selectedHouseholdIds.value];
    try {
        const response = await api.delete<unknown>("/households", {
            data: { household_ids: ids },
        });
        const responseData = apiData<Row>(response.data);
        const deletedCount = Number(responseData.deleted_count);
        if (
            !Number.isInteger(deletedCount) ||
            deletedCount < 0 ||
            deletedCount > ids.length
        ) {
            throw new Error(
                "Phản hồi xóa phiếu không hợp lệ. Vui lòng tải lại danh sách.",
            );
        }
        rows.value = rows.value.filter((row) => !ids.includes(Number(row.id)));
        totalRecords.value = Math.max(0, totalRecords.value - deletedCount);
        selectedHouseholdIds.value = [];
        pendingHouseholdBulkDelete.value = false;
        await updateHouseholdPageAfterDelete();
        deleteResult.value = {
            success: true,
            title: "Xóa phiếu thành công",
            message: `Đã xóa ${deletedCount} phiếu điều tra đã chọn.`,
        };
    } catch (cause) {
        pendingHouseholdBulkDelete.value = false;
        deleteResult.value = {
            success: false,
            title: "Xóa phiếu thất bại",
            message: errorMessage(cause),
        };
    } finally {
        busy.value = false;
    }
}

function openRolePermissionDetails(row: Row): void {
    selectedRolePermissions.value = row;
}

function closeConfigurationForm(): void {
    if (
        props.moduleKey === "households" &&
        window.location.pathname.startsWith("/households/")
    ) {
        setHouseholdUrl("/households", true);
    }
    configurationFormOpen.value = false;
    selected.value = null;
    createdHouseholdId.value = null;
    createHouseholdWizardStep.value = 1;
    form.value = {};
    initialVillageFormState.value = null;
    initialSchoolFormState.value = null;
    initialHouseholdFormState.value = null;
    initialUserFormState.value = null;
    initialVillageFormState.value = null;
    showUserPassword.value = false;
    showUserPasswordConfirmation.value = false;
    error.value = "";
    formErrors.value = {};
    userFormSubmitted.value = false;
    schoolFormSubmitted.value = false;
    roleFormSubmitted.value = false;
    villageFormSubmitted.value = false;
    householdFormSubmitted.value = false;
}

function householdMemberDraftKey(
    householdId: number | string,
    personId: number | null,
): string {
    return `${householdMemberDraftPrefix}:${householdId}:${personId ?? "new"}`;
}

function householdCreationDraftKey(householdId: number | string): string {
    return `pcgd:household-creation-draft:${householdId}`;
}

function saveHouseholdCreationDraft(
    householdId: number | string,
    values: Record<string, string>,
): void {
    try {
        sessionStorage.setItem(
            householdCreationDraftKey(householdId),
            JSON.stringify(values),
        );
    } catch (cause) {
        console.error(
            "Không thể lưu bản nháp thông tin hộ trong phiên.",
            cause,
        );
        error.value = "Không thể lưu bản nháp thông tin hộ trên trình duyệt.";
    }
}

function restoreHouseholdCreationDraft(householdId: number | string): void {
    let raw: string | null;
    try {
        raw = sessionStorage.getItem(householdCreationDraftKey(householdId));
    } catch (cause) {
        console.error(
            "Không thể đọc bản nháp thông tin hộ trong phiên.",
            cause,
        );
        error.value =
            "Không thể khôi phục bản nháp thông tin hộ trên trình duyệt.";
        return;
    }
    if (raw === null) return;

    try {
        const draft = asRow(JSON.parse(raw));
        for (const key of Object.keys(form.value)) {
            if (key === "household_code") continue;
            const value = draft[key];
            if (typeof value === "string") form.value[key] = value;
        }
    } catch (cause) {
        console.error("Bản nháp thông tin hộ trong phiên không hợp lệ.", cause);
        error.value = "Không thể khôi phục bản nháp thông tin hộ đã lưu.";
    }
}

function clearHouseholdCreationDraft(householdId: number | string): void {
    try {
        sessionStorage.removeItem(householdCreationDraftKey(householdId));
    } catch (cause) {
        console.error(
            "Không thể xóa bản nháp thông tin hộ trong phiên.",
            cause,
        );
        error.value = "Không thể xóa bản nháp thông tin hộ trên trình duyệt.";
    }
}

function setHouseholdUrl(path: string, replace = false): void {
    const url = new URL(path, window.location.origin);
    if (
        `${url.pathname}${url.search}` ===
        `${window.location.pathname}${window.location.search}`
    ) {
        return;
    }

    if (replace) {
        window.history.replaceState({ module: "households" }, "", url);
    } else {
        window.history.pushState({ module: "households" }, "", url);
    }
}

function mergeStoredStringFields(
    target: Record<string, string>,
    source: unknown,
): void {
    const stored = asRow(source);
    for (const key of Object.keys(target)) {
        const value = stored[key];
        if (typeof value === "string") target[key] = value;
    }
}

function mergeStoredDisabilityFields(source: unknown): void {
    const stored = asRow(source);
    for (const key of Object.keys(disabilityForm.value)) {
        const value = stored[key];
        if (typeof value === "string" || typeof value === "boolean") {
            disabilityForm.value[key] = value;
        }
    }
}

function saveHouseholdMemberDraft(): void {
    const householdId = createdHouseholdId.value;
    if (householdId === null || !isHouseholdWizardMemberPage.value) return;

    try {
        sessionStorage.setItem(
            householdMemberDraftKey(householdId, memberId.value),
            JSON.stringify({
                member: memberForm.value,
                education: educationForm.value,
                disability: disabilityForm.value,
            }),
        );
    } catch (cause) {
        console.error("Không thể lưu bản nháp nhân khẩu trong phiên.", cause);
        error.value = "Không thể lưu bản nháp trên trình duyệt.";
    }
}

function restoreHouseholdMemberDraft(
    householdId: number,
    personId: number | null,
): void {
    const key = householdMemberDraftKey(householdId, personId);
    let raw: string | null;
    try {
        raw = sessionStorage.getItem(key);
    } catch (cause) {
        console.error("Không thể đọc bản nháp nhân khẩu trong phiên.", cause);
        error.value = "Không thể khôi phục bản nháp trên trình duyệt.";
        return;
    }
    if (raw === null) return;

    try {
        const draft = asRow(JSON.parse(raw));
        restoringHouseholdMemberDraft.value = true;
        mergeStoredStringFields(memberForm.value, draft.member);
        mergeStoredStringFields(educationForm.value, draft.education);
        mergeStoredDisabilityFields(draft.disability);
    } catch (cause) {
        console.error("Bản nháp nhân khẩu trong phiên không hợp lệ.", cause);
        error.value = "Không thể khôi phục bản nháp nhân khẩu đã lưu.";
    } finally {
        restoringHouseholdMemberDraft.value = false;
    }
}

function clearHouseholdMemberDraft(
    householdId: number,
    personId: number | null,
): void {
    try {
        sessionStorage.removeItem(
            householdMemberDraftKey(householdId, personId),
        );
    } catch (cause) {
        console.error("Không thể xóa bản nháp nhân khẩu trong phiên.", cause);
        error.value = "Không thể xóa bản nháp nhân khẩu trên trình duyệt.";
    }
}

function gradeFromClassName(className: unknown): string {
    const match = String(className ?? "")
        .trim()
        .match(/^(?:lớp\s*)?(\d{1,2})(?=\D|$)/iu);
    if (!match) return "";

    const grade = Number(match[1]);
    return grade >= 1 && grade <= 12 ? String(grade) : "";
}

function preschoolAgeFromClassName(className: unknown): string {
    const match = String(className ?? "")
        .trim()
        .match(/^MN\s*([3-5])(?=\D|$)/iu);
    if (!match) return "";

    const ages: Record<string, string> = {
        "3": "3-4 tuổi",
        "4": "4-5 tuổi",
        "5": "5-6 tuổi",
    };
    return ages[match[1]];
}

function academicBlockFromClassName(className: unknown): string {
    return preschoolAgeFromClassName(className) || gradeFromClassName(className);
}

function schoolLevelFromClassName(className: unknown): string {
    return /^\s*MN\s*\d/iu.test(String(className ?? "")) ? "MN" : "";
}

watch(
    () => educationForm.value.current_class,
    (className, previousClassName) => {
        const academicBlock = academicBlockFromClassName(className);
        const previousAcademicBlock =
            academicBlockFromClassName(previousClassName);
        if (
            previousClassName !== undefined &&
            academicBlock !== previousAcademicBlock &&
            educationForm.value.school_code
        ) {
            educationForm.value.school_code = "";
        }
        if (educationForm.value.academic_block !== academicBlock) {
            educationForm.value.academic_block = academicBlock;
        }
    },
);

watch(
    () => educationForm.value.academic_block,
    (academicBlock) => {
        if (personSchoolOptionsTimer !== undefined) {
            window.clearTimeout(personSchoolOptionsTimer);
        }
        const requestSequence = ++personSchoolOptionsSequence;
        const schoolLevel = schoolLevelFromClassName(
            educationForm.value.current_class,
        );
        const grade =
            gradeFromClassName(educationForm.value.current_class) ||
            gradeFromClassName(academicBlock);
        personSchoolOptionsError.value = "";

        if (!schoolLevel && !grade) {
            const selectedSchool = personSchools.value.find(
                (school) =>
                    String(school.code) === educationForm.value.school_code,
            );
            personSchools.value = selectedSchool
                ? [selectedSchool]
                : personSchools.value;
            personSchoolOptionsLoading.value = false;
            return;
        }

        const selectedSchool = personSchools.value.find(
            (school) => String(school.code) === educationForm.value.school_code,
        );
        personSchools.value = selectedSchool ? [selectedSchool] : [];
        personSchoolOptionsTimer = window.setTimeout(async () => {
            const householdId =
                householdDetail.value?.id ?? createdHouseholdId.value;
            if (householdId === null || householdId === undefined) {
                personSchoolOptionsLoading.value = false;
                return;
            }

            personSchoolOptionsLoading.value = true;
            try {
                const response = await api.get<unknown>(
                    `/households/${householdId}/persons/schools`,
                    {
                        params: schoolLevel
                            ? { school_level: schoolLevel }
                            : { academic_block: grade },
                    },
                );
                if (requestSequence !== personSchoolOptionsSequence) return;

                const schools = asRows(apiData<Row>(response.data).schools);
                const selectedSchool = personSchools.value.find(
                    (school) =>
                        String(school.code) === educationForm.value.school_code,
                );
                personSchools.value =
                    selectedSchool &&
                    !schools.some(
                        (school) =>
                            String(school.code) === String(selectedSchool.code),
                    )
                        ? [selectedSchool, ...schools]
                        : schools;
            } catch (cause) {
                if (requestSequence === personSchoolOptionsSequence) {
                    personSchoolOptionsError.value = errorMessage(cause);
                }
            } finally {
                if (requestSequence === personSchoolOptionsSequence) {
                    personSchoolOptionsLoading.value = false;
                }
            }
        }, 150);
    },
);

async function restoreHouseholdRoute(): Promise<void> {
    if (props.moduleKey !== "households" || householdRouteRestoreActive.value) {
        return;
    }

    const path = window.location.pathname;
    const route =
        /^\/households\/(\d+)\/persons(?:\/create|\/(\d+)\/edit)?\/?$/.exec(
            path,
        );
    if (!route) {
        if (path === "/households" && createdHouseholdId.value !== null) {
            configurationFormOpen.value = false;
            memberEditorOpen.value = false;
            householdDetail.value = null;
            createdHouseholdId.value = null;
            createHouseholdWizardStep.value = 1;
            selected.value = null;
        }
        return;
    }

    const householdId = Number(route[1]);
    const personId = route[2] ? Number(route[2]) : null;
    const isCreateRoute = path.includes("/persons/create");
    const isHead = isCreateRoute
        ? new URLSearchParams(window.location.search).get("is_head") !== "0"
        : false;
    householdRouteRestoreActive.value = true;
    try {
        const [response, formResponse] = await Promise.all([
            api.get<unknown>(`/households/${householdId}`),
            api.get<unknown>("/households/form"),
        ]);
        const responseData = apiData<Row>(response.data);
        householdDetail.value = asRow(responseData.household ?? responseData);
        const formData = apiData<Row>(formResponse.data);
        provinces.value = asRows(formData.provinces);
        householdFormSchoolYears.value = Array.isArray(formData.school_years)
            ? formData.school_years.map(String)
            : householdSchoolYearOptions;
        form.value = newFormValues();
        for (const key of Object.keys(form.value)) {
            const value = householdDetail.value[key];
            if (value !== null && value !== undefined) {
                form.value[key] = String(value);
            }
        }
        restoreHouseholdCreationDraft(householdId);
        if (!form.value.school_year) {
            form.value.school_year = currentSchoolYearValue();
        }
        householdFormSchoolYears.value = [
            ...new Set(
                [
                    ...householdFormSchoolYears.value,
                    form.value.school_year,
                ].filter(Boolean),
            ),
        ].sort();
        householdDetailReadOnly.value = false;
        householdListRow.value = householdDetail.value;
        createdHouseholdId.value = householdId;
        createHouseholdWizardStep.value = isCreateRoute && isHead ? 2 : 3;
        configurationFormOpen.value = true;
        selected.value = null;
        await applyHouseholdLocationDefaults();
        if (!isCreateRoute || personId !== null) {
            await refreshHouseholdEditPersons(householdId);
        }

        if (isCreateRoute || personId !== null) {
            const person =
                personId === null
                    ? undefined
                    : (householdEditPersons.value.find(
                          (row) => Number(row.id) === personId,
                      ) ?? { id: personId });
            await beginMember(isHead, person, false);
            restoreHouseholdMemberDraft(householdId, personId);
            memberEditorOpen.value = true;
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        householdRouteRestoreActive.value = false;
        householdRouteInitialLoading.value = false;
    }
}

async function beginMember(
    isHead: boolean,
    person?: Row,
    openAfterLoading = true,
): Promise<void> {
    memberFormSubmitted.value = false;
    memberFormErrors.value = {};
    const householdId =
        householdDetail.value?.id ??
        createdHouseholdId.value ??
        selected.value?.id;
    if (householdId === undefined) {
        memberId.value = person?.id === undefined ? null : Number(person.id);
        memberIsHead.value = person
            ? person.relationship_with_head === "Chủ hộ"
            : isHead;
        memberForm.value = {
            first_name: "",
            last_name: "",
            dob: "",
            gender: "NAM",
            ethnicity_code: "",
            relationship_with_head: isHead ? "Chủ hộ" : "",
            religion: "",
            priority_type: "",
            parent_name: "",
            phone: "",
            note: "",
        };
        educationForm.value = {
            school_year: String(form.value.school_year ?? ""),
            school_code: "",
            current_class: "",
            academic_block: "",
            graduation_level: "",
            graduation_year: "",
            is_complementary: "0",
            vocational_grad_level: "",
            vocational_grad_year: "",
            finished_class: "",
            finished_year: "",
            dropped_class: "",
            dropped_year: "",
            literacy_current_class: "",
            literacy_completed_class: "",
            literacy_relapse_level: "",
            learning_capacity: "",
        };
        memberEditorOpen.value = true;
        return;
    }
    memberId.value = person?.id === undefined ? null : Number(person.id);
    memberIsHead.value = person
        ? person.relationship_with_head === "Chủ hộ"
        : isHead;
    headMemberEducationStep.value = false;
    memberForm.value = {
        first_name: "",
        last_name: "",
        dob: "",
        gender: "NAM",
        ethnicity_code: "",
        relationship_with_head: isHead ? "Chủ hộ" : "",
        religion: "",
        priority_type: "",
        parent_name: "",
        phone: "",
        note: "",
    };
    educationForm.value = {
        school_year: String(householdDetail.value?.school_year ?? ""),
        school_code: "",
        current_class: "",
        academic_block: "",
        graduation_level: "",
        graduation_year: "",
        is_complementary: "0",
        vocational_grad_level: "",
        vocational_grad_year: "",
        finished_class: "",
        finished_year: "",
        dropped_class: "",
        dropped_year: "",
        literacy_current_class: "",
        literacy_completed_class: "",
        literacy_relapse_level: "",
        learning_capacity: "",
    };
    disabilityForm.value = Object.fromEntries([
        ...memberDisabilityOptions.map(({ key }) => [key, false]),
        ["can_study", ""],
        ["special_circumstance", ""],
        ["special_circumstance_detail", ""],
    ]);
    ethnicities.value = [];
    personSchools.value = [];
    personSchoolOptionsError.value = "";
    memberFormErrors.value = {};
    notice.value = "";
    error.value = "";
    householdFormSchoolYears.value = memberSchoolYearOptions;
    memberEditorOpen.value = false;
    if (isHouseholdCreatePage.value && createdHouseholdId.value !== null) {
        const route =
            memberId.value === null
                ? `/households/${createdHouseholdId.value}/persons/create?is_head=${memberIsHead.value ? "1" : "0"}`
                : `/households/${createdHouseholdId.value}/persons/${memberId.value}/edit`;
        setHouseholdUrl(route);
    }
    try {
        const path =
            memberId.value === null
                ? `/households/${householdId}/persons/form`
                : `/households/${householdId}/persons/${memberId.value}/form`;
        const result = apiData<Row>(
            (
                await api.get<unknown>(path, {
                    params: { is_head: memberIsHead.value ? 1 : 0 },
                })
            ).data,
        );
        const person =
            typeof result.person === "object" && result.person !== null
                ? (result.person as Row)
                : {};
        for (const key of Object.keys(memberForm.value)) {
            const value = person[key];
            if (
                value !== null &&
                value !== undefined &&
                String(value).trim() !== ""
            )
                memberForm.value[key] = String(value);
        }
        if (memberId.value === null && memberIsHead.value) {
            memberForm.value.relationship_with_head = "Chủ hộ";
        }
        const education =
            typeof result.education === "object" && result.education !== null
                ? (result.education as Row)
                : {};
        for (const key of Object.keys(educationForm.value)) {
            const value = education[key];
            if (
                key === "is_complementary" &&
                value !== null &&
                value !== undefined
            ) {
                educationForm.value[key] =
                    value === true || value === 1 || value === "1" ? "1" : "0";
            } else if (
                value !== null &&
                value !== undefined &&
                String(value).trim() !== ""
            ) {
                educationForm.value[key] = String(value);
            }
        }
        const classGrade = gradeFromClassName(
            educationForm.value.current_class,
        );
        if (classGrade) educationForm.value.academic_block = classGrade;
        const disability =
            typeof result.disability === "object" && result.disability !== null
                ? (result.disability as Row)
                : {};
        for (const key of Object.keys(disabilityForm.value)) {
            const value = disability[key];
            if (key === "can_study") {
                disabilityForm.value[key] =
                    value === null || value === undefined
                        ? ""
                        : String(
                              value === true || value === 1 || value === "1",
                          );
            } else if (
                memberDisabilityOptions.some((option) => option.key === key)
            ) {
                disabilityForm.value[key] = value === true || value === 1;
            } else if (value !== null && value !== undefined) {
                disabilityForm.value[key] = String(value);
            }
        }
        if (!educationForm.value.school_year) {
            educationForm.value.school_year = String(
                householdDetail.value?.school_year ?? "",
            );
        }
        ethnicities.value = asRows(result.ethnicities);
        personSchools.value = asRows(result.schools);
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        if (openAfterLoading) memberEditorOpen.value = true;
    }
}

function sanitizeMemberPhone(event: Event): void {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;

    const phone = input.value.replace(/[^\d+]/g, "");
    const sanitizedPhone = phone.startsWith("+")
        ? `+${phone.slice(1).replace(/\+/g, "")}`
        : phone.replace(/\+/g, "");
    input.value = sanitizedPhone;
    memberForm.value.phone = sanitizedPhone;
}

async function saveMember(): Promise<void> {
    const householdIdValue =
        householdDetail.value?.id ??
        createdHouseholdId.value ??
        selected.value?.id;
    const householdId =
        householdIdValue === undefined || householdIdValue === null
            ? ""
            : String(householdIdValue);
    if (!householdId || memberBusy.value) return;
    memberFormSubmitted.value = true;
    memberFormErrors.value = {};
    if (Object.keys(memberClientValidationErrors.value).length > 0) {
        await nextTick();
        scrollToFirstMemberError();
        return;
    }
    const booleanDisability = Object.fromEntries(
        memberDisabilityOptions.map(({ key }) => [
            key,
            disabilityForm.value[key] === true,
        ]),
    );
    const selectedSchool = personSchools.value.find(
        (school) => String(school.code) === educationForm.value.school_code,
    );
    const body: Row = {
        ...memberForm.value,
        is_head: memberIsHead.value,
        education: {
            ...educationForm.value,
            academic_block:
                gradeFromClassName(educationForm.value.current_class) ||
                educationForm.value.academic_block,
        },
        school_province_code: selectedSchool?.province_code ?? "",
        school_commune_code: selectedSchool?.commune_code ?? "",
        disability: {
            ...booleanDisability,
            can_study:
                disabilityForm.value.can_study === ""
                    ? null
                    : disabilityForm.value.can_study === "true",
            special_circumstance: disabilityForm.value.special_circumstance,
            special_circumstance_detail:
                disabilityForm.value.special_circumstance_detail,
        },
    };
    memberBusy.value = true;
    error.value = "";
    const householdWizardMember = isHouseholdWizardMemberPage.value;
    const savedMemberId = memberId.value;
    try {
        if (memberId.value === null) {
            await api.post(`/households/${householdId}/persons`, body);
            memberEditorOpen.value = false;
        } else {
            await api.put(
                `/households/${householdId}/persons/${memberId.value}`,
                body,
            );
            memberEditorOpen.value = false;
        }
        await refreshHouseholdEditPersons(householdId);
        if (householdWizardMember && createdHouseholdId.value !== null) {
            clearHouseholdMemberDraft(createdHouseholdId.value, savedMemberId);
            setHouseholdUrl(
                `/households/${createdHouseholdId.value}/persons`,
                true,
            );
        }
        notice.value = "";
        if (
            !editing.value &&
            props.moduleKey === "households" &&
            memberIsHead.value
        ) {
            createHouseholdWizardStep.value = 3;
        }
    } catch (cause) {
        memberFormErrors.value = validationErrors(cause);
        if (Object.keys(memberFormErrors.value).length > 0) {
            await nextTick();
            scrollToFirstMemberError();
        } else {
            managementResult.value = {
                success: false,
                title: "Không thể lưu nhân khẩu",
                message: errorMessage(cause),
            };
        }
    } finally {
        memberBusy.value = false;
    }
}

function scrollToFirstMemberError(): void {
    const formElement = memberEditorForm.value;
    if (!formElement) return;

    const errorElement = Array.from(
        formElement.querySelectorAll<HTMLElement>(".field-error"),
    ).find(
        (element) =>
            element.textContent?.trim() && element.getClientRects().length > 0,
    );
    const field = errorElement?.closest<HTMLElement>(".workspace-field");
    if (!field) return;

    field.scrollIntoView({ behavior: "smooth", block: "center" });
    field
        .querySelector<HTMLElement>(
            "input:not([type='hidden']), select, textarea",
        )
        ?.focus({ preventScroll: true });
}

async function finishHouseholdCreation(): Promise<void> {
    const householdId = createdHouseholdId.value;
    if (householdId === null) return;
    clearHouseholdCreationDraft(householdId);
    configurationFormOpen.value = false;
    memberEditorOpen.value = false;
    await openHousehold({ id: householdId });
    createdHouseholdId.value = null;
    createHouseholdWizardStep.value = 1;
    setHouseholdUrl("/households", true);
}

async function refreshHouseholdEditPersons(
    householdId: number | string,
): Promise<void> {
    const response = await api.get<unknown>(
        `/households/${householdId}/persons`,
        { params: { per_page: 100 } },
    );
    const page = apiPage<Row>(response.data);
    householdEditPersons.value = normalizeInlineHouseholdPersons(page.data);
    saveInlineHouseholdPersonBaseline(householdEditPersons.value);
    if (String(householdDetail.value?.id) === String(householdId)) {
        await loadHouseholdDetailPage(householdDetailPage.value, true);
    }
}

function normalizeInlineHouseholdPersons(persons: Row[]): Row[] {
    return persons.map((person) => {
        const educations = Array.isArray(person.educations)
            ? (person.educations as Row[])
            : [];
        const education =
            educations.find(
                (item) =>
                    String(item.school_year ?? "") ===
                    String(form.value.school_year ?? ""),
            ) ??
            educations[0] ??
            {};
        const educationYearRange = (value: unknown): string => {
            const text = String(value ?? "");
            return /^\d{4}$/.test(text)
                ? `${text}-${Number(text) + 1}`
                : text;
        };

        return {
            ...person,
            dob: normalizeInlineDate(person.dob),
            religion: person.religion ?? "",
            priority_type: person.priority_type ?? "",
            relationship_with_head: person.relationship_with_head ?? "",
            parent_name: person.parent_name ?? "",
            phone: person.phone ?? "",
            note: person.note ?? "",
            education: {
                school_year:
                    education.school_year ?? form.value.school_year ?? "",
                academic_block: education.academic_block ?? "",
                current_class: education.current_class ?? "",
                school_code: education.school_code ?? "",
                graduation_level: education.graduation_level ?? "",
                is_complementary: education.is_complementary ?? false,
                graduation_year: educationYearRange(
                    education.graduation_year,
                ),
                vocational_grad_level: education.vocational_grad_level ?? "",
                vocational_grad_year: educationYearRange(
                    education.vocational_grad_year,
                ),
                finished_class: education.finished_class ?? "",
                finished_year: educationYearRange(education.finished_year),
                dropped_class: education.dropped_class ?? "",
                dropped_year: educationYearRange(education.dropped_year),
                literacy_current_class: education.literacy_current_class ?? "",
                literacy_completed_class:
                    education.literacy_completed_class ?? "",
                literacy_relapse_level: education.literacy_relapse_level ?? "",
                learning_capacity: education.learning_capacity ?? "",
            },
            _school_name: pathValue(education, "school.name") ?? "",
            disability: {
                ...((typeof person.disability === "object" &&
                person.disability !== null
                    ? person.disability
                    : {}) as Row),
                mobility_disability:
                    (person.disability as Row)?.mobility_disability ?? false,
                hearing_speech_disability:
                    (person.disability as Row)?.hearing_speech_disability ??
                    false,
                visual_disability:
                    (person.disability as Row)?.visual_disability ?? false,
                mental_disability:
                    (person.disability as Row)?.mental_disability ?? false,
                intellectual_disability:
                    (person.disability as Row)?.intellectual_disability ??
                    false,
                learning_disability:
                    (person.disability as Row)?.learning_disability ?? false,
                autism: (person.disability as Row)?.autism ?? false,
                other_disability:
                    (person.disability as Row)?.other_disability ?? false,
                has_disability_cert:
                    (person.disability as Row)?.has_disability_cert ?? false,
                can_study: (person.disability as Row)?.can_study ?? false,
                special_circumstance:
                    (person.disability as Row)?.special_circumstance ?? "",
                special_circumstance_detail:
                    (person.disability as Row)?.special_circumstance_detail ??
                    "",
            },
        };
    });
}

function ensureLegacyHouseholdHead(
    persons: Row[],
    household: Row,
    schoolYear: string,
): Row[] {
    if (
        persons.some(
            (person) =>
                String(person.relationship_with_head ?? "") === "Chủ hộ",
        )
    ) {
        return persons;
    }

    const lastName = String(household.head_last_name ?? "").trim();
    const firstName = String(household.head_first_name ?? "").trim();
    if (!lastName && !firstName) {
        return persons;
    }

    return [
        {
            _inline_new: true,
            last_name: lastName,
            first_name: firstName,
            relationship_with_head: "Chủ hộ",
            dob: "",
            gender: "",
            ethnicity_code: "",
            religion: "",
            priority_type: "",
            parent_name: "",
            phone: "",
            note: "",
            education: {
                school_year: schoolYear,
                academic_block: "",
                current_class: "",
                school_code: "",
                graduation_level: "",
                is_complementary: false,
                graduation_year: "",
                vocational_grad_level: "",
                vocational_grad_year: "",
                finished_class: "",
                finished_year: "",
                dropped_class: "",
                dropped_year: "",
                literacy_current_class: "",
                literacy_completed_class: "",
                literacy_relapse_level: "",
                learning_capacity: "",
            },
            disability: {},
        },
        ...persons,
    ];
}

function inlineHouseholdPersonKey(person: Row, index?: number): string {
    return person.id === undefined || person.id === null
        ? `new:${index ?? 0}`
        : String(person.id);
}

function inlineHouseholdPersonSnapshot(person: Row): string {
    return JSON.stringify({
        first_name: person.first_name ?? "",
        last_name: person.last_name ?? "",
        dob: person.dob ?? "",
        gender: person.gender ?? "",
        ethnicity_code: person.ethnicity_code ?? "",
        relationship_with_head: person.relationship_with_head ?? "",
        religion: person.religion ?? "",
        priority_type: person.priority_type ?? "",
        parent_name: person.parent_name ?? "",
        phone: person.phone ?? "",
        note: person.note ?? "",
        education: person.education ?? {},
        disability: person.disability ?? {},
    });
}

function inlineHouseholdPersonChanged(person: Row, index: number): boolean {
    if (
        person.id === undefined ||
        person.id === null ||
        person._inline_new === true
    )
        return true;
    const key = inlineHouseholdPersonKey(person, index);
    return (
        inlineHouseholdPersonBaseline.value[key] !==
        inlineHouseholdPersonSnapshot(person)
    );
}

function saveInlineHouseholdPersonBaseline(persons: Row[]): void {
    inlineHouseholdPersonBaseline.value = Object.fromEntries(
        persons.map((person, index) => [
            inlineHouseholdPersonKey(person, index),
            inlineHouseholdPersonSnapshot(person),
        ]),
    );
}

function normalizeInlineDate(value: unknown): string {
    const date = String(value ?? "").trim();
    return /^\d{4}-\d{2}-\d{2}/.test(date) ? date.slice(0, 10) : "";
}

function inlineEducation(person: Row): Row {
    if (typeof person.education !== "object" || person.education === null) {
        person.education = {};
    }
    return person.education as Row;
}

function inlineSchoolGrade(person: Row): string {
    const education = inlineEducation(person);
    return (
        schoolLevelFromClassName(education.current_class) ||
        gradeFromClassName(education.current_class) ||
        gradeFromClassName(education.academic_block)
    );
}

function inlineSchoolOptionsKey(person: Row): string {
    const householdId =
        householdDetail.value?.id ?? selected.value?.id ?? "";
    return `${String(householdId)}:${inlineSchoolGrade(person)}`;
}

async function loadInlineSchoolOptions(person: Row): Promise<void> {
    const grade = inlineSchoolGrade(person);
    const householdId =
        householdDetail.value?.id ?? selected.value?.id;
    if (!grade || householdId === undefined || householdId === null) return;

    const key = `${String(householdId)}:${grade}`;
    if (inlineSchoolOptions.value[key] || inlineSchoolRequests.has(key)) {
        await inlineSchoolRequests.get(key);
        return;
    }

    inlineSchoolOptionsLoading.value[key] = true;
    inlineSchoolOptionsErrors.value[key] = "";
    const schoolLevel = schoolLevelFromClassName(
        inlineEducation(person).current_class,
    );
    const request = api
        .get<unknown>(`/households/${householdId}/persons/schools`, {
            params: schoolLevel
                ? { school_level: schoolLevel }
                : { academic_block: grade },
        })
        .then(({ data }) => {
            inlineSchoolOptions.value[key] = asRows(
                apiData<Row>(data).schools,
            );
        })
        .catch((cause: unknown) => {
            inlineSchoolOptionsErrors.value[key] = errorMessage(cause);
        })
        .finally(() => {
            inlineSchoolOptionsLoading.value[key] = false;
            inlineSchoolRequests.delete(key);
        });
    inlineSchoolRequests.set(key, request);
    await request;
}

function updateInlineClass(person: Row, event: Event): void {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;

    const education = inlineEducation(person);
    const academicBlock = academicBlockFromClassName(input.value);
    if (academicBlock !== String(education.academic_block ?? "")) {
        education.school_code = "";
        person._school_name = "";
    }
    education.academic_block = academicBlock;
    if (inlineSchoolGrade(person)) void loadInlineSchoolOptions(person);
}

function inlineSchoolChanged(person: Row): void {
    const education = inlineEducation(person);
    const school = (inlineSchoolOptions.value[inlineSchoolOptionsKey(person)] ?? [])
        .find((item) => String(item.code) === String(education.school_code));
    person._school_name = school?.name ?? education.school_code ?? "";
}

function inlineSchoolYearStart(person: Row): string {
    const value = String(inlineEducation(person).school_year ?? "");
    return value.match(/^(\d{4})(?:-\d{4})?$/)?.[1] ?? value;
}

function inlineSchoolYearEnd(person: Row): string {
    const value = String(inlineEducation(person).school_year ?? "");
    const range = value.match(/^\d{4}-(\d{4})$/);
    if (range) return range[1];

    return /^\d{4}$/.test(value) ? String(Number(value) + 1) : "";
}

function updateInlineSchoolYear(person: Row, event: Event): void {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;

    const value = input.value.trim();
    const yearRange = /^\d{4}$/.test(value)
        ? `${value}-${Number(value) + 1}`
        : value;
    inlineEducation(person).school_year = yearRange;
}

function inlineEducationYearStart(person: Row, field: string): string {
    const value = String(inlineEducation(person)[field] ?? "");
    return value.match(/^(\d{4})(?:-\d{4})?$/)?.[1] ?? value;
}

function inlineEducationYearEnd(person: Row, field: string): string {
    const value = String(inlineEducation(person)[field] ?? "");
    const range = value.match(/^(\d{4})-(\d{4})$/);
    if (range) return range[2];

    return /^\d{4}$/.test(value) ? String(Number(value) + 1) : "";
}

function inlineEducationYearEndHint(person: Row, field: string): string {
    return inlineEducationYearEnd(person, field) || "YYYY";
}

function updateInlineEducationYear(
    person: Row,
    field: string,
    event: Event,
): void {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;

    const value = input.value.trim();
    inlineEducation(person)[field] = /^\d{4}$/.test(value)
        ? `${value}-${Number(value) + 1}`
        : value;
}

function sanitizeInlineMemberPhone(person: Row, event: Event): void {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;

    const value = input.value.replace(/[^\d+]/g, "");
    const phone = value.startsWith("+")
        ? `+${value.slice(1).replace(/\+/g, "")}`
        : value.replace(/\+/g, "");
    person.phone = phone;
    input.value = phone;
}

function inlineMemberValidationError(
    person: Row,
): { field: string; message: string } | null {
    const value = (field: string): string => String(person[field] ?? "").trim();
    const education = inlineEducation(person);
    const educationValue = (field: string): string =>
        String(education[field] ?? "").trim();
    const personNamePattern =
        /^[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u;
    const orderedFields: Array<[string, string, unknown]> = [
        ["last_name", "Họ và tên đệm", person.last_name],
        ["first_name", "Tên", person.first_name],
        ["relationship_with_head", "Quan hệ", person.relationship_with_head],
        ["dob", "Ngày sinh", person.dob],
        ["gender", "Giới tính", person.gender],
        ["ethnicity_code", "Dân tộc", person.ethnicity_code],
        ["school_year", "Năm học", education.school_year],
    ];
    const missingField = orderedFields.find(
        ([, , fieldValue]) => !String(fieldValue ?? "").trim(),
    );
    if (missingField) {
        return {
            field: missingField[0],
            message: `Vui lòng nhập ${missingField[1].toLocaleLowerCase("vi-VN")}.`,
        };
    }

    for (const [field, label, maxLength] of [
        ["last_name", "Họ và tên đệm", 50],
        ["first_name", "Tên", 100],
        ["parent_name", "Họ tên cha hoặc mẹ", 150],
    ] as const) {
        const text = value(field);
        if (text && !personNamePattern.test(text)) {
            return { field, message: `${label} chỉ được chứa chữ cái, khoảng trắng, dấu nháy hoặc dấu gạch nối.` };
        }
        if (text.length > maxLength) {
            return { field, message: `${label} không được vượt quá ${maxLength} ký tự.` };
        }
    }

    const phone = value("phone");
    if (phone && !/^\+?[0-9]+$/.test(phone)) {
        return { field: "phone", message: "Số điện thoại chỉ gồm chữ số và có thể bắt đầu bằng dấu +." };
    }
    if (phone.length > 20) {
        return { field: "phone", message: "Số điện thoại không được vượt quá 20 ký tự." };
    }

    for (const [field, label, maxLength] of [
        ["religion", "Tôn giáo", 50],
        ["priority_type", "Diện ưu tiên", 100],
        ["note", "Ghi chú", 2000],
    ] as const) {
        if (value(field).length > maxLength) {
            return { field, message: `${label} không được vượt quá ${maxLength} ký tự.` };
        }
    }

    const relationship = value("relationship_with_head");
    if (relationship.length > 100) {
        return { field: "relationship_with_head", message: "Quan hệ với chủ hộ không được vượt quá 100 ký tự." };
    }

    const dob = value("dob");
    const today = new Date();
    const todayString = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
    if (
        !/^\d{4}-\d{2}-\d{2}$/.test(dob) ||
        Number.isNaN(Date.parse(`${dob}T00:00:00`))
    ) {
        return { field: "dob", message: "Ngày sinh không hợp lệ." };
    }
    if (dob > todayString) {
        return { field: "dob", message: "Ngày sinh không được lớn hơn ngày hiện tại." };
    }
    if (!["NAM", "NU"].includes(value("gender"))) {
        return { field: "gender", message: "Vui lòng chọn giới tính hợp lệ." };
    }

    const schoolYear = educationValue("school_year");
    const yearMatch = schoolYear.match(/^(\d{4})-(\d{4})$/);
    const year = Number(yearMatch?.[1]);
    const now = new Date();
    const latestYear =
        now.getFullYear() - (now.getMonth() < 7 ? 1 : 0);
    if (
        !yearMatch ||
        Number(yearMatch[2]) !== year + 1 ||
        year < 2020 ||
        year > latestYear
    ) {
        return { field: "school_year", message: "Năm học không hợp lệ." };
    }

    if (educationValue("academic_block").length > 50) {
        return { field: "academic_block", message: "Khối học không được vượt quá 50 ký tự." };
    }

    const classFields = [
        ["current_class", "Lớp đang học"],
        ["finished_class", "Lớp đã hoàn thành"],
        ["dropped_class", "Lớp bỏ học"],
    ] as const;
    for (const [field, label] of classFields) {
        const className = educationValue(field);
        if (className.length > (field === "current_class" ? 50 : 20)) {
            return { field, message: `${label} vượt quá số ký tự cho phép.` };
        }
        const gradeMatch = className.match(/^\s*(?:lớp\s*)?(\d+)/iu);
        if (gradeMatch && Number(gradeMatch[1]) > 12) {
            return { field, message: `${label} không được lớn hơn lớp 12.` };
        }
    }

    const graduationLevel = educationValue("graduation_level");
    if (
        graduationLevel &&
        !["MN", "TH", "THCS", "THPT"].includes(graduationLevel)
    ) {
        return { field: "graduation_level", message: "Trình độ phổ cập không hợp lệ." };
    }
    if (educationValue("vocational_grad_level").length > 50) {
        return { field: "vocational_grad_level", message: "Trình độ tốt nghiệp nghề không được vượt quá 50 ký tự." };
    }

    for (const [field, label] of [
        ["graduation_year", "Năm tốt nghiệp văn hóa"],
        ["vocational_grad_year", "Năm tốt nghiệp nghề"],
        ["finished_year", "Năm hoàn thành lớp"],
        ["dropped_year", "Năm bỏ học"],
    ] as const) {
        const text = educationValue(field);
        if (!text) continue;
        if (text.length > 20 || !/^\d{4}(?:-\d{4})?$/.test(text)) {
            return { field, message: `${label} phải có dạng YYYY hoặc YYYY-YYYY.` };
        }
        const years = text.split("-").map(Number);
        const nextYearRange = `${now.getFullYear()}-${now.getFullYear() + 1}`;
        if (
            years.some((item) => item > now.getFullYear()) &&
            text !== nextYearRange
        ) {
            return { field, message: `${label} không được lớn hơn năm hiện tại.` };
        }
    }

    for (const [field, label, maxLength] of [
        ["literacy_current_class", "Lớp xóa mù chữ đang học", 20],
        ["literacy_completed_class", "Lớp xóa mù chữ đã hoàn thành", 20],
        ["learning_capacity", "Năng lực học tập", 50],
    ] as const) {
        if (educationValue(field).length > maxLength) {
            return { field, message: `${label} không được vượt quá ${maxLength} ký tự.` };
        }
    }

    const relapseLevel = educationValue("literacy_relapse_level");
    if (relapseLevel && !["1", "2"].includes(relapseLevel)) {
        return { field: "literacy_relapse_level", message: "Mức độ tái mù chữ phải là 1 hoặc 2." };
    }
    if (
        person.relationship_with_head !== "Chủ hộ" &&
        value("relationship_with_head") === "Chủ hộ"
    ) {
        return { field: "relationship_with_head", message: "Thành viên không thể có quan hệ là chủ hộ." };
    }
    const slotError = relationshipSlotError(
        person.relationship_with_head,
        person,
    );
    if (slotError) return { field: "relationship_with_head", message: slotError };
    const specialCircumstance = String(
        inlineDisability(person).special_circumstance ?? "",
    );
    if (
        specialCircumstance &&
        !["Chuyển đến", "Chuyển đi"].includes(specialCircumstance)
    ) {
        return { field: "special_circumstance", message: "Hoàn cảnh đặc biệt không hợp lệ." };
    }
    if (
        String(inlineDisability(person).special_circumstance_detail ?? "")
            .length > 2000
    ) {
        return { field: "special_circumstance_detail", message: "Chi tiết hoàn cảnh đặc biệt không được vượt quá 2000 ký tự." };
    }

    return null;
}

const inlineMemberFieldColumns: Record<string, number> = {
    last_name: 1,
    first_name: 2,
    relationship_with_head: 3,
    dob: 4,
    gender: 5,
    ethnicity_code: 6,
    religion: 7,
    priority_type: 8,
    parent_name: 9,
    phone: 10,
    note: 11,
    school_year: 12,
    current_class: 13,
    academic_block: 14,
    school_code: 15,
    is_complementary: 16,
    graduation_level: 17,
    graduation_year: 18,
    vocational_grad_level: 19,
    vocational_grad_year: 20,
    finished_class: 21,
    finished_year: 22,
    dropped_class: 23,
    dropped_year: 24,
    literacy_current_class: 25,
    literacy_completed_class: 26,
    literacy_relapse_level: 27,
    learning_capacity: 28,
    mobility_disability: 29,
    hearing_speech_disability: 30,
    visual_disability: 31,
    mental_disability: 32,
    intellectual_disability: 33,
    learning_disability: 34,
    autism: 35,
    other_disability: 36,
    has_disability_cert: 37,
    can_study: 38,
    special_circumstance: 39,
    special_circumstance_detail: 40,
};

function markInlineMemberFieldErrors(
    index: number,
    errors: Record<string, string>,
): void {
    const row = document.querySelector<HTMLTableRowElement>(
        `.household-edit-members-table tr[data-member-index="${index}"]`,
    );
    if (!row) return;

    for (const [fieldPath, message] of Object.entries(errors)) {
        const field = fieldPath.split(".").at(-1) ?? fieldPath;
        const cell = row.cells[inlineMemberFieldColumns[field]];
        if (!cell) continue;
        cell.classList.add("household-edit-member-invalid");
        cell.title = message;
        cell.querySelectorAll<HTMLElement>("input, select, button").forEach(
            (control) => control.setAttribute("aria-invalid", "true"),
        );
    }
}

function clearInlineMemberRowErrors(index: number): void {
    const row = document.querySelector<HTMLElement>(
        `.household-edit-members-table tr[data-member-index="${index}"]`,
    );
    row?.querySelectorAll(".household-edit-member-invalid").forEach((cell) => {
        cell.classList.remove("household-edit-member-invalid");
        cell.removeAttribute("title");
        cell
            .querySelectorAll<HTMLElement>("[aria-invalid='true']")
            .forEach((control) => control.removeAttribute("aria-invalid"));
    });
}

function clearInlineMemberFieldError(event: Event): void {
    const control = event.target;
    if (!(control instanceof HTMLElement)) return;
    const cell = control.closest("td.household-edit-member-invalid");
    if (!cell) return;
    cell.classList.remove("household-edit-member-invalid");
    cell.removeAttribute("title");
    cell.querySelectorAll<HTMLElement>("[aria-invalid='true']").forEach(
        (invalidControl) => invalidControl.removeAttribute("aria-invalid"),
    );
}

function inlineDisability(person: Row): Row {
    if (typeof person.disability !== "object" || person.disability === null) {
        person.disability = {};
    }
    return person.disability as Row;
}

async function addInlineHouseholdMember(): Promise<void> {
    householdEditPersons.value.push({
        _inline_new: true,
        last_name: "",
        first_name: "",
        relationship_with_head: "Con",
        dob: "",
        gender: "NAM",
        ethnicity_code: "",
        religion: "",
        priority_type: "",
        parent_name: "",
        phone: "",
        note: "",
        education: {
            school_year: String(
                form.value.school_year ??
                    householdDetail.value?.school_year ??
                    "",
            ),
            academic_block: "",
            current_class: "",
            school_code: "",
            graduation_level: "",
            is_complementary: false,
            graduation_year: "",
            vocational_grad_level: "",
            vocational_grad_year: "",
            finished_class: "",
            finished_year: "",
            dropped_class: "",
            dropped_year: "",
            literacy_current_class: "",
            literacy_completed_class: "",
            literacy_relapse_level: "",
            learning_capacity: "",
        },
        disability: {
            mobility_disability: false,
            hearing_speech_disability: false,
            visual_disability: false,
            mental_disability: false,
            intellectual_disability: false,
            learning_disability: false,
            autism: false,
            other_disability: false,
            has_disability_cert: false,
            can_study: false,
            special_circumstance: "",
            special_circumstance_detail: "",
        },
    });

    householdEditPage.value = householdEditPageCount.value;
    await nextTick();
    const newRowIndex = householdEditPersons.value.length - 1;
    const input = document.querySelector<HTMLInputElement>(
        `.household-edit-member-last-name[data-member-index="${newRowIndex}"]`,
    );
    if (input) {
        input.focus({ preventScroll: true });
        const tableWrap = input.closest(".household-edit-members-table-wrap");
        const row = input.closest("tr");
        if (tableWrap && row) {
            tableWrap.scrollTop = Math.max(
                0,
                row.offsetTop - tableWrap.clientHeight / 2,
            );
            tableWrap.scrollLeft = 0;
        }
    }
}

async function saveInlineHouseholdMember(
    person: Row,
    index: number,
    showResult = true,
): Promise<boolean> {
    const householdIdValue = householdDetail.value?.id ?? selected.value?.id;
    const householdId =
        householdIdValue === undefined || householdIdValue === null
            ? ""
            : String(householdIdValue);
    if (!householdId || memberBusy.value) return false;

    clearInlineMemberRowErrors(index);
    const validationIssue = inlineMemberValidationError(person);
    if (validationIssue) {
        markInlineMemberFieldErrors(index, {
            [validationIssue.field]: validationIssue.message,
        });
        error.value = validationIssue.message;
        managementResult.value = {
            success: false,
            title: "Không thể lưu nhân khẩu",
            message: validationIssue.message,
        };
        return false;
    }

    memberBusy.value = true;
    error.value = "";
    try {
        let education: Row = {
            school_year: String(
                form.value.school_year ??
                    householdDetail.value?.school_year ??
                    "",
            ),
            is_complementary: "0",
            ...(typeof person.education === "object" &&
            person.education !== null
                ? (person.education as Row)
                : {}),
        };
        let disability: Row =
            typeof person.disability === "object" && person.disability !== null
                ? (person.disability as Row)
                : {};
        let ethnicityCode = String(person.ethnicity_code ?? "");

        education = {
            ...education,
            ...(typeof person.education === "object" &&
            person.education !== null
                ? (person.education as Row)
                : {}),
        };
        disability = {
            ...disability,
            ...(typeof person.disability === "object" &&
            person.disability !== null
                ? (person.disability as Row)
                : {}),
        };

        const body = {
            first_name: String(person.first_name ?? ""),
            last_name: String(person.last_name ?? ""),
            dob: String(person.dob ?? ""),
            gender: String(person.gender ?? ""),
            ethnicity_code: ethnicityCode,
            relationship_with_head: String(person.relationship_with_head ?? ""),
            religion: String(person.religion ?? ""),
            priority_type: String(person.priority_type ?? ""),
            parent_name: String(person.parent_name ?? ""),
            phone: String(person.phone ?? ""),
            note: String(person.note ?? ""),
            is_head: person.relationship_with_head === "Chủ hộ",
            education,
            school_province_code: "",
            school_commune_code: "",
            disability,
        };

        if (person.id === undefined) {
            await api.post(`/households/${householdId}/persons`, body);
        } else {
            await api.put(
                `/households/${householdId}/persons/${person.id}`,
                body,
            );
        }
        if (showResult) {
            await refreshHouseholdEditPersons(householdId);
            managementResult.value = {
                success: true,
                title:
                    person.id === undefined
                        ? "Thêm nhân khẩu thành công"
                        : "Sửa nhân khẩu thành công",
                message:
                    person.id === undefined
                        ? "Nhân khẩu mới đã được thêm vào phiếu."
                        : "Thông tin nhân khẩu đã được cập nhật.",
            };
        }
        return true;
    } catch (cause) {
        error.value = errorMessage(cause);
        const fieldErrors = validationErrors(cause);
        if (Object.keys(fieldErrors).length > 0) {
            markInlineMemberFieldErrors(index, fieldErrors);
        }
        if (showResult)
            managementResult.value = {
                success: false,
                title: "Không thể lưu nhân khẩu",
                message: errorMessage(cause),
            };
        return false;
    } finally {
        memberBusy.value = false;
    }
}

async function deleteMember(person: Row): Promise<void> {
    if (person.id === undefined) return;
    if (person.relationship_with_head === "Chủ hộ") {
        managementResult.value = {
            success: false,
            title: "Không thể xóa chủ hộ",
            message: "Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.",
        };
        return;
    }
    error.value = "";
    notice.value = "";
    pendingHouseholdPersonDelete.value = person;
}

async function confirmDeleteMember(): Promise<void> {
    const householdIdValue = householdDetail.value?.id ?? selected.value?.id;
    const householdId =
        householdIdValue === undefined || householdIdValue === null
            ? ""
            : String(householdIdValue);
    const person = pendingHouseholdPersonDelete.value;
    if (!householdId || person?.id === undefined || memberBusy.value) return;
    memberBusy.value = true;
    error.value = "";
    try {
        const response = await api.delete<unknown>(
            `/households/${householdId}/persons/${person.id}`,
        );
        const result = apiData<Row>(response.data);
        if (householdDetail.value) await openHousehold({ id: householdId });
        await refreshHouseholdEditPersons(householdId);
        pendingHouseholdPersonDelete.value = null;
        managementResult.value = {
            success: true,
            title: "Xóa nhân khẩu thành công",
            message: String(
                result.message ?? "Nhân khẩu đã được xóa khỏi phiếu.",
            ),
        };
    } catch (cause) {
        error.value = errorMessage(cause);
        managementResult.value = {
            success: false,
            title: "Không thể xóa nhân khẩu",
            message: errorMessage(cause),
        };
    } finally {
        memberBusy.value = false;
    }
}

function payload(): Row {
    const data: Row = { ...form.value };
    if (props.moduleKey === "roles") {
        data.permissions = permissionIds.value
            .map(Number)
            .filter(Number.isInteger);
    }
    if (props.moduleKey === "users") {
        if (
            currentActorScopeLevel.value === "commune" &&
            form.value.scope_level === "commune"
        ) {
            data.village_codes =
                selectedRoleUsesVillageScope.value &&
                !selectedRoleManagesAllVillages.value
                    ? selectedUserVillageCodes.value
                    : [];
        } else {
            delete data.village_codes;
        }
        data.is_active = form.value.is_active === "1";
        if (editing.value && !form.value.password) {
            delete data.password;
            delete data.password_confirmation;
        }
    }
    if (props.moduleKey === "schools") {
        data.is_active = form.value.is_active === "1";
    }
    if (props.moduleKey === "merge") {
        data.source_codes = selectedSourceCodes.value;
    }
    if (props.moduleKey === "villages" || props.moduleKey === "schools") {
        if (!form.value.commune_code) data.commune_code = null;
    }
    return data;
}

function villageMatchesFilters(village: Row): boolean {
    const filters = props.villageFilters;
    if (!filters) return true;

    const commune =
        typeof village.commune === "object" && village.commune !== null
            ? (village.commune as Row)
            : {};
    const province =
        typeof commune.province === "object" && commune.province !== null
            ? (commune.province as Row)
            : {};
    const provinceCode = String(
        village.province_code ?? commune.province_code ?? province.code ?? "",
    );
    if (filters.province_code && provinceCode !== filters.province_code)
        return false;
    if (
        filters.commune_code &&
        String(village.commune_code ?? commune.code ?? "") !==
            filters.commune_code
    )
        return false;
    const query = filters.q.trim().toLocaleLowerCase("vi");
    return (
        !query ||
        [village.code, village.name].some((value) =>
            String(value ?? "")
                .toLocaleLowerCase("vi")
                .includes(query),
        )
    );
}

function updateVillageList(village: Row, wasEditing: boolean): void {
    const code = String(village.code ?? "");
    if (!code) return;

    const wasListed = rows.value.some((row) => String(row.code ?? "") === code);
    const matchesFilters = villageMatchesFilters(village);
    if (wasEditing && wasListed && !matchesFilters) {
        rows.value = rows.value.filter(
            (row) => String(row.code ?? "") !== code,
        );
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        return;
    }

    if (!matchesFilters) return;
    const updatedRows = rows.value.filter(
        (row) => String(row.code ?? "") !== code,
    );
    if (wasListed || currentPage.value === 1) {
        updatedRows.push(village);
        updatedRows.sort((left, right) =>
            String(left.name ?? "").localeCompare(
                String(right.name ?? ""),
                "vi",
            ),
        );
        rows.value = updatedRows.slice(0, villagePageSize.value);
    }
    if (!wasListed) totalRecords.value += 1;
}

function schoolMatchesFilters(school: Row): boolean {
    const filters = appliedSchoolFilters.value;
    const province =
        typeof school.province === "object" && school.province !== null
            ? (school.province as Row)
            : {};
    const commune =
        typeof school.commune === "object" && school.commune !== null
            ? (school.commune as Row)
            : {};
    if (
        filters.province_code &&
        String(school.province_code ?? province.code ?? "") !==
            filters.province_code
    )
        return false;
    if (
        filters.commune_code &&
        String(school.commune_code ?? commune.code ?? "") !==
            filters.commune_code
    )
        return false;
    if (filters.level && String(school.level ?? "") !== filters.level)
        return false;
    const query = filters.q.trim().toLocaleLowerCase("vi");
    return (
        !query ||
        [school.code, school.name].some((value) =>
            String(value ?? "")
                .toLocaleLowerCase("vi")
                .includes(query),
        )
    );
}

function updateSchoolList(school: Row, wasEditing: boolean): void {
    const id = String(school.id ?? "");
    if (!id) return;

    const wasListed = rows.value.some((row) => String(row.id ?? "") === id);
    const matchesFilters = schoolMatchesFilters(school);
    if (wasEditing && wasListed && !matchesFilters) {
        rows.value = rows.value.filter((row) => String(row.id ?? "") !== id);
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        return;
    }

    if (!matchesFilters) return;
    const updatedRows = rows.value.filter((row) => String(row.id ?? "") !== id);
    if (wasListed || currentPage.value === 1) {
        updatedRows.push(school);
        updatedRows.sort((left, right) =>
            String(left.name ?? "").localeCompare(
                String(right.name ?? ""),
                "vi",
            ),
        );
        rows.value = updatedRows.slice(0, schoolPageSize.value);
    }
    if (!wasListed) totalRecords.value += 1;
}

function villageFormState(): string {
    return JSON.stringify({
        name: form.value.name?.trim() ?? "",
        province_code: form.value.province_code ?? "",
        commune_code: form.value.commune_code ?? "",
        operational_status: form.value.operational_status ?? "active",
    });
}

function schoolFormState(): string {
    return JSON.stringify({
        name: form.value.name?.trim() ?? "",
        type: form.value.type ?? "",
        level: form.value.level ?? "",
        is_active: form.value.is_active ?? "1",
        province_code: form.value.province_code ?? "",
        commune_code: form.value.commune_code ?? "",
    });
}

function householdFormState(): string {
    return JSON.stringify(
        Object.fromEntries(
            Object.keys(newFormValues()).map((field) => [
                field,
                form.value[field]?.trim() ?? "",
            ]),
        ),
    );
}

function userMatchesCurrentFilters(user: Row): boolean {
    if (
        userFilterProvince.value &&
        String(user.province_code ?? "") !== userFilterProvince.value
    )
        return false;
    if (
        userFilterCommune.value &&
        String(user.commune_code ?? "") !== userFilterCommune.value
    )
        return false;
    if (
        userFilterRole.value &&
        String(user.role_id ?? "") !== userFilterRole.value
    )
        return false;
    if (userFilterVillage.value) {
        const villageCodes = Array.isArray(user.village_codes)
            ? user.village_codes.map(String)
            : [];
        if (!villageCodes.includes(userFilterVillage.value)) return false;
    }

    const query = userFilterQuery.value.trim().toLocaleLowerCase();
    return (
        !query ||
        [user.name, user.username].some((value) =>
            String(value ?? "")
                .toLocaleLowerCase()
                .includes(query),
        )
    );
}

function userFormState(): string {
    const values = Object.keys(form.value)
        .filter(
            (field) => !["password", "password_confirmation"].includes(field),
        )
        .sort()
        .map((field) => [field, form.value[field] ?? ""]);

    return JSON.stringify({
        values,
        village_codes: [...selectedUserVillageCodes.value].sort(),
    });
}

function updateUserList(userId: unknown, data: Row): void {
    const role = roleOptions.value.find(
        (option) => String(option.id) === String(data.role_id ?? ""),
    );

    rows.value = rows.value.map((row) =>
        String(row.id) === String(userId)
            ? {
                  ...row,
                  name: data.name,
                  username: data.username,
                  email: data.email,
                  phone: data.phone,
                  role_name: role?.name ?? row.role_name,
                  scope_level: data.scope_level,
                  is_active: data.is_active,
              }
            : row,
    );
}

const userFormChanged = computed(
    () =>
        Boolean(form.value.password) ||
        initialUserFormState.value !== userFormState(),
);

async function save(): Promise<void> {
    formErrors.value = {};
    userFormRequestError.value = "";
    roleFormRequestError.value = "";
    managementResult.value = null;
    if (props.moduleKey === "users") {
        if (editing.value && !userFormChanged.value) {
            return;
        }

        userFormSubmitted.value = true;
        if (!userFormReady.value) {
            error.value =
                "Bạn chưa thể lưu tài khoản. Hãy hoàn tất các thông tin bắt buộc bên dưới.";
            return;
        }
    }
    if (props.moduleKey === "roles") {
        roleFormSubmitted.value = true;
        if (!roleFormReady.value) return;
    }
    if (props.moduleKey === "villages") {
        villageFormSubmitted.value = true;
        if (!villageFormReady.value) return;
    }
    if (props.moduleKey === "households") {
        householdFormSubmitted.value = true;
        if (Object.keys(householdFormValidationErrors.value).length > 0) {
            error.value = "";
            return;
        }
    }
    if (props.moduleKey === "schools") {
        schoolFormSubmitted.value = true;
        if (!schoolFormReady.value) return;
    }
    if (props.moduleKey === "merge") {
        if (selectedSourceCodes.value.length === 0) {
            error.value = "Chọn ít nhất một thôn nguồn cần gộp.";
            return;
        }
        if (!selectedSourcesPaused.value) {
            error.value =
                "Hãy tạm dừng nhận phiếu tại tất cả thôn nguồn trước khi gộp.";
            return;
        }
    }
    if (
        props.moduleKey === "villages" &&
        editing.value &&
        initialVillageFormState.value === villageFormState()
    ) {
        closeConfigurationForm();
        deleteResult.value = {
            success: true,
            title: "Không có thay đổi",
            message:
                "Thông tin thôn/xóm không thay đổi; không gửi yêu cầu cập nhật.",
        };
        return;
    }
    if (
        props.moduleKey === "schools" &&
        editing.value &&
        initialSchoolFormState.value === schoolFormState()
    ) {
        closeConfigurationForm();
        deleteResult.value = {
            success: true,
            title: "Không có thay đổi",
            message:
                "Thông tin trường học không thay đổi; không gửi yêu cầu cập nhật.",
        };
        return;
    }
    if (
        props.moduleKey === "households" &&
        editing.value &&
        initialHouseholdFormState.value === householdFormState()
    ) {
        closeConfigurationForm();
        managementResult.value = {
            success: true,
            title: "Không có thay đổi",
            message:
                "Thông tin phiếu điều tra không thay đổi; không gửi yêu cầu cập nhật.",
        };
        return;
    }
    const isUpdatingCreatedHousehold =
        props.moduleKey === "households" &&
        !editing.value &&
        createdHouseholdId.value !== null &&
        createHouseholdWizardStep.value === 1;
    if (
        props.moduleKey === "roles" &&
        editing.value &&
        initialRoleFormState.value === roleFormState()
    ) {
        configurationFormOpen.value = false;
        selected.value = null;
        form.value = {};
        permissionIds.value = [];
        managementResult.value = {
            success: true,
            title: "Không có thay đổi",
            message:
                "Thông tin nhóm người dùng vẫn giữ nguyên; không gửi yêu cầu cập nhật.",
        };
        return;
    }
    const accountDetails =
        props.moduleKey === "users" && !editing.value
            ? {
                  name: form.value.name ?? "",
                  username: form.value.username ?? "",
              }
            : null;
    const roleDetails =
        props.moduleKey === "roles" && !editing.value
            ? { name: form.value.name ?? "" }
            : null;

    const wasEditing = editing.value;
    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        const id = selected.value?.id ?? selected.value?.code;
        const endpoint = info.value.endpoint;
        const body = payload();
        if (
            isUpdatingCreatedHousehold &&
            !String(form.value.household_code ?? "").trim()
        ) {
            delete body.household_code;
        }
        let createdHouseholdIdValue: unknown;
        let createdRole: Row | null = null;
        let createdUser: Row | null = null;
        let savedVillage: Row | null = null;
        let savedSchool: Row | null = null;
        let responseMessage = "";
        const householdFormChanged =
            props.moduleKey === "households" &&
            editing.value &&
            initialHouseholdFormState.value !== householdFormState();
        if (editing.value || isUpdatingCreatedHousehold) {
            const householdId = isUpdatingCreatedHousehold
                ? createdHouseholdId.value
                : id;
            if (
                props.moduleKey === "households" &&
                editing.value &&
                !householdFormChanged
            ) {
                configurationFormOpen.value = false;
                managementResult.value = {
                    success: true,
                    title: "Không có thay đổi",
                    message:
                        "Thông tin phiếu điều tra không thay đổi; không gửi yêu cầu cập nhật.",
                };
                return;
            }
            const response = await api.put<unknown>(
                `${endpoint}/${householdId}`,
                body,
            );
            if (props.moduleKey === "users") {
                updateUserList(householdId, body);
                configurationFormOpen.value = false;
                selected.value = null;
                form.value = {};
                managementResult.value = {
                    success: true,
                    title: "Cập nhật tài khoản thành công",
                    message: `Thông tin tài khoản “${String(body.name ?? "")}” đã được lưu.`,
                };
                return;
            }
            if (props.moduleKey === "villages") {
                const result = apiData<Row>(response.data);
                savedVillage =
                    typeof result.village === "object" &&
                    result.village !== null
                        ? (result.village as Row)
                        : null;
            }
            if (props.moduleKey === "schools") {
                const result = apiData<Row>(response.data);
                savedSchool =
                    typeof result.school === "object" && result.school !== null
                        ? (result.school as Row)
                        : null;
            }
        } else {
            const response = await api.post<unknown>(endpoint, body);
            const created = apiData<Row>(response.data);
            responseMessage = String(created.message ?? "");
            createdHouseholdIdValue = created.id;
            if (
                props.moduleKey === "households" &&
                created.household_code !== undefined
            ) {
                form.value.household_code = String(created.household_code);
            }
            if (props.moduleKey === "users") {
                createdUser =
                    typeof created.user === "object" && created.user !== null
                        ? (created.user as Row)
                        : null;
            } else if (props.moduleKey === "roles") {
                createdRole = { ...created, can_manage: true };
            } else if (props.moduleKey === "villages") {
                savedVillage =
                    typeof created.village === "object" &&
                    created.village !== null
                        ? (created.village as Row)
                        : null;
            } else if (props.moduleKey === "schools") {
                savedSchool =
                    typeof created.school === "object" &&
                    created.school !== null
                        ? (created.school as Row)
                        : null;
            }
        }
        notice.value =
            props.moduleKey === "households" ||
            props.moduleKey === "users" ||
            ["roles", "villages", "schools"].includes(props.moduleKey)
                ? ""
                : wasEditing
                  ? "Đã cập nhật."
                  : "";
        if (
            props.moduleKey === "households" &&
            typeof createdHouseholdIdValue === "number"
        ) {
            if (!editing.value) {
                saveHouseholdCreationDraft(createdHouseholdIdValue, form.value);
                clearHouseholdCreationDraft("new");
                createdHouseholdId.value = createdHouseholdIdValue;
                createHouseholdWizardStep.value = 2;
                void beginMember(true);
                return;
            }
            await openHousehold({ id: createdHouseholdIdValue });
        }
        if (isUpdatingCreatedHousehold) {
            createHouseholdWizardStep.value = 2;
            void beginMember(true);
            return;
        }
        configurationFormOpen.value = false;
        selected.value = null;
        form.value = {};
        if (props.moduleKey === "merge") selectedSourceCodes.value = [];
        initialSchoolFormState.value = null;
        if (props.moduleKey === "villages" && savedVillage) {
            updateVillageList(savedVillage, wasEditing);
            deleteResult.value = {
                success: true,
                title: wasEditing ? "Cập nhật thành công" : "Thêm thành công",
                message: wasEditing
                    ? "Thông tin thôn/xóm đã được cập nhật."
                    : "Thôn/xóm đã được thêm.",
            };
        } else if (props.moduleKey === "schools" && savedSchool) {
            updateSchoolList(savedSchool, wasEditing);
            deleteResult.value = {
                success: true,
                title: wasEditing ? "Cập nhật thành công" : "Thêm thành công",
                message: wasEditing
                    ? "Thông tin trường học đã được cập nhật."
                    : "Trường học đã được thêm.",
            };
        } else if (props.moduleKey === "roles" && wasEditing) {
            const updatedPermissions = permissionIds.value
                .map((id) =>
                    permissionOptions.value.find(
                        (option) => String(option.id) === id,
                    ),
                )
                .filter(
                    (permission): permission is Row => permission !== undefined,
                )
                .map((permission) => String(permission.name));
            rows.value = rows.value.map((row) =>
                String(row.id) === String(id)
                    ? {
                          ...row,
                          name: body.name,
                          description: body.description,
                          scope_level: body.scope_level,
                          permissions: updatedPermissions,
                      }
                    : row,
            );
        } else if (props.moduleKey === "roles" && createdRole) {
            rows.value = [...rows.value, createdRole];
            totalRecords.value += 1;
        } else if (
            props.moduleKey === "users" &&
            !wasEditing &&
            createdUser &&
            userMatchesCurrentFilters(createdUser)
        ) {
            totalRecords.value += 1;
            if (currentPage.value === 1) {
                rows.value = [createdUser, ...rows.value].slice(
                    0,
                    userPageSize.value,
                );
            }
        } else if (
            !["villages", "schools", "households", "users"].includes(
                props.moduleKey,
            )
        ) {
            await load();
        }
        if (props.moduleKey === "merge") {
            deleteResult.value = {
                success: true,
                title: "Gộp thôn thành công",
                message:
                    responseMessage ||
                    "Đã gộp các thôn nguồn vào thôn đích và xóa khỏi danh mục.",
            };
        }
        if (props.moduleKey === "households" && wasEditing) {
            managementResult.value = {
                success: true,
                title: "Cập nhật phiếu điều tra thành công",
                message: "Thông tin phiếu điều tra đã được cập nhật.",
            };
        } else if (accountDetails) {
            managementResult.value = {
                success: true,
                title: "Tạo tài khoản thành công",
                message: `Tài khoản ${accountDetails.name} đã được thêm vào danh sách.`,
                username: accountDetails.username,
            };
        } else if (roleDetails) {
            managementResult.value = {
                success: true,
                title: "Tạo nhóm người dùng thành công",
                message: `Nhóm “${roleDetails.name}” đã được thêm vào danh sách.`,
            };
        } else if (props.moduleKey === "roles" && wasEditing) {
            managementResult.value = {
                success: true,
                title: "Cập nhật nhóm người dùng thành công",
                message: `Nhóm “${String(body.name ?? "")}” đã được cập nhật.`,
            };
        }
    } catch (cause) {
        formErrors.value = validationErrors(cause);
        error.value = errorMessage(cause);
        if (props.moduleKey === "households") {
            managementResult.value = {
                success: false,
                title: "Cập nhật phiếu điều tra thất bại",
                message: errorMessage(cause),
            };
        }
        if (props.moduleKey === "villages") {
            deleteResult.value = {
                success: false,
                title: wasEditing ? "Cập nhật thất bại" : "Thêm thất bại",
                message: error.value,
            };
        }
        if (props.moduleKey === "schools") {
            deleteResult.value = {
                success: false,
                title: wasEditing ? "Cập nhật thất bại" : "Thêm thất bại",
                message: error.value,
            };
        }
        if (accountDetails) {
            if (Object.keys(formErrors.value).length === 0) {
                userFormRequestError.value = `Chưa thể tạo tài khoản. ${error.value} Vui lòng thử lại.`;
            }
            managementResult.value = {
                success: false,
                title: "Tạo tài khoản thất bại",
                message: error.value,
            };
        } else if (props.moduleKey === "users") {
            managementResult.value = {
                success: false,
                title: "Cập nhật tài khoản thất bại",
                message: error.value,
            };
        } else if (props.moduleKey === "roles") {
            roleFormRequestError.value =
                Object.keys(formErrors.value).length === 0
                    ? `Chưa thể lưu nhóm người dùng. ${error.value} Vui lòng thử lại.`
                    : "";
            managementResult.value = {
                success: false,
                title: "Lưu nhóm người dùng thất bại",
                message: error.value,
            };
        }
    } finally {
        busy.value = false;
    }
}

async function remove(row: Row): Promise<void> {
    const id = row.id ?? row.code;
    if (id === undefined) return;
    pendingDelete.value = row;
}

async function confirmRemove(): Promise<void> {
    const row = pendingDelete.value;
    const id = row?.id ?? row?.code;
    if (id === undefined || row === null) return;
    busy.value = true;
    error.value = "";
    try {
        await api.delete(`${info.value.endpoint}/${id}`);
        pendingDelete.value = null;
        if (props.moduleKey === "households") {
            selectedHouseholdIds.value = selectedHouseholdIds.value.filter(
                (selectedId) => String(selectedId) !== String(id),
            );
        }
        deleteResult.value = {
            success: true,
            message:
                props.moduleKey === "imports"
                    ? `Đã xóa lịch sử tệp “${displayImportFileName(row.file_name)}” thành công.`
                    : `Đã xóa “${String(row.name ?? row.code ?? "mục đã chọn")}” thành công.`,
        };
        if (props.moduleKey === "roles") {
            rows.value = rows.value.filter(
                (item) => String(item.id ?? item.code) !== String(id),
            );
            totalRecords.value = Math.max(0, totalRecords.value - 1);
        } else if (props.moduleKey === "villages") {
            const wasListed = rows.value.some(
                (item) => String(item.code ?? "") === String(id),
            );
            rows.value = rows.value.filter(
                (item) => String(item.code ?? "") !== String(id),
            );
            if (wasListed)
                totalRecords.value = Math.max(0, totalRecords.value - 1);
            deleteResult.value = {
                success: true,
                title: "Xóa thành công",
                message: `Thôn/xóm “${String(row.name ?? row.code ?? "")}” đã được xóa.`,
            };
        } else if (props.moduleKey === "schools") {
            const wasListed = rows.value.some(
                (item) => String(item.id ?? "") === String(id),
            );
            rows.value = rows.value.filter(
                (item) => String(item.id ?? "") !== String(id),
            );
            if (wasListed)
                totalRecords.value = Math.max(0, totalRecords.value - 1);
            deleteResult.value = {
                success: true,
                title: "Xóa thành công",
                message: `Trường học “${String(row.name ?? row.code ?? "")}” đã được xóa.`,
            };
        } else if (props.moduleKey === "households") {
            const wasListed = rows.value.some(
                (item) => String(item.id ?? "") === String(id),
            );
            rows.value = rows.value.filter(
                (item) => String(item.id ?? "") !== String(id),
            );
            if (wasListed)
                totalRecords.value = Math.max(0, totalRecords.value - 1);
            deleteResult.value = {
                success: true,
                title: "Xóa phiếu thành công",
                message: `Phiếu điều tra “${String(row.household_code ?? "")}” đã được xóa.`,
            };
            await updateHouseholdPageAfterDelete();
        } else if (props.moduleKey === "imports") {
            const wasListed = importHistory.value.some(
                (item) => String(item.id ?? "") === String(id),
            );
            importHistory.value = importHistory.value.filter(
                (item) => String(item.id ?? "") !== String(id),
            );
            if (wasListed) {
                totalRecords.value = Math.max(0, totalRecords.value - 1);
                lastPage.value = Math.max(1, Math.ceil(totalRecords.value / 4));
                currentPage.value = Math.min(currentPage.value, lastPage.value);
            }
        } else {
            await load();
        }
    } catch (cause) {
        pendingDelete.value = null;
        deleteResult.value = {
            success: false,
            title: ["villages", "schools"].includes(props.moduleKey)
                ? "Xóa thất bại"
                : undefined,
            message: `Không thể xóa mục đã chọn. ${errorMessage(cause)}`,
        };
    } finally {
        busy.value = false;
    }
}

const accountFormChanged = computed(
    () =>
        accountForm.value.name !== initialAccountForm.value.name ||
        accountForm.value.email !== initialAccountForm.value.email ||
        accountForm.value.phone !== initialAccountForm.value.phone ||
        accountForm.value.citizen_id !== initialAccountForm.value.citizen_id ||
        accountForm.value.date_of_birth !==
            initialAccountForm.value.date_of_birth,
);

async function saveAccountProfile(): Promise<void> {
    if (!accountFormChanged.value) return;

    accountFormErrors.value = {};
    if (!accountForm.value.name.trim()) {
        accountFormErrors.value.name = "Vui lòng nhập họ và tên.";
    } else if ([...accountForm.value.name].length > 255) {
        accountFormErrors.value.name =
            "Họ và tên không được vượt quá 255 ký tự.";
    }
    if (
        accountForm.value.email &&
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(accountForm.value.email)
    ) {
        accountFormErrors.value.email = "Email không đúng định dạng.";
    } else if ([...accountForm.value.email].length > 255) {
        accountFormErrors.value.email = "Email không được vượt quá 255 ký tự.";
    }
    if (accountForm.value.phone && !/^\+?\d+$/.test(accountForm.value.phone)) {
        accountFormErrors.value.phone =
            "Số điện thoại chỉ được chứa chữ số hoặc dấu + ở đầu.";
    } else if ([...accountForm.value.phone].length > 20) {
        accountFormErrors.value.phone =
            "Số điện thoại không được vượt quá 20 ký tự.";
    }
    if (
        accountForm.value.citizen_id &&
        !/^\d{12}$/.test(accountForm.value.citizen_id)
    ) {
        accountFormErrors.value.citizen_id = "Số CCCD phải gồm đúng 12 chữ số.";
    }
    if (
        accountForm.value.date_of_birth &&
        accountForm.value.date_of_birth > latestBirthDate
    ) {
        accountFormErrors.value.date_of_birth =
            "Ngày sinh không được lớn hơn ngày hiện tại.";
    }
    if (Object.keys(accountFormErrors.value).length > 0) {
        return;
    }

    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        const response = await api.patch<unknown>(
            "/account",
            accountForm.value,
        );
        profile.value = apiData<Row>(response.data);
        const user =
            typeof profile.value.user === "object" &&
            profile.value.user !== null
                ? (profile.value.user as Row)
                : {};
        accountForm.value = {
            name: String(user.name ?? ""),
            email: String(user.email ?? ""),
            phone: String(user.phone ?? ""),
            citizen_id: String(user.citizen_id ?? ""),
            date_of_birth: String(user.date_of_birth ?? ""),
        };
        initialAccountForm.value = { ...accountForm.value };
        emit("profileUpdated", { name: accountForm.value.name });
        notice.value = "Cập nhật thông tin cá nhân thành công.";
    } catch (cause) {
        accountFormErrors.value = validationErrors(cause);
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

async function changePassword(): Promise<void> {
    passwordFormErrors.value = {};
    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        await api.patch("/account/password", passwordForm.value);
        passwordForm.value = {
            current_password: "",
            password: "",
            password_confirmation: "",
        };
        showCurrentPassword.value = false;
        showNewPassword.value = false;
        showPasswordConfirmation.value = false;
        notice.value = "Đổi mật khẩu thành công.";
    } catch (cause) {
        passwordFormErrors.value = validationErrors(cause);
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

function setImportFiles(files: File[], input?: HTMLInputElement): void {
    importSubmitAttempted.value = false;
    const invalidFile = files.find((file) => !/\.(xls|xlsx)$/i.test(file.name));
    if (invalidFile) {
        importFiles.value = [];
        importFileError.value = `Tệp "${invalidFile.name}" không phải định dạng .xls hoặc .xlsx.`;
    } else if (files.length > MAX_IMPORT_FILES) {
        importFiles.value = [];
        importFileError.value = `Mỗi lần chỉ chọn tối đa ${MAX_IMPORT_FILES} tệp.`;
    } else {
        const oversizedFile = files.find(
            (file) => file.size > MAX_IMPORT_FILE_BYTES,
        );
        const totalSize = files.reduce((total, file) => total + file.size, 0);
        if (oversizedFile) {
            importFiles.value = [];
            importFileError.value = `Tệp "${oversizedFile.name}" vượt quá 20 MB.`;
        } else if (totalSize > MAX_IMPORT_BATCH_BYTES) {
            importFiles.value = [];
            importFileError.value =
                "Tổng dung lượng các tệp không được vượt quá 35 MB.";
        } else {
            importFiles.value = files;
            importFileError.value = "";
        }
    }
    if (input) input.value = "";
    error.value = "";
}

function handleImportFileChange(event: Event): void {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    setImportFiles(Array.from(input.files ?? []), input);
}

function handleImportDrop(event: DragEvent): void {
    importDropActive.value = false;
    setImportFiles(
        Array.from(event.dataTransfer?.files ?? []),
        importFileInput.value ?? undefined,
    );
}

function removeImportFile(index: number): void {
    importFiles.value = importFiles.value.filter(
        (_, fileIndex) => fileIndex !== index,
    );
    importSubmitAttempted.value = false;
    importFileError.value = "";
    error.value = "";
}

function handleImportDragLeave(event: DragEvent): void {
    const nextTarget = event.relatedTarget;
    if (
        nextTarget instanceof Node &&
        event.currentTarget instanceof Node &&
        event.currentTarget.contains(nextTarget)
    ) {
        return;
    }
    importDropActive.value = false;
}

async function submitImport(): Promise<void> {
    importSubmitAttempted.value = true;
    if (
        importFiles.value.length === 0 ||
        !form.value.province_code ||
        !form.value.commune_code ||
        !form.value.village_code
    ) {
        return;
    }
    const data = new FormData();
    const files = [...importFiles.value];
    files.forEach((file) => data.append("files[]", file));
    data.append("school_year", currentImportSchoolYear);
    for (const key of ["province_code", "commune_code", "village_code"]) {
        const value = form.value[key];
        if (value) data.append(key, value);
    }
    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        window.dispatchEvent(
            new CustomEvent(HOUSEHOLD_IMPORT_SUBMITTED_EVENT, {
                detail: {
                    stage: "uploading",
                    imports: files.map((file) => ({ fileName: file.name })),
                },
            }),
        );
        const response = await api.post<unknown>("/household-imports", data);
        const result = apiData<Record<string, unknown>>(response.data);
        const imports = Array.isArray(result.imports)
            ? result.imports
            : [{ id: result.import_id, file_name: files[0]?.name }];
        const submittedImports = imports.flatMap((item) => {
            if (typeof item !== "object" || item === null) return [];
            const importData = item as Record<string, unknown>;
            return typeof importData.id === "number" &&
                Number.isSafeInteger(importData.id) &&
                typeof importData.file_name === "string"
                ? [{ id: importData.id, fileName: importData.file_name }]
                : [];
        });
        if (submittedImports.length !== files.length) {
            throw new Error("Máy chủ không trả về đủ mã file nhập.");
        }
        window.dispatchEvent(
            new CustomEvent(HOUSEHOLD_IMPORT_SUBMITTED_EVENT, {
                detail: { stage: "accepted", imports: submittedImports },
            }),
        );
        importFiles.value = [];
        importSubmitAttempted.value = false;
        await load();
    } catch (cause) {
        window.dispatchEvent(
            new CustomEvent(HOUSEHOLD_IMPORT_SUBMITTED_EVENT, {
                detail: {
                    stage: "failed",
                    message: errorMessage(cause),
                },
            }),
        );
        error.value = "";
    } finally {
        busy.value = false;
    }
}

async function restoreTrash(row: Row): Promise<void> {
    const type = row.item_type === "household" ? "households" : "persons";
    try {
        await api.patch(`/trash/${type}/${row.id}`);
        const key = trashKey(row);
        selectedTrashKeys.value = selectedTrashKeys.value.filter(
            (selectedKey) => selectedKey !== key,
        );
        rows.value = rows.value.filter((item) => trashKey(item) !== key);
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        lastPage.value = Math.max(
            1,
            Math.ceil(totalRecords.value / trashPageSize.value),
        );
        if (currentPage.value > lastPage.value)
            currentPage.value = lastPage.value;
        notice.value = "Đã khôi phục mục đã chọn.";
        deleteResult.value = {
            success: true,
            title: "Khôi phục thành công",
            message: "Dữ liệu đã được đưa trở lại danh sách quản lý.",
        };
    } catch (cause) {
        error.value = errorMessage(cause);
        deleteResult.value = {
            success: false,
            title: "Khôi phục thất bại",
            message: errorMessage(cause),
        };
    }
}

async function permanentlyDeleteTrash(row: Row): Promise<void> {
    const type = row.item_type === "household" ? "households" : "persons";
    try {
        await api.delete(`/trash/${type}/${row.id}`);
        const key = trashKey(row);
        selectedTrashKeys.value = selectedTrashKeys.value.filter(
            (selectedKey) => selectedKey !== key,
        );
        rows.value = rows.value.filter((item) => trashKey(item) !== key);
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        lastPage.value = Math.max(
            1,
            Math.ceil(totalRecords.value / trashPageSize.value),
        );
        if (currentPage.value > lastPage.value)
            currentPage.value = lastPage.value;
        notice.value = "Đã xóa vĩnh viễn mục đã chọn.";
        deleteResult.value = {
            success: true,
            title: "Xóa vĩnh viễn thành công",
            message: "Dữ liệu đã được xóa khỏi thùng rác.",
        };
    } catch (cause) {
        error.value = errorMessage(cause);
        deleteResult.value = {
            success: false,
            title: "Không thể xóa dữ liệu",
            message: errorMessage(cause),
        };
    }
}

function requestTrashRestore(row: Row): void {
    trashActionDialog.value = { operation: "restore", row };
}

function requestTrashDelete(row: Row): void {
    trashActionDialog.value = { operation: "force_delete", row };
}

async function confirmTrashAction(): Promise<void> {
    const action = trashActionDialog.value;
    if (!action || busy.value) return;
    trashActionDialog.value = null;
    if (action.row) {
        if (action.operation === "restore") await restoreTrash(action.row);
        else await permanentlyDeleteTrash(action.row);
        return;
    }
    await bulkTrash(action.operation);
}

function trashKey(row: Row): string {
    return `${row.item_type ?? "household"}-${row.id}`;
}

function toggleTrashSelection(row: Row, checked: boolean): void {
    const key = trashKey(row);
    selectedTrashKeys.value = checked
        ? [...new Set([...selectedTrashKeys.value, key])]
        : selectedTrashKeys.value.filter((selectedKey) => selectedKey !== key);
}

const allTrashRowsSelected = computed(
    () =>
        rows.value.length > 0 &&
        rows.value.every((row) =>
            selectedTrashKeys.value.includes(trashKey(row)),
        ),
);

function toggleAllTrashRows(checked: boolean): void {
    const pageKeys = rows.value.map(trashKey);
    selectedTrashKeys.value = checked
        ? [...new Set([...selectedTrashKeys.value, ...pageKeys])]
        : selectedTrashKeys.value.filter((key) => !pageKeys.includes(key));
}

async function bulkTrash(operation: "restore" | "force_delete"): Promise<void> {
    if (!selectedTrashKeys.value.length || busy.value) return;
    const selectedKeys = [...selectedTrashKeys.value];
    const householdIds = selectedTrashKeys.value
        .filter((key) => key.startsWith("household-"))
        .map((key) => Number(key.split("-").at(-1)));
    const personIds = selectedTrashKeys.value
        .filter((key) => key.startsWith("person-"))
        .map((key) => Number(key.split("-").at(-1)));
    busy.value = true;
    try {
        await api.post("/trash/batch-operations", {
            operation,
            household_ids: householdIds,
            person_ids: personIds,
        });
        selectedTrashKeys.value = [];
        notice.value =
            operation === "restore"
                ? "Đã khôi phục các mục đã chọn."
                : "Đã xóa vĩnh viễn các mục đã chọn.";
        rows.value = rows.value.filter(
            (row) => !selectedKeys.includes(trashKey(row)),
        );
        totalRecords.value = Math.max(
            0,
            totalRecords.value - selectedKeys.length,
        );
        lastPage.value = Math.max(
            1,
            Math.ceil(totalRecords.value / trashPageSize.value),
        );
        if (currentPage.value > lastPage.value)
            currentPage.value = lastPage.value;
        deleteResult.value = {
            success: true,
            title:
                operation === "restore"
                    ? "Khôi phục thành công"
                    : "Xóa vĩnh viễn thành công",
            message:
                operation === "restore"
                    ? "Các mục đã chọn đã được đưa trở lại danh sách quản lý."
                    : "Các mục đã chọn đã được xóa khỏi thùng rác.",
        };
    } catch (cause) {
        error.value = errorMessage(cause);
        deleteResult.value = {
            success: false,
            title: "Không thể xử lý dữ liệu",
            message: errorMessage(cause),
        };
    } finally {
        busy.value = false;
    }
}

function applyTrashFilters(): void {
    selectedTrashKeys.value = [];
    currentPage.value = 1;
    void load();
}

function openAccountAction(row: Row, action: AccountAction): void {
    const accountName = String(row.name ?? row.username ?? "tài khoản này");
    accountPassword.value = "";
    accountPasswordConfirmation.value = "";
    accountPasswordError.value = "";
    showAccountPassword.value = false;
    showAccountPasswordConfirmation.value = false;
    accountActionDialog.value = {
        mode: action === "password" ? "password-form" : "confirm",
        action,
        row,
        accountName,
    };
}

function randomPasswordIndex(max: number): number {
    const range = 0x100000000;
    const limit = range - (range % max);
    const value = new Uint32Array(1);
    do {
        window.crypto.getRandomValues(value);
    } while (value[0] >= limit);

    return value[0] % max;
}

function generateAccountPassword(): void {
    const groups = [
        "abcdefghijkmnopqrstuvwxyz",
        "ABCDEFGHJKLMNPQRSTUVWXYZ",
        "23456789",
        "!@#$%_-",
    ];
    const allCharacters = groups.join("");

    try {
        const password = groups
            .map((group) => group[randomPasswordIndex(group.length)])
            .concat(
                Array.from(
                    { length: 12 },
                    () =>
                        allCharacters[
                            randomPasswordIndex(allCharacters.length)
                        ],
                ),
            );
        for (let index = password.length - 1; index > 0; index -= 1) {
            const swapIndex = randomPasswordIndex(index + 1);
            [password[index], password[swapIndex]] = [
                password[swapIndex],
                password[index],
            ];
        }

        accountPassword.value = password.join("");
        accountPasswordConfirmation.value = accountPassword.value;
        showAccountPassword.value = true;
        showAccountPasswordConfirmation.value = true;
        accountPasswordError.value = "";
    } catch {
        accountPasswordError.value =
            "Trình duyệt không thể tạo mật khẩu an toàn. Vui lòng nhập mật khẩu mới.";
    }
}

function closeAccountAction(): void {
    if (busy.value) return;
    accountActionDialog.value = null;
    accountPassword.value = "";
    accountPasswordConfirmation.value = "";
    accountPasswordError.value = "";
    showAccountPassword.value = false;
    showAccountPasswordConfirmation.value = false;
}

async function confirmAccountAction(): Promise<void> {
    const dialog = accountActionDialog.value;
    if (
        !dialog ||
        (dialog.mode !== "confirm" && dialog.mode !== "password-form")
    )
        return;

    if (dialog.action === "password") {
        const passwordLength = Array.from(accountPassword.value).length;
        if (passwordLength < 12 || passwordLength > 18) {
            accountPasswordError.value = "Mật khẩu phải có từ 12 đến 18 ký tự.";
            return;
        }
        if (accountPassword.value !== accountPasswordConfirmation.value) {
            accountPasswordError.value = "Mật khẩu xác nhận không khớp.";
            return;
        }
    }

    error.value = "";
    notice.value = "";
    busy.value = true;
    try {
        const result =
            dialog.action === "status"
                ? await api.patch<{ message: string }>(
                      `/users/${dialog.row.id}/status`,
                      {
                          is_active: !dialog.row.is_active,
                      },
                  )
                : await api.post<{ message: string }>(
                      `/users/${dialog.row.id}/password-resets`,
                      {
                          password: accountPassword.value,
                          password_confirmation:
                              accountPasswordConfirmation.value,
                      },
                  );
        if (dialog.action === "status") {
            dialog.row.is_active = !dialog.row.is_active;
        }
        accountPassword.value = "";
        accountPasswordConfirmation.value = "";
        accountActionDialog.value = {
            ...dialog,
            mode: "result",
            success: true,
            message: result.data.message,
        };
    } catch (cause) {
        if (
            dialog.action === "password" &&
            typeof cause === "object" &&
            cause !== null &&
            "response" in cause &&
            typeof cause.response === "object" &&
            cause.response !== null &&
            "status" in cause.response &&
            cause.response.status === 422
        ) {
            accountPasswordError.value = errorMessage(cause);
            return;
        }
        accountPassword.value = "";
        accountPasswordConfirmation.value = "";
        accountActionDialog.value = {
            ...dialog,
            mode: "result",
            success: false,
            message: errorMessage(cause),
        };
    } finally {
        busy.value = false;
    }
}

function accountActionTitle(dialog: AccountActionDialog): string {
    if (dialog.action === "password") {
        return dialog.mode === "result"
            ? "Kết quả đổi mật khẩu"
            : "Đổi mật khẩu tài khoản";
    }

    return dialog.mode === "confirm"
        ? dialog.row.is_active
            ? "Xác nhận vô hiệu hóa tài khoản"
            : "Xác nhận kích hoạt tài khoản"
        : "Kết quả cập nhật tài khoản";
}

function accountActionMessage(dialog: AccountActionDialog): string {
    return dialog.success
        ? (dialog.message ?? "")
        : `Thao tác thất bại: ${dialog.message ?? "Vui lòng thử lại."}`;
}

async function changeUserPageSize(): Promise<void> {
    if (busy.value) return;
    currentPage.value = 1;
    await load();
}

async function changeRolePageSize(): Promise<void> {
    if (busy.value) return;
    currentPage.value = 1;
    await load();
}

async function changeVillagePageSize(): Promise<void> {
    if (busy.value) return;
    currentPage.value = 1;
    await load();
}

async function changeSchoolPageSize(): Promise<void> {
    if (busy.value) return;
    currentPage.value = 1;
    await load();
}

async function changeHouseholdPageSize(): Promise<void> {
    if (busy.value) return;
    currentPage.value = 1;
    await load();
}

async function changePage(direction: -1 | 1): Promise<void> {
    if (busy.value) return;
    currentPage.value = Math.min(
        lastPage.value,
        Math.max(1, currentPage.value + direction),
    );
    await load();
}

watch(
    [() => props.moduleKey, () => props.importView],
    ([moduleKey, importView], [previousModuleKey, previousImportView]) => {
        selectedHouseholdIds.value = [];
        if (moduleKey !== "households") {
            householdDetail.value = null;
            householdListRow.value = null;
            selectedHouseholdPerson.value = null;
        }
        configurationFormOpen.value = false;
        selected.value = null;
        form.value = {};
        notice.value = "";
        pendingDelete.value = null;
        if (moduleKey === "users") canEditAccountConfigurations.value = false;
        if (moduleKey !== "users") {
            userFiltersInitialized.value = false;
            userFilterProvince.value = "";
            userFilterCommune.value = "";
            userFilterVillage.value = "";
            userFilterRole.value = "";
            userFilterQuery.value = "";
        }
        if (
            moduleKey !== previousModuleKey ||
            importView !== previousImportView
        ) {
            currentPage.value = 1;
            if (importView === "upload") {
                importSubmitAttempted.value = false;
                importFileError.value = "";
            }
            void load();
        }
    },
);

watch(
    () => props.householdFilters,
    () => {
        if (props.moduleKey === "households") {
            selectedHouseholdIds.value = [];
            currentPage.value = 1;
            void load();
        }
    },
    { deep: true },
);

watch(
    () => props.villageFilters,
    () => {
        if (props.moduleKey === "villages") {
            if (syncingVillageOptions) return;
            currentPage.value = 1;
            void load();
        }
    },
    { deep: true },
);

async function refreshImportHistory(): Promise<void> {
    if (
        props.moduleKey !== "imports" ||
        props.importView !== "history" ||
        !props.permissions.includes("view-imports")
    ) {
        return;
    }
    if (importHistoryRefreshActive || busy.value) {
        importHistoryRefreshPending = true;
        return;
    }

    importHistoryRefreshPending = false;
    importHistoryRefreshActive = true;
    try {
        const response = await api.get<unknown>("/household-imports", {
            params: { page: currentPage.value, per_page: 4 },
        });
        const history = apiData<Row>(response.data);
        importHistory.value = asRows(history.imports);
        const page = apiPage<Row>(response.data, history.imports);
        currentPage.value = page.current_page;
        lastPage.value = page.last_page;
        totalRecords.value = page.total;
    } catch (cause) {
        console.error(
            "Không thể cập nhật trạng thái lịch sử nhập file.",
            cause,
        );
    } finally {
        importHistoryRefreshActive = false;
        if (importHistoryRefreshPending && !busy.value) {
            void refreshImportHistory();
        }
    }
}

function handleImportCompleted(): void {
    void refreshImportHistory();
}

const previousBodyOverflow = document.body.style.overflow;
watch(configurationFormOpen, (open) => {
    document.body.style.overflow = open ? "hidden" : previousBodyOverflow;
});
watch(
    [memberForm, educationForm, disabilityForm],
    () => {
        if (!restoringHouseholdMemberDraft.value) {
            saveHouseholdMemberDraft();
        }
    },
    { deep: true, flush: "sync" },
);

function handleHouseholdRouteChange(): void {
    void restoreHouseholdRoute();
}

onUnmounted(() => {
    document.body.style.overflow = previousBodyOverflow;
    if (personSchoolOptionsTimer !== undefined) {
        window.clearTimeout(personSchoolOptionsTimer);
    }
    personSchoolOptionsSequence += 1;
    window.removeEventListener("popstate", handleHouseholdRouteChange);
    window.removeEventListener(
        HOUSEHOLD_IMPORT_COMPLETED_EVENT,
        handleImportCompleted,
    );
});

onMounted(async () => {
    window.addEventListener(
        HOUSEHOLD_IMPORT_COMPLETED_EVENT,
        handleImportCompleted,
    );
    window.addEventListener("popstate", handleHouseholdRouteChange);
    const isHouseholdPersonRoute =
        props.moduleKey === "households" &&
        /^\/households\/\d+\/persons(?:\/create|\/\d+\/edit)?\/?$/.test(
            window.location.pathname,
        );
    if (isHouseholdPersonRoute) {
        await restoreHouseholdRoute();
    } else {
        await load();
        await restoreHouseholdRoute();
    }
});
</script>

<template>
    <section
        class="workspace"
        :class="{
            'compact-management-workspace': isCompactManagement,
            'user-management-workspace': moduleKey === 'users',
            'workspace-imports': moduleKey === 'imports',
            'household-creation-active': householdCreationPageActive,
            'household-route-initializing': householdRouteInitialLoading,
            'import-upload-only':
                moduleKey === 'imports' && importView === 'upload',
        }"
        :aria-busy="busy"
        :aria-label="
            ['users', 'villages', 'schools'].includes(moduleKey)
                ? info.title
                : undefined
        "
    >
        <div
            v-if="householdRouteInitialLoading"
            class="household-route-loading"
            role="status"
            aria-live="polite"
        >
            <span class="workspace-loading-indicator" aria-hidden="true"></span>
            <span>Đang tải thông tin phiếu điều tra…</span>
        </div>
        <header
            v-if="
                ![
                    'users',
                    'roles',
                    'villages',
                    'schools',
                    'households',
                    'trash',
                    'account',
                    'imports',
                    'merge',
                ].includes(moduleKey)
            "
            class="section-heading"
            :class="{ 'compact-section-heading': isCompactManagement }"
        >
            <div v-if="moduleKey !== 'villages'" class="workspace-title">
                <span
                    v-if="moduleKey !== 'roles'"
                    class="workspace-title-mark"
                    aria-hidden="true"
                    >{{ info.title.slice(0, 1) }}</span
                >
                <div>
                    <p v-if="!isCompactManagement" class="workspace-eyebrow">
                        QUẢN LÝ DANH MỤC
                    </p>
                    <h2>{{ info.title }}</h2>
                    <p v-if="!isCompactManagement" class="muted">
                        {{ sectionDescription }}
                    </p>
                </div>
            </div>
            <div v-if="!isCompactManagement" class="pagination-actions">
                <span
                    v-if="rows.length && moduleKey !== 'trash'"
                    class="record-count"
                    >{{ rows.length }} bản ghi</span
                >
                <span v-else-if="moduleKey === 'trash'" class="record-count"
                    >{{ rows.length }} / {{ totalRecords }} mục</span
                >
                <button
                    class="secondary-button"
                    type="button"
                    @click="emit('close')"
                >
                    Quay lại
                </button>
                <button
                    v-if="
                        canCreate &&
                        [
                            'households',
                            'schools',
                            'villages',
                            'roles',
                            'users',
                        ].includes(moduleKey)
                    "
                    class="primary-button"
                    type="button"
                    @click="beginEdit()"
                >
                    Thêm mới
                </button>
            </div>
        </header>

        <div v-if="moduleKey === 'schools'" class="village-toolbar-row">
            <form
                class="village-unified-filter school-unified-filter"
                role="search"
                @submit.prevent="applySchoolFilters"
            >
                <label v-if="showSchoolProvinceFilter">
                    <span>Tỉnh / thành phố</span>
                    <select
                        v-model="schoolFilterProvince"
                        @change="changeSchoolFilterProvince"
                    >
                        <option value="">Tất cả địa bàn</option>
                        <option
                            v-for="province in provinces"
                            :key="String(province.code)"
                            :value="String(province.code)"
                        >
                            {{ province.name }}
                        </option>
                    </select>
                </label>
                <label v-if="showSchoolCommuneFilter">
                    <span>Xã / phường</span>
                    <select
                        v-model="schoolFilterCommune"
                        :disabled="!schoolFilterProvince"
                        @change="applySchoolFilters"
                    >
                        <option value="">Tất cả xã / phường</option>
                        <option
                            v-for="commune in schoolFilterCommunes"
                            :key="String(commune.code)"
                            :value="String(commune.code)"
                        >
                            {{ commune.name }}
                        </option>
                    </select>
                </label>
                <label>
                    <span>Cấp học</span>
                    <select
                        v-model="schoolFilterLevel"
                        @change="applySchoolFilters"
                    >
                        <option value="">Tất cả cấp học</option>
                        <option
                            v-for="level in schoolLevelOptions"
                            :key="level"
                            :value="level"
                        >
                            {{ schoolLevelLabels[level] }}
                        </option>
                    </select>
                </label>
                <label class="village-unified-search">
                    <span class="village-search-control">
                        <input
                            v-model="schoolFilterQuery"
                            type="search"
                            placeholder="Nhập mã hoặc tên trường"
                        />
                        <button
                            class="secondary-button management-tool-button user-search-button"
                            type="submit"
                            :disabled="busy"
                            aria-label="Tìm trường học"
                            title="Tìm trường học"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="10.8" cy="10.8" r="6.8" />
                                <path d="m16 16 4.5 4.5" />
                            </svg>
                        </button>
                    </span>
                </label>
            </form>
            <div class="village-management-toolbar-bottom">
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="beginEdit()"
                >
                    Thêm mới
                </button>
            </div>
        </div>

        <div
            v-if="moduleKey === 'households'"
            class="village-toolbar-row household-toolbar-row"
        >
            <form
                class="village-unified-filter household-unified-filter"
                role="search"
                @submit.prevent="applyHouseholdFilters"
            >
                <slot name="toolbar" />
                <label class="household-school-year-filter">
                    <span>Năm học</span>
                    <select
                        v-model="householdSchoolYear"
                        aria-label="Lọc theo năm học"
                        @change="applyHouseholdFilters"
                    >
                        <option value="">{{ currentImportSchoolYear }}</option>
                        <option value="all">Tất cả năm học</option>
                        <option
                            v-for="schoolYear in householdSchoolYearOptions"
                            :key="schoolYear"
                            :value="schoolYear"
                        >
                            {{ schoolYear }}
                        </option>
                    </select>
                </label>
                <label class="village-unified-search">
                    <span>Tìm theo mã phiếu hoặc tên chủ hộ</span>
                    <span class="village-search-control">
                        <input
                            v-model.trim="householdQuery"
                            type="search"
                            placeholder="Nhập mã phiếu hoặc tên chủ hộ"
                        />
                        <button
                            class="secondary-button management-tool-button user-search-button"
                            type="submit"
                            :disabled="busy"
                            aria-label="Tìm phiếu điều tra"
                            title="Tìm phiếu điều tra"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="10.8" cy="10.8" r="6.8" />
                                <path d="m16 16 4.5 4.5" />
                            </svg>
                        </button>
                    </span>
                </label>
            </form>
            <div
                class="village-management-toolbar-bottom household-toolbar-actions household-list-actions"
            >
                <button
                    v-if="canDelete && selectedHouseholdIds.length"
                    class="secondary-button danger-button management-tool-button household-bulk-delete-button"
                    type="button"
                    :disabled="busy"
                    @click="pendingHouseholdBulkDelete = true"
                >
                    <svg viewBox="0 0 20 20" aria-hidden="true">
                        <path
                            d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"
                        />
                    </svg>
                    Xóa đã chọn ({{ selectedHouseholdIds.length }})
                </button>
                <button
                    v-if="permissions.includes('create-imports')"
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="emit('navigate', 'import-file')"
                >
                    Import file
                </button>
                <button
                    v-if="permissions.includes('view-imports')"
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="emit('navigate', 'import-history')"
                >
                    Lịch sử file
                </button>
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="beginEdit()"
                >
                    Thêm mới
                </button>
            </div>
        </div>

        <div v-if="moduleKey === 'villages'" class="village-toolbar-row">
            <slot name="toolbar" />
            <div class="village-management-toolbar-bottom">
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="beginEdit()"
                >
                    Thêm mới
                </button>
            </div>
        </div>

        <div
            v-if="isCompactManagement"
            class="management-toolbar"
            :class="{
                'user-management-toolbar': moduleKey === 'users',
                'role-management-toolbar': moduleKey === 'roles',
                'commune-management-toolbar':
                    props.scopeLevel === 'commune' &&
                    ['users', 'roles'].includes(moduleKey),
                'trash-management-toolbar-wrapper': moduleKey === 'trash',
            }"
        >
            <div v-if="moduleKey === 'users'" class="user-location-filters">
                <label v-if="userAccountScopeLevels.length > 1">
                    <span>Cấp quản lý</span>
                    <select
                        v-model="userFilterScopeLevel"
                        aria-label="Lọc theo cấp quản lý"
                        @change="changeUserScopeLevel"
                    >
                        <option value="">Tất cả cấp quản lý</option>
                        <option
                            v-for="level in userAccountScopeLevels"
                            :key="level"
                            :value="level"
                        >
                            {{ scopeLabels[level] || level }}
                        </option>
                    </select>
                </label>
                <label v-if="showUserProvinceFilter">
                    <span>Phạm vi</span>
                    <select
                        v-model="userFilterProvince"
                        aria-label="Lọc theo phạm vi"
                        :disabled="userFilterScopeLevel === 'global'"
                        @change="changeUserProvince"
                    >
                        <option value="">Cả nước</option>
                        <option
                            v-for="province in provinces"
                            :key="String(province.code)"
                            :value="String(province.code)"
                        >
                            {{ province.name }}
                        </option>
                    </select>
                </label>
                <label v-else-if="showUserCommuneFilter">
                    <span>Phạm vi</span>
                    <select
                        v-model="userFilterCommune"
                        aria-label="Lọc theo phạm vi xã / phường"
                        :disabled="!userFilterProvince"
                        @change="changeUserCommune"
                    >
                        <option value="">Tất cả xã / phường</option>
                        <option
                            v-for="commune in userFilterCommunes"
                            :key="String(commune.code)"
                            :value="String(commune.code)"
                        >
                            {{ commune.name }}
                        </option>
                    </select>
                </label>
                <label v-else-if="showUserVillageFilter">
                    <span>Phạm vi</span>
                    <select
                        v-model="userFilterVillage"
                        aria-label="Lọc theo phạm vi thôn / xóm"
                        :disabled="!userFilterCommune"
                        @change="changeUserVillage"
                    >
                        <option value="">Tất cả thôn / xóm</option>
                        <option
                            v-for="village in userFilterVillages"
                            :key="String(village.code)"
                            :value="String(village.code)"
                        >
                            {{ village.name }}
                        </option>
                    </select>
                </label>
                <label>
                    <span>Nhóm quyền</span>
                    <select
                        v-model="userFilterRole"
                        aria-label="Lọc theo nhóm người dùng"
                        @change="changeUserRole"
                    >
                        <option value="">Tất cả nhóm người dùng</option>
                        <option
                            v-for="role in userFilterRoles"
                            :key="String(role.id)"
                            :value="String(role.id)"
                        >
                            {{ role.name }}
                        </option>
                    </select>
                </label>
                <form
                    class="user-search-control"
                    role="search"
                    @submit.prevent="searchUsers"
                >
                    <input
                        v-model.trim="userFilterQuery"
                        type="search"
                        aria-label="Tìm theo tên cán bộ"
                        placeholder="Tìm tên cán bộ…"
                    />
                    <button
                        class="secondary-button management-tool-button user-search-button"
                        type="submit"
                        :disabled="busy"
                        aria-label="Tìm cán bộ"
                        title="Tìm cán bộ"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="10.8" cy="10.8" r="6.8" />
                            <path d="m16 16 4.5 4.5" />
                        </svg>
                    </button>
                </form>
            </div>
            <div
                v-if="moduleKey === 'users'"
                class="user-management-toolbar-bottom"
            >
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    @click="beginEdit()"
                >
                    Thêm mới
                </button>
            </div>
            <template v-else-if="moduleKey === 'roles'">
                <form
                    class="user-search-control role-search-control"
                    role="search"
                    @submit.prevent="searchRoles"
                >
                    <input
                        v-model.trim="roleFilterQuery"
                        type="search"
                        aria-label="Tìm theo tên nhóm người dùng"
                        placeholder="Tìm theo tên nhóm…"
                    />
                    <button
                        class="secondary-button management-tool-button user-search-button"
                        type="submit"
                        :disabled="busy"
                        aria-label="Tìm nhóm người dùng"
                        title="Tìm nhóm người dùng"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="10.8" cy="10.8" r="6.8" />
                            <path d="m16 16 4.5 4.5" />
                        </svg>
                    </button>
                </form>
                <div class="role-management-toolbar-bottom">
                    <button
                        v-if="canCreate"
                        class="primary-button management-tool-button"
                        type="button"
                        :disabled="busy"
                        @click="beginEdit()"
                    >
                        Thêm mới
                    </button>
                </div>
            </template>
            <template v-else-if="moduleKey === 'trash'">
                <div class="trash-management-toolbar">
                    <label>
                        <span>Loại dữ liệu</span>
                        <select
                            v-model="trashFilterType"
                            @change="applyTrashFilters"
                        >
                            <option value="household">Phiếu điều tra</option>
                            <option value="person">Nhân khẩu</option>
                        </select>
                    </label>
                    <form
                        class="trash-search-control"
                        role="search"
                        aria-label="Tìm kiếm dữ liệu đã xóa"
                        @submit.prevent="applyTrashFilters"
                    >
                        <input
                            v-model.trim="trashFilterQuery"
                            type="search"
                            placeholder="Mã phiếu hoặc tên nhân khẩu…"
                            @keyup.enter="applyTrashFilters"
                        />
                        <button
                            class="secondary-button management-tool-button trash-search-button"
                            type="submit"
                            :disabled="busy"
                            aria-label="Tìm dữ liệu đã xóa"
                            title="Tìm dữ liệu đã xóa"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="10.8" cy="10.8" r="6.8" />
                                <path d="m16 16 4.5 4.5" />
                            </svg>
                        </button>
                    </form>
                </div>
                <div
                    v-if="selectedTrashKeys.length && (canUpdate || canDelete)"
                    class="trash-management-toolbar-bottom"
                >
                    <div
                        class="trash-bulk-actions"
                    >
                        <span class="trash-selected-count"
                            >Đã chọn {{ selectedTrashKeys.length }}</span
                        >
                        <button
                            v-if="canUpdate"
                            class="secondary-button management-tool-button trash-bulk-button"
                            type="button"
                            :disabled="busy"
                            title="Khôi phục mục đã chọn"
                            aria-label="Khôi phục mục đã chọn"
                            @click="
                                trashActionDialog = { operation: 'restore' }
                            "
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.6" />
                                <path d="M4 4v4.6h4.6" />
                            </svg>
                        </button>
                        <button
                            v-if="canDelete"
                            class="secondary-button management-tool-button trash-bulk-button danger-button"
                            type="button"
                            :disabled="busy"
                            title="Xóa vĩnh viễn mục đã chọn"
                            aria-label="Xóa vĩnh viễn mục đã chọn"
                            @click="
                                trashActionDialog = {
                                    operation: 'force_delete',
                                }
                            "
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path
                                    d="M5 7h14M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </template>
            <div v-else class="management-toolbar-actions">
                <span class="record-count"
                    >{{ rows.length }} / {{ totalRecords }} bản ghi</span
                >
                <div
                    v-if="lastPage > 1"
                    class="pagination-actions compact-pagination"
                    aria-label="Phân trang danh sách"
                >
                    <button
                        class="secondary-button management-tool-button"
                        type="button"
                        :disabled="currentPage <= 1 || busy"
                        @click="changePage(-1)"
                    >
                        Trước
                    </button>
                    <span class="muted"
                        >Trang {{ currentPage }} / {{ lastPage }}</span
                    >
                    <button
                        class="secondary-button management-tool-button"
                        type="button"
                        :disabled="currentPage >= lastPage || busy"
                        @click="changePage(1)"
                    >
                        Sau
                    </button>
                </div>
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    @click="beginEdit()"
                >
                    Thêm mới
                </button>
            </div>
        </div>

        <p v-if="error && !hasConfigurationForm" class="notice" role="alert">
            {{ error }}
        </p>
        <p
            v-if="notice && !['trash', 'households'].includes(moduleKey)"
            class="success-notice"
            role="status"
        >
            {{ notice }}
        </p>
        <div
            v-if="hasConfigurationForm && !isHouseholdCreateContinuationPage"
            :class="{
                'workspace-modal-backdrop': isDialogForm,
                'household-create-page-backdrop': isHouseholdCreatePage,
            }"
            :role="
                isDialogForm && !isHouseholdCreatePage
                    ? 'presentation'
                    : undefined
            "
            @keydown.esc="closeConfigurationForm"
        >
            <form
                class="workspace-form"
                :class="{
                    'workspace-modal-card':
                        isDialogForm && !isHouseholdCreatePage,
                    'household-create-page-form': isHouseholdCreatePage,
                    'user-configuration-form': moduleKey === 'users',
                    'role-configuration-form': moduleKey === 'roles',
                    'village-merge-configuration-form': moduleKey === 'merge',
                    'village-configuration-form': [
                        'villages',
                        'schools',
                    ].includes(moduleKey),
                    'household-configuration-form': moduleKey === 'households',
                }"
                :novalidate="
                    [
                        'users',
                        'schools',
                        'villages',
                        'roles',
                        'households',
                    ].includes(moduleKey)
                "
                :role="
                    isDialogForm && !isHouseholdCreatePage
                        ? 'dialog'
                        : undefined
                "
                :aria-modal="
                    isDialogForm && !isHouseholdCreatePage ? 'true' : undefined
                "
                :aria-labelledby="
                    isDialogForm && !isHouseholdCreatePage
                        ? 'configuration-form-title'
                        : undefined
                "
                @submit.prevent="save"
            >
                <div class="workspace-form-heading">
                    <h3
                        v-if="moduleKey !== 'merge'"
                        :id="
                            isDialogForm
                                ? 'configuration-form-title'
                                : undefined
                        "
                    >
                        {{
                            `${editing ? "Cập nhật" : "Tạo mới"} ${info.title.toLocaleLowerCase("vi")}`
                        }}
                    </h3>
                </div>
                <button
                    v-if="isDialogForm && !isHouseholdCreatePage"
                    class="modal-icon-button workspace-form-close"
                    type="button"
                    aria-label="Đóng biểu mẫu"
                    @click="closeConfigurationForm"
                >
                    ×
                </button>
                <p
                    v-if="
                        error &&
                        hasConfigurationForm &&
                        !['users', 'roles', 'households'].includes(moduleKey)
                    "
                    class="notice"
                    role="alert"
                >
                    {{ error }}
                </p>
                <p
                    v-if="moduleKey === 'roles' && roleFormRequestError"
                    class="field-error role-form-request-error"
                    role="alert"
                >
                    {{ roleFormRequestError }}
                </p>
                <template
                    v-for="field in fieldsForForm.filter(
                        (field) =>
                            !(
                                moduleKey === 'merge' &&
                                ['source_codes', 'target_code'].includes(field)
                            ) &&
                            !(
                                moduleKey === 'households' &&
                                !editing &&
                                field === 'household_code'
                            ) &&
                            !(
                                field === 'scope_level' &&
                                scopeLevelOptions.length <= 1
                            ) &&
                            shouldShowFormField(field),
                    )"
                    :key="field"
                >
                    <h4
                        v-if="
                            moduleKey === 'households' &&
                            (['household_code', 'province_code'].includes(
                                field,
                            ) ||
                                (!editing && field === 'school_year'))
                        "
                        class="household-form-section-heading"
                    >
                        {{
                            field === "province_code"
                                ? "Thông tin nơi đang ở"
                                : "Thông tin đối tượng và chủ hộ"
                        }}
                        <span
                            v-if="
                                isHouseholdCreatePage &&
                                (field === 'household_code' ||
                                    field === 'school_year') &&
                                String(form.household_code ?? '').trim()
                            "
                            class="household-form-section-code"
                        >
                            Mã phiếu: {{ form.household_code }}
                        </span>
                    </h4>
                    <label
                        v-if="
                            !(
                                moduleKey === 'users' &&
                                field === 'village_codes'
                            ) &&
                            !(
                                moduleKey === 'users' &&
                                field === 'province_code' &&
                                currentActorScopeLevel === 'province'
                            ) &&
                            !(
                                moduleKey === 'schools' &&
                                field === 'province_code' &&
                                provinces.length === 1
                            ) &&
                            !(
                                moduleKey === 'schools' &&
                                field === 'commune_code' &&
                                schoolActorScopeLevel === 'commune'
                            )
                        "
                        class="workspace-field"
                        :class="{
                            'household-wide-field':
                                moduleKey === 'households' &&
                                ['address', 'residence_status'].includes(field),
                        }"
                    >
                        <span
                            :class="{
                                'required-field-label':
                                    (moduleKey === 'schools' &&
                                        [
                                            'name',
                                            'type',
                                            'level',
                                            'province_code',
                                            'commune_code',
                                            'is_active',
                                        ].includes(field)) ||
                                    (moduleKey === 'households' &&
                                        [
                                            'school_year',
                                            'head_last_name',
                                            'head_first_name',
                                            'province_code',
                                            'commune_code',
                                            'village_code',
                                            'residence_type',
                                        ].includes(field)) ||
                                    (moduleKey === 'households' &&
                                        field === 'household_code' &&
                                        editing),
                            }"
                        >
                            {{
                                moduleKey === "users" && field === "username"
                                    ? "Tên đăng nhập (7–12 ký tự)"
                                    : fieldLabel(field)
                            }}
                        </span>
                        <select
                            v-if="field === 'type'"
                            v-model="form[field]"
                            :required="moduleKey !== 'schools'"
                            @change="
                                moduleKey === 'schools' && changeSchoolType()
                            "
                        >
                            <option value="">Chọn loại trường</option>
                            <option value="single_level">Một cấp</option>
                            <option value="multi_level">Liên cấp</option>
                        </select>
                        <select
                            v-else-if="
                                ['schools', 'users'].includes(moduleKey) &&
                                field === 'is_active'
                            "
                            v-model="form[field]"
                            :required="moduleKey === 'users'"
                        >
                            <option value="1">Đang hoạt động</option>
                            <option value="0">
                                {{
                                    moduleKey === "users"
                                        ? "Vô hiệu hóa"
                                        : "Ngừng hoạt động"
                                }}
                            </option>
                        </select>
                        <select
                            v-else-if="field === 'level'"
                            v-model="form[field]"
                        >
                            <option value="">Chọn cấp học</option>
                            <option
                                v-for="level in schoolLevelOptions.filter(
                                    (level) =>
                                        moduleKey !== 'schools' ||
                                        form.type !== 'multi_level' ||
                                        ['THCS', 'THPT'].includes(level),
                                )"
                                :key="level"
                                :value="level"
                            >
                                {{ schoolLevelLabels[level] }}
                            </option>
                        </select>
                        <select
                            v-else-if="
                                moduleKey === 'villages' &&
                                field === 'operational_status'
                            "
                            v-model="form[field]"
                            required
                        >
                            <option value="active">Đang hoạt động</option>
                            <option value="paused">Không hoạt động</option>
                        </select>
                        <select
                            v-else-if="field === 'scope_level'"
                            v-model="form[field]"
                            :required="['roles', 'users'].includes(moduleKey)"
                            :aria-invalid="
                                Boolean(
                                    formErrors[field] ||
                                    (userFormSubmitted &&
                                        userFormValidationErrors[field]) ||
                                    (roleFormSubmitted &&
                                        roleFormValidationErrors[field]),
                                )
                            "
                        >
                            <option value="">Chọn cấp quản lý</option>
                            <option
                                v-for="level in moduleKey === 'users'
                                    ? accountLevels
                                    : roleScopeLevels"
                                :key="level"
                                :value="level"
                            >
                                {{ scopeLabels[level] }}
                            </option>
                        </select>
                        <select
                            v-else-if="field === 'role_id'"
                            v-model="form[field]"
                            :required="moduleKey === 'users'"
                            :disabled="formOptionsBusy"
                            :aria-invalid="
                                Boolean(
                                    formErrors[field] ||
                                    (userFormSubmitted &&
                                        userFormValidationErrors[field]) ||
                                    (roleFormSubmitted &&
                                        roleFormValidationErrors[field]),
                                )
                            "
                            @change="
                                moduleKey === 'users' && changeUserFormRole()
                            "
                        >
                            <option value="">Chọn nhóm quyền</option>
                            <option
                                v-for="role in moduleKey === 'users'
                                    ? assignableUserRoles
                                    : roleOptions"
                                :key="String(role.id)"
                                :value="String(role.id)"
                                :disabled="
                                    role.is_primary_admin === true &&
                                    availablePrimaryAdminScopes[
                                        form.scope_level
                                    ] === false
                                "
                            >
                                {{ role.name
                                }}{{
                                    role.is_primary_admin === true &&
                                    availablePrimaryAdminScopes[
                                        form.scope_level
                                    ] === false
                                        ? " (đã đủ quản trị viên)"
                                        : ""
                                }}
                            </option>
                        </select>
                        <select
                            v-else-if="
                                field === 'province_code' &&
                                (provinces.length || moduleKey === 'users') &&
                                moduleKey !== 'households'
                            "
                            v-model="form[field]"
                            :required="
                                moduleKey === 'villages' ||
                                (moduleKey === 'users' &&
                                    form.scope_level !== 'global')
                            "
                            @change="
                                ['villages', 'users', 'schools'].includes(
                                    moduleKey,
                                ) && changeFormProvince()
                            "
                        >
                            <option value="">Chọn tỉnh / thành phố</option>
                            <option
                                v-for="province in provinces"
                                :key="String(province.code)"
                                :value="String(province.code)"
                            >
                                {{ province.name }}
                            </option>
                        </select>
                        <select
                            v-else-if="
                                field === 'commune_code' &&
                                (moduleKey === 'villages' ||
                                    moduleKey === 'users' ||
                                    moduleKey === 'schools')
                            "
                            v-model="form[field]"
                            :required="
                                moduleKey === 'villages' ||
                                (moduleKey === 'users' &&
                                    form.scope_level === 'commune')
                            "
                            :disabled="
                                (['villages', 'schools'].includes(moduleKey) &&
                                    !form.province_code) ||
                                (moduleKey === 'users' &&
                                    (!form.province_code || formOptionsBusy))
                            "
                            @change="
                                moduleKey === 'users' && changeFormCommune()
                            "
                        >
                            <option value="">Chọn xã / phường</option>
                            <option
                                v-for="commune in formCommunes"
                                :key="String(commune.code)"
                                :value="String(commune.code)"
                            >
                                {{ commune.name }}
                            </option>
                        </select>
                        <select
                            v-else-if="field === 'residence_type'"
                            v-model="form[field]"
                            :required="moduleKey === 'households'"
                        >
                            <option value="THUONG_TRU">Thường trú</option>
                            <option value="TAM_TRU">Tạm trú</option>
                            <option value="KHAC">Khác</option>
                        </select>
                        <select
                            v-else-if="
                                moduleKey === 'households' &&
                                field === 'school_year'
                            "
                            v-model="form[field]"
                            required
                            disabled
                        >
                            <option value="">Chọn năm học</option>
                            <option
                                v-for="year in householdFormSchoolYears"
                                :key="year"
                                :value="year"
                            >
                                {{ year }}
                            </option>
                        </select>
                        <select
                            v-else-if="
                                moduleKey === 'households' &&
                                field === 'province_code'
                            "
                            v-model="form[field]"
                            required
                            @change="changeFormProvince"
                        >
                            <option value="">Chọn tỉnh / thành phố</option>
                            <option
                                v-for="province in provinces"
                                :key="String(province.code)"
                                :value="String(province.code)"
                            >
                                {{ province.name }}
                            </option>
                        </select>
                        <select
                            v-else-if="
                                moduleKey === 'households' &&
                                field === 'commune_code'
                            "
                            v-model="form[field]"
                            required
                            :disabled="!form.province_code"
                            @change="changeHouseholdFormCommune"
                        >
                            <option value="">Chọn xã / phường</option>
                            <option
                                v-for="commune in formCommunes"
                                :key="String(commune.code)"
                                :value="String(commune.code)"
                            >
                                {{ commune.name }}
                            </option>
                        </select>
                        <select
                            v-else-if="
                                moduleKey === 'households' &&
                                field === 'village_code'
                            "
                            v-model="form[field]"
                            required
                            :disabled="!form.commune_code || formOptionsBusy"
                        >
                            <option value="">Chọn thôn / xóm</option>
                            <option
                                v-for="village in formVillages"
                                :key="String(village.code)"
                                :value="String(village.code)"
                            >
                                {{ village.name }}
                            </option>
                        </select>
                        <select
                            v-else-if="
                                moduleKey === 'merge' && field === 'target_code'
                            "
                            v-model="form[field]"
                            required
                            @change="changeVillageMergeTarget"
                        >
                            <option value="">Chọn thôn đích có sẵn</option>
                            <option
                                v-for="village in rows.filter(
                                    isAvailableVillageMergeTarget,
                                )"
                                :key="String(village.code)"
                                :value="String(village.code)"
                                :disabled="
                                    selectedSourceCodes.includes(
                                        String(village.code),
                                    )
                                "
                            >
                                {{ village.name }} · {{ village.code }}
                            </option>
                        </select>
                        <input
                            v-else
                            v-model="form[field]"
                            :readonly="
                                moduleKey === 'households' &&
                                field === 'household_code' &&
                                !editing
                            "
                            @input="sanitizeUserNumericInput($event, field)"
                            :type="
                                field.includes('password')
                                    ? 'password'
                                    : field === 'date_of_birth'
                                      ? 'date'
                                      : field === 'email'
                                        ? 'email'
                                        : moduleKey === 'users' &&
                                            field === 'phone'
                                          ? 'tel'
                                          : 'text'
                            "
                            :maxlength="formFieldMaxLength(field)"
                            :max="
                                field === 'date_of_birth'
                                    ? latestBirthDate
                                    : undefined
                            "
                            :minlength="
                                moduleKey === 'users' && field === 'username'
                                    ? 7
                                    : undefined
                            "
                            :inputmode="
                                moduleKey === 'users' && field === 'phone'
                                    ? 'tel'
                                    : moduleKey === 'users' &&
                                        field === 'citizen_id'
                                      ? 'numeric'
                                      : undefined
                            "
                            :aria-invalid="
                                Boolean(
                                    formErrors[field] ||
                                    (userFormSubmitted &&
                                        userFormValidationErrors[field]) ||
                                    (roleFormSubmitted &&
                                        roleFormValidationErrors[field]) ||
                                    (householdFormSubmitted &&
                                        householdFormValidationErrors[field]) ||
                                    (field === 'username' &&
                                        userFormRequestError),
                                )
                            "
                            :required="
                                (moduleKey !== 'schools' &&
                                    ((moduleKey !== 'roles' &&
                                        [
                                            'name',
                                            'username',
                                            'role_id',
                                            'scope_level',
                                        ].includes(field)) ||
                                        (moduleKey === 'roles' &&
                                            field === 'name'))) ||
                                (moduleKey === 'households' &&
                                    [
                                        'head_first_name',
                                        'head_last_name',
                                    ].includes(field)) ||
                                (moduleKey === 'households' &&
                                    field === 'household_code' &&
                                    editing) ||
                                (moduleKey === 'villages' &&
                                    field === 'name') ||
                                (moduleKey === 'merge' &&
                                    field === 'target_code') ||
                                (moduleKey === 'users' &&
                                    field === 'citizen_id')
                            "
                        />
                        <small
                            v-if="
                                moduleKey === 'users' &&
                                field === 'village_codes'
                            "
                            class="muted"
                        >
                            Không chọn thôn nào thì tài khoản được xem dữ liệu
                            toàn xã.
                        </small>
                        <small
                            v-if="formErrors[field]"
                            class="field-error"
                            role="alert"
                            >{{ formErrors[field] }}</small
                        >
                        <small
                            v-else-if="
                                villageFormSubmitted &&
                                villageFormValidationErrors[field]
                            "
                            class="field-error"
                            role="alert"
                        >
                            {{ villageFormValidationErrors[field] }}
                        </small>
                        <small
                            v-else-if="
                                schoolFormSubmitted &&
                                schoolFormValidationErrors[field]
                            "
                            class="field-error"
                            role="alert"
                        >
                            {{ schoolFormValidationErrors[field] }}
                        </small>
                        <small
                            v-else-if="
                                moduleKey === 'roles' &&
                                roleFormSubmitted &&
                                roleFormValidationErrors[field]
                            "
                            class="field-error"
                            role="alert"
                        >
                            {{ roleFormValidationErrors[field] }}
                        </small>
                        <small
                            v-else-if="
                                userFormSubmitted &&
                                userFormValidationErrors[field]
                            "
                            class="field-error"
                            role="alert"
                        >
                            {{ userFormValidationErrors[field] }}
                        </small>
                        <small
                            v-if="moduleKey === 'households'"
                            class="field-error household-validation-slot"
                            role="alert"
                        >
                            {{
                                householdFormSubmitted
                                    ? householdFormValidationErrors[field]
                                    : ""
                            }}
                        </small>
                        <small
                            v-if="field === 'username' && userFormRequestError"
                            class="field-error"
                            role="alert"
                            >{{ userFormRequestError }}</small
                        >
                    </label>
                    <fieldset
                        v-if="
                            moduleKey === 'users' && field === 'village_codes'
                        "
                        class="workspace-field user-village-scope-field"
                    >
                        <legend>{{ fieldLabel(field) }}</legend>
                        <div class="permission-options">
                            <label
                                v-for="village in formVillages"
                                :key="String(village.code)"
                            >
                                <input
                                    v-model="selectedUserVillageCodes"
                                    type="checkbox"
                                    :value="String(village.code)"
                                    :disabled="formOptionsBusy"
                                />
                                <span>{{ village.name }}</span>
                            </label>
                            <small v-if="!formVillages.length" class="muted"
                                >Xã này chưa có thôn/xóm để giới hạn.</small
                            >
                        </div>
                        <small class="muted"
                            >Không chọn thôn nào thì tài khoản được xem dữ liệu
                            toàn xã.</small
                        >
                        <small
                            v-if="formErrors[field]"
                            class="field-error"
                            role="alert"
                            >{{ formErrors[field] }}</small
                        >
                        <small
                            v-else-if="
                                userFormSubmitted &&
                                userFormValidationErrors[field]
                            "
                            class="field-error"
                            role="alert"
                            >{{ userFormValidationErrors[field] }}</small
                        >
                    </fieldset>
                </template>
                <p
                    v-if="moduleKey === 'users' && formOptionsBusy"
                    class="muted"
                    role="status"
                >
                    Đang tải nhóm quyền và địa bàn…
                </p>
                <p
                    v-else-if="
                        moduleKey === 'users' && primaryAdminUnavailableMessage
                    "
                    class="notice"
                    role="status"
                >
                    {{ primaryAdminUnavailableMessage }}
                </p>
                <p
                    v-else-if="
                        moduleKey === 'users' &&
                        form.scope_level &&
                        !assignableUserRoles.length
                    "
                    class="muted"
                    role="status"
                >
                    Không có nhóm quyền phù hợp với cấp quản lý đã chọn.
                </p>
                <div v-if="moduleKey === 'merge'" class="village-merge-columns">
                    <section class="merge-selection-card village-merge-card">
                        <div class="merge-step">
                            <span>01</span>
                            <div>
                                <h3>Thôn đích</h3>
                                <p>Nơi tiếp nhận dữ liệu sau khi gộp</p>
                            </div>
                        </div>
                        <label class="workspace-field">
                            <span>Chọn thôn đích</span>
                            <select
                                v-model="form.target_code"
                                required
                                @change="changeVillageMergeTarget"
                            >
                                <option value="">Chọn thôn tiếp nhận</option>
                                <option
                                    v-for="village in rows.filter(
                                        isAvailableVillageMergeTarget,
                                    )"
                                    :key="String(village.code)"
                                    :value="String(village.code)"
                                >
                                    {{ village.name }} · {{ village.code }}
                                </option>
                            </select>
                            <small
                                v-if="formErrors.target_code"
                                class="field-error"
                                role="alert"
                            >
                                {{ formErrors.target_code }}
                            </small>
                        </label>
                        <div
                            v-if="form.target_code"
                            class="village-merge-target-summary"
                        >
                            <strong>
                                {{
                                    rows.find(
                                        (village) =>
                                            String(village.code) ===
                                            String(form.target_code),
                                    )?.name
                                }}
                            </strong>
                            <span>Mã thôn: {{ form.target_code }}</span>
                            <span class="village-merge-status active">
                                Đang nhận phiếu
                            </span>
                        </div>
                        <p class="village-merge-target-hint">
                            Thôn đích phải đang nhận phiếu. Toàn bộ phiếu từ
                            thôn nguồn sẽ được chuyển sang địa bàn này.
                        </p>
                        <ol class="village-merge-guide">
                            <li>Chọn một hoặc nhiều thôn nguồn cần gộp.</li>
                            <li>
                                Tạm dừng nhận phiếu ở tất cả thôn nguồn đã chọn.
                            </li>
                            <li>
                                Nhấn <strong>Gộp thôn</strong> để chuyển phiếu
                                sang thôn đích.
                            </li>
                        </ol>
                    </section>
                    <section
                        class="merge-selection-card village-merge-card village-merge-source-card"
                    >
                        <div class="merge-step">
                            <span>02</span>
                            <div>
                                <h3>
                                    Thôn nguồn
                                    <span
                                        class="required-indicator"
                                        aria-hidden="true"
                                        >*</span
                                    >
                                </h3>
                                <p>Chọn các thôn cần chuyển dữ liệu</p>
                            </div>
                            <span
                                class="village-merge-selection-count"
                                :class="{
                                    selected: selectedSourceCodes.length > 0,
                                }"
                            >
                                {{ selectedSourceCodes.length }}
                                / {{ selectableVillageMergeSources.length }}
                                đã chọn
                            </span>
                        </div>
                        <label class="workspace-field">
                            <span>Tìm mã hoặc tên thôn</span>
                            <input
                                v-model="villageMergeSourceSearch"
                                type="search"
                                placeholder="Nhập mã hoặc tên thôn"
                            />
                        </label>
                        <div class="village-merge-source-list">
                            <label
                                v-for="village in filteredVillageMergeSources"
                                :key="String(village.code)"
                                class="village-merge-source-option"
                                :class="{
                                    selected: selectedSourceCodes.includes(
                                        String(village.code),
                                    ),
                                }"
                            >
                                <input
                                    v-model="selectedSourceCodes"
                                    type="checkbox"
                                    :value="String(village.code)"
                                    :disabled="villageMergeBusy"
                                />
                                <span class="village-merge-source-name">
                                    <strong>{{ village.name }}</strong>
                                    <small>{{ village.code }}</small>
                                </span>
                                <span class="village-merge-source-details">
                                    <small>
                                        {{
                                            Number(
                                                village.households_count ?? 0,
                                            ).toLocaleString("vi-VN")
                                        }}
                                        phiếu
                                    </small>
                                    <span
                                        class="village-merge-status"
                                        :class="
                                            village.intake_paused_at
                                                ? 'paused'
                                                : 'active'
                                        "
                                    >
                                        {{
                                            villageOperationalStatusLabel(
                                                village.operational_status,
                                            )
                                        }}
                                    </span>
                                </span>
                            </label>
                            <p
                                v-if="!filteredVillageMergeSources.length"
                                class="village-merge-empty-selection"
                            >
                                {{
                                    rows.length === 0
                                        ? "Không có thôn nào trong phạm vi quản lý."
                                        : selectableVillageMergeSources.length
                                          ? "Không tìm thấy thôn phù hợp."
                                          : form.target_code
                                            ? "Cần chọn ít nhất một thôn nguồn khác với thôn đích."
                                            : "Không có thôn nguồn phù hợp để gộp."
                                }}
                            </p>
                        </div>
                        <div class="village-merge-source-footer">
                            <div class="village-merge-intake-actions">
                                <template v-if="selectedSourceCodes.length">
                                    <button
                                        v-if="selectedSourcesHaveActive"
                                        class="secondary-button"
                                        type="button"
                                        :disabled="villageMergeBusy"
                                        @click="
                                            changeSelectedVillageIntake('pause')
                                        "
                                    >
                                        {{
                                            villageMergeBusy
                                                ? "Đang cập nhật…"
                                                : "Tạm dừng nhận phiếu"
                                        }}
                                    </button>
                                    <button
                                        v-if="
                                            selectedVillageMergeSources.some(
                                                (village) =>
                                                    Boolean(
                                                        village.intake_paused_at,
                                                    ),
                                            )
                                        "
                                        class="secondary-button"
                                        type="button"
                                        :disabled="villageMergeBusy"
                                        @click="
                                            changeSelectedVillageIntake(
                                                'resume',
                                            )
                                        "
                                    >
                                        Mở lại nhận phiếu
                                    </button>
                                </template>
                                <span v-else class="village-merge-intake-hint">
                                    Chọn thôn nguồn để tạm dừng nhận phiếu trước
                                    khi gộp.
                                </span>
                            </div>
                            <p
                                class="village-merge-safety-note"
                                :class="{
                                    ready:
                                        selectedSourceCodes.length > 0 &&
                                        selectedSourcesPaused,
                                    warning:
                                        selectedSourceCodes.length > 0 &&
                                        !selectedSourcesPaused,
                                    idle: selectedSourceCodes.length === 0,
                                }"
                                role="status"
                            >
                                {{
                                    selectedSourceCodes.length === 0
                                        ? "Chọn ít nhất một thôn nguồn để tiếp tục."
                                        : selectedSourcesPaused
                                          ? "Đã sẵn sàng: tất cả thôn nguồn đang tạm dừng nhận phiếu."
                                          : "Cần tạm dừng nhận phiếu tại tất cả thôn nguồn trước khi gộp. Không thể tạm dừng khi còn tệp nhập đang chờ hoặc xử lý."
                                }}
                            </p>
                        </div>
                    </section>
                </div>
                <label v-if="moduleKey === 'users'" class="workspace-field">
                    <span
                        >Mật khẩu (12–18 ký tự)
                        {{ editing ? "(để trống nếu không đổi)" : "" }}</span
                    >
                    <div class="password-input-control">
                        <input
                            v-model="form.password"
                            :type="showUserPassword ? 'text' : 'password'"
                            minlength="12"
                            maxlength="18"
                            :required="!editing"
                            :aria-invalid="
                                Boolean(
                                    formErrors.password ||
                                    (userFormSubmitted &&
                                        userFormValidationErrors.password),
                                )
                            "
                        />
                        <button
                            class="password-visibility-button"
                            type="button"
                            :aria-label="
                                showUserPassword
                                    ? 'Ẩn mật khẩu'
                                    : 'Hiện mật khẩu'
                            "
                            :aria-pressed="showUserPassword"
                            @click="showUserPassword = !showUserPassword"
                        >
                            {{ showUserPassword ? "Ẩn" : "Hiện" }}
                        </button>
                    </div>
                    <small
                        v-if="
                            formErrors.password ||
                            (userFormSubmitted &&
                                userFormValidationErrors.password)
                        "
                        class="field-error"
                        role="alert"
                        >{{
                            formErrors.password ||
                            (userFormSubmitted &&
                                userFormValidationErrors.password)
                        }}</small
                    >
                </label>
                <label
                    v-if="moduleKey === 'users' && (!editing || form.password)"
                    class="workspace-field"
                >
                    <span>Nhập lại mật khẩu</span>
                    <div class="password-input-control">
                        <input
                            v-model="form.password_confirmation"
                            :type="
                                showUserPasswordConfirmation
                                    ? 'text'
                                    : 'password'
                            "
                            :required="!editing || Boolean(form.password)"
                            minlength="12"
                            maxlength="18"
                            :aria-invalid="
                                Boolean(
                                    formErrors.password_confirmation ||
                                    (userFormSubmitted &&
                                        userFormValidationErrors.password_confirmation),
                                )
                            "
                        />
                        <button
                            class="password-visibility-button"
                            type="button"
                            :aria-label="
                                showUserPasswordConfirmation
                                    ? 'Ẩn mật khẩu xác nhận'
                                    : 'Hiện mật khẩu xác nhận'
                            "
                            :aria-pressed="showUserPasswordConfirmation"
                            @click="
                                showUserPasswordConfirmation =
                                    !showUserPasswordConfirmation
                            "
                        >
                            {{ showUserPasswordConfirmation ? "Ẩn" : "Hiện" }}
                        </button>
                    </div>
                    <small
                        v-if="
                            formErrors.password_confirmation ||
                            (userFormSubmitted &&
                                userFormValidationErrors.password_confirmation)
                        "
                        class="field-error"
                        role="alert"
                        >{{
                            formErrors.password_confirmation ||
                            (userFormSubmitted &&
                                userFormValidationErrors.password_confirmation)
                        }}</small
                    >
                </label>
                <div
                    v-if="moduleKey === 'roles'"
                    class="workspace-field role-permissions-field"
                >
                    <span class="required-field-label">
                        Ma trận phân quyền · {{ permissionIds.length }} quyền đã
                        chọn
                    </span>
                    <div class="role-validation-message-slot">
                        <small
                            v-if="
                                rolePermissionError ||
                                (roleFormSubmitted &&
                                    roleFormValidationErrors.permissions)
                            "
                            class="field-error"
                            role="alert"
                        >
                            {{
                                rolePermissionError ||
                                roleFormValidationErrors.permissions
                            }}
                        </small>
                        <p
                            v-else-if="!roleFormReady"
                            class="muted form-validation-hint role-validation-hint"
                            role="status"
                        >
                            Hoàn tất thông tin nhóm và chọn ít nhất một quyền
                            trước khi lưu.
                        </p>
                    </div>
                    <div
                        v-if="permissionMatrixRows.length"
                        class="permission-matrix-wrap"
                    >
                        <table class="permission-matrix-table">
                            <thead>
                                <tr>
                                    <th scope="col">Chức năng</th>
                                    <th scope="col">
                                        <label
                                            class="permission-matrix-checkbox"
                                            title="Chọn tất cả quyền trong cấp quản lý này"
                                        >
                                            <input
                                                type="checkbox"
                                                aria-label="Chọn tất cả quyền"
                                                :checked="
                                                    permissionMatrixOverallState.checked
                                                "
                                                :indeterminate="
                                                    permissionMatrixOverallState.indeterminate
                                                "
                                                @change="
                                                    toggleAllMatrixPermissions(
                                                        (
                                                            $event.target as HTMLInputElement
                                                        ).checked,
                                                    )
                                                "
                                            />
                                            <span>Chọn cả</span>
                                        </label>
                                    </th>
                                    <th
                                        v-for="action in permissionMatrixActions"
                                        :key="action"
                                        scope="col"
                                    >
                                        {{
                                            permissionMatrixActionLabels[action]
                                        }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in permissionMatrixRows"
                                    :key="row.key"
                                    :class="{
                                        'permission-matrix-section':
                                            row.section,
                                        'permission-matrix-menu-group':
                                            row.section && row.depth === 0,
                                    }"
                                >
                                    <th
                                        scope="row"
                                        class="permission-matrix-title"
                                        :style="{
                                            paddingLeft: `${12 + row.depth * 22}px`,
                                        }"
                                    >
                                        {{ row.label }}
                                    </th>
                                    <td>
                                        <input
                                            type="checkbox"
                                            class="permission-matrix-input"
                                            :aria-label="`Chọn tất cả quyền: ${row.label}`"
                                            :checked="
                                                matrixRowState(row).checked
                                            "
                                            :indeterminate="
                                                matrixRowState(row)
                                                    .indeterminate
                                            "
                                            @change="
                                                toggleMatrixRow(
                                                    row,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                    </td>
                                    <td
                                        v-for="action in permissionMatrixActions"
                                        :key="action"
                                    >
                                        <input
                                            type="checkbox"
                                            class="permission-matrix-input"
                                            :aria-label="`${permissionMatrixActionLabels[action]}: ${row.label}`"
                                            :disabled="
                                                !row.actions[action]?.length
                                            "
                                            :checked="
                                                matrixActionState(row, action)
                                            "
                                            @change="
                                                toggleMatrixAction(
                                                    row,
                                                    action,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="permission-matrix-empty">
                        Chọn cấp quản lý để xem các quyền có thể cấp cho nhóm
                        này.
                    </div>
                </div>
                <p v-if="moduleKey === 'villages'" class="muted">
                    Mã thôn / xóm sẽ tự sinh theo cấu trúc [Mã xã]_[Số thứ tự],
                    ví dụ C01_001. Mã cũ được giữ nguyên.
                </p>
                <section
                    v-if="moduleKey === 'households' && editing"
                    class="household-edit-members"
                >
                    <div class="household-edit-members-heading">
                        <div>
                            <h4>Danh sách nhân khẩu</h4>
                        </div>
                        <span class="household-view-member-count">
                            {{ householdEditPersons.length }} nhân khẩu
                        </span>
                        <button
                            v-if="permissions.includes('create-households')"
                            class="secondary-button"
                            type="button"
                            @click="addInlineHouseholdMember"
                        >
                            + Thêm nhân khẩu
                        </button>
                    </div>
                    <div class="household-edit-members-table-wrap">
                        <table
                            v-member-column-resize
                            class="household-edit-members-table"
                            @input="clearInlineMemberFieldError"
                            @change="clearInlineMemberFieldError"
                        >
                            <colgroup>
                                <col class="member-col-stt" />
                                <col class="member-col-name" />
                                <col class="member-col-short" />
                                <col class="member-col-medium" />
                                <col class="member-col-date" />
                                <col class="member-col-gender" />
                                <col class="member-col-select" />
                                <col class="member-col-medium" />
                                <col class="member-col-medium" />
                                <col class="member-col-medium" />
                                <col class="member-col-phone" />
                                <col class="member-col-medium" />
                                <col class="member-col-school-year" />
                                <col
                                    class="member-col-small"
                                    v-for="column in 28"
                                    :key="`member-col-${column}`"
                                />
                                <col class="member-col-actions" />
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th class="is-required">
                                        Họ và tên đệm
                                        <span class="required-indicator">*</span>
                                    </th>
                                    <th class="is-required">
                                        Tên <span class="required-indicator">*</span>
                                    </th>
                                    <th class="is-required">
                                        Quan hệ
                                        <span class="required-indicator">*</span>
                                    </th>
                                    <th class="is-required">
                                        Ngày sinh
                                        <span class="required-indicator">*</span>
                                    </th>
                                    <th class="is-required">
                                        Giới tính
                                        <span class="required-indicator">*</span>
                                    </th>
                                    <th class="is-required">
                                        Dân tộc
                                        <span class="required-indicator">*</span>
                                    </th>
                                    <th>Tôn giáo</th>
                                    <th>Diện ưu tiên</th>
                                    <th>Cha/mẹ hoặc người thân</th>
                                    <th>Số điện thoại</th>
                                    <th>Ghi chú</th>
                                    <th class="is-required">
                                        Năm học
                                        <span class="required-indicator">*</span>
                                    </th>
                                    <th>Lớp đang học</th>
                                    <th>Khối học</th>
                                    <th>Trường đang học</th>
                                    <th>Bổ túc</th>
                                    <th>Tốt nghiệp văn hóa</th>
                                    <th>Năm tốt nghiệp văn hóa</th>
                                    <th>Tốt nghiệp nghề</th>
                                    <th>Năm tốt nghiệp nghề</th>
                                    <th>Lớp dở dang</th>
                                    <th>Năm dở dang</th>
                                    <th>Lớp bỏ học</th>
                                    <th>Năm bỏ học</th>
                                    <th>Lớp mù chữ đang học</th>
                                    <th>Lớp đã hoàn thành</th>
                                    <th>Mức tái mù chữ</th>
                                    <th>Năng lực học tập</th>
                                    <th>Vận động</th>
                                    <th>Nghe nói</th>
                                    <th>Thị giác</th>
                                    <th>Thần kinh</th>
                                    <th>Trí tuệ</th>
                                    <th>Học tập</th>
                                    <th>Tự kỷ</th>
                                    <th>Khuyết tật khác</th>
                                    <th>Có giấy xác nhận</th>
                                    <th>Có khả năng học</th>
                                    <th>Hoàn cảnh đặc biệt</th>
                                    <th>Chi tiết hoàn cảnh</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template
                                    v-for="(
                                        person, index
                                    ) in householdEditPersons"
                                    :key="
                                        person.id === undefined
                                            ? `new-${index}`
                                            : String(person.id)
                                    "
                                >
                                    <tr
                                        v-if="
                                            Math.floor(
                                                index / householdEditPageSize,
                                            ) +
                                                1 ===
                                            householdEditPage
                                        "
                                        :data-member-index="index"
                                    >
                                        <td>{{ index + 1 }}</td>
                                        <td>
                                            <input
                                                v-model="person.last_name"
                                                :name="`members[${index}][last_name]`"
                                                class="household-edit-member-last-name"
                                                :data-member-index="index"
                                                type="text"
                                                maxlength="50"
                                                aria-label="Họ và tên đệm"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="person.first_name"
                                                :name="`members[${index}][first_name]`"
                                                type="text"
                                                maxlength="100"
                                                aria-label="Tên"
                                            />
                                        </td>
                                        <td>
                                            <select
                                                v-model="
                                                    person.relationship_with_head
                                                "
                                                :name="`members[${index}][relationship_with_head]`"
                                            >
                                                <option value="Chủ hộ">
                                                    Chủ hộ
                                                </option>
                                                <option
                                                    v-for="relationship in memberRelationships"
                                                    :key="relationship"
                                                    :value="relationship"
                                                >
                                                    {{ relationship }}
                                                </option>
                                            </select>
                                        </td>
                                        <td>
                                            <button
                                                class="household-date-picker-trigger"
                                                type="button"
                                                :name="`members[${index}][dob]`"
                                                aria-label="Chọn ngày sinh"
                                                @click="
                                                    openHouseholdDatePicker(
                                                        $event,
                                                        {
                                                            kind: 'inline',
                                                            person,
                                                        },
                                                    )
                                                "
                                            >
                                                {{
                                                    datePickerDisplayValue(
                                                        person.dob,
                                                    ) || "dd/mm/yyyy"
                                                }}
                                                <span aria-hidden="true">▦</span>
                                            </button>
                                        </td>
                                        <td>
                                            <select
                                                v-model="person.gender"
                                                :name="`members[${index}][gender]`"
                                            >
                                                <option value="NAM">Nam</option>
                                                <option value="NU">Nữ</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select
                                                v-model="person.ethnicity_code"
                                                :name="`members[${index}][ethnicity_code]`"
                                                aria-label="Dân tộc"
                                            >
                                                <option value="">
                                                    Chọn dân tộc
                                                </option>
                                                <option
                                                    v-for="ethnicity in ethnicities"
                                                    :key="
                                                        String(ethnicity.code)
                                                    "
                                                    :value="
                                                        String(ethnicity.code)
                                                    "
                                                >
                                                    {{ ethnicity.name }}
                                                </option>
                                            </select>
                                        </td>
                                        <td>
                                            <input
                                                v-model="person.religion"
                                                maxlength="50"
                                                aria-label="Tôn giáo"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="person.priority_type"
                                                maxlength="100"
                                                aria-label="Diện ưu tiên"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="person.parent_name"
                                                maxlength="150"
                                                aria-label="Cha/mẹ hoặc người thân"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="person.phone"
                                                @input="
                                                    sanitizeInlineMemberPhone(
                                                        person,
                                                        $event,
                                                    )
                                                "
                                                maxlength="20"
                                                inputmode="tel"
                                                aria-label="Số điện thoại"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="person.note"
                                                maxlength="2000"
                                                aria-label="Ghi chú"
                                            />
                                        </td>
                                        <td>
                                            <div class="inline-school-year-control">
                                                <input
                                                    :value="
                                                        inlineSchoolYearStart(
                                                            person,
                                                        )
                                                    "
                                                    @input="
                                                        updateInlineSchoolYear(
                                                            person,
                                                            $event,
                                                        )
                                                    "
                                                    maxlength="4"
                                                    aria-label="Năm học"
                                                />
                                                <span
                                                    v-if="
                                                        inlineSchoolYearEnd(
                                                            person,
                                                        )
                                                    "
                                                    >-{{
                                                        inlineSchoolYearEnd(
                                                            person,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .current_class
                                                "
                                                @input="
                                                    updateInlineClass(
                                                        person,
                                                        $event,
                                                    )
                                                "
                                                maxlength="50"
                                                aria-label="Lớp đang học"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .academic_block
                                                "
                                                readonly
                                                maxlength="50"
                                                aria-label="Khối học"
                                            />
                                        </td>
                                        <td>
                                            <select
                                                v-model="
                                                    inlineEducation(person)
                                                        .school_code
                                                "
                                                :disabled="
                                                    !inlineSchoolGrade(person)
                                                "
                                                :aria-busy="
                                                    inlineSchoolOptionsLoading[
                                                        inlineSchoolOptionsKey(
                                                            person,
                                                        )
                                                    ]
                                                "
                                                @focus="
                                                    loadInlineSchoolOptions(
                                                        person,
                                                    )
                                                "
                                                @change="
                                                    inlineSchoolChanged(person)
                                                "
                                                aria-label="Trường đang học"
                                            >
                                                <option value="">
                                                    {{
                                                        !inlineSchoolGrade(
                                                            person,
                                                        )
                                                            ? "Nhập lớp trước"
                                                            : inlineSchoolOptionsLoading[
                                                                    inlineSchoolOptionsKey(
                                                                        person,
                                                                    )
                                                                ]
                                                              ? "Đang tải trường..."
                                                              : "Chọn trường"
                                                    }}
                                                </option>
                                                <option
                                                    v-if="
                                                        inlineEducation(person)
                                                            .school_code &&
                                                        !(
                                                            inlineSchoolOptions[
                                                                inlineSchoolOptionsKey(
                                                                    person,
                                                                )
                                                            ] ?? []
                                                        ).some(
                                                            (school) =>
                                                                String(
                                                                    school.code,
                                                                ) ===
                                                                String(
                                                                    inlineEducation(
                                                                        person,
                                                                    )
                                                                        .school_code,
                                                                ),
                                                        )
                                                    "
                                                    :value="
                                                        String(
                                                            inlineEducation(
                                                                person,
                                                            ).school_code,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        person._school_name ||
                                                        inlineEducation(person)
                                                            .school_code
                                                    }}
                                                </option>
                                                <option
                                                    v-for="school in inlineSchoolOptions[
                                                        inlineSchoolOptionsKey(
                                                            person,
                                                        )
                                                    ] ?? []"
                                                    :key="String(school.code)"
                                                    :value="String(school.code)"
                                                >
                                                    {{ school.name }} ·
                                                    {{ school.code }}
                                                </option>
                                            </select>
                                            <small
                                                v-if="
                                                    inlineSchoolOptionsErrors[
                                                        inlineSchoolOptionsKey(
                                                            person,
                                                        )
                                                    ]
                                                "
                                                class="field-error"
                                                role="alert"
                                            >
                                                {{
                                                    inlineSchoolOptionsErrors[
                                                        inlineSchoolOptionsKey(
                                                            person,
                                                        )
                                                    ]
                                                }}
                                            </small>
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .is_complementary
                                                "
                                                type="checkbox"
                                                aria-label="Bổ túc"
                                            />
                                        </td>
                                        <td>
                                            <select
                                                v-model="
                                                    inlineEducation(person)
                                                        .graduation_level
                                                "
                                                aria-label="Tốt nghiệp văn hóa"
                                            >
                                                <option value="">
                                                    Chưa chọn
                                                </option>
                                                <option value="MN">MN</option>
                                                <option value="TH">TH</option>
                                                <option value="THCS">
                                                    THCS
                                                </option>
                                                <option value="THPT">
                                                    THPT
                                                </option>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="inline-school-year-control">
                                                <input
                                                    :value="
                                                        inlineEducationYearStart(
                                                            person,
                                                            'graduation_year',
                                                        )
                                                    "
                                                    @input="
                                                        updateInlineEducationYear(
                                                            person,
                                                            'graduation_year',
                                                            $event,
                                                        )
                                                    "
                                                    maxlength="4"
                                                    inputmode="numeric"
                                                    placeholder="YYYY"
                                                    aria-label="Năm tốt nghiệp văn hóa"
                                                />
                                                <span class="inline-year-suffix">
                                                    -{{
                                                        inlineEducationYearEndHint(
                                                            person,
                                                            "graduation_year",
                                                        )
                                                    }}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .vocational_grad_level
                                                "
                                                maxlength="50"
                                                aria-label="Tốt nghiệp nghề"
                                            />
                                        </td>
                                        <td>
                                            <div class="inline-school-year-control">
                                                <input
                                                    :value="
                                                        inlineEducationYearStart(
                                                            person,
                                                            'vocational_grad_year',
                                                        )
                                                    "
                                                    @input="
                                                        updateInlineEducationYear(
                                                            person,
                                                            'vocational_grad_year',
                                                            $event,
                                                        )
                                                    "
                                                    maxlength="4"
                                                    inputmode="numeric"
                                                    placeholder="YYYY"
                                                    aria-label="Năm tốt nghiệp nghề"
                                                />
                                                <span class="inline-year-suffix">
                                                    -{{
                                                        inlineEducationYearEndHint(
                                                            person,
                                                            "vocational_grad_year",
                                                        )
                                                    }}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .finished_class
                                                "
                                                maxlength="20"
                                                aria-label="Lớp dở dang"
                                            />
                                        </td>
                                        <td>
                                            <div class="inline-school-year-control">
                                                <input
                                                    :value="
                                                        inlineEducationYearStart(
                                                            person,
                                                            'finished_year',
                                                        )
                                                    "
                                                    @input="
                                                        updateInlineEducationYear(
                                                            person,
                                                            'finished_year',
                                                            $event,
                                                        )
                                                    "
                                                    maxlength="4"
                                                    inputmode="numeric"
                                                    placeholder="YYYY"
                                                    aria-label="Năm dở dang"
                                                />
                                                <span class="inline-year-suffix">
                                                    -{{
                                                        inlineEducationYearEndHint(
                                                            person,
                                                            "finished_year",
                                                        )
                                                    }}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .dropped_class
                                                "
                                                maxlength="20"
                                                aria-label="Lớp bỏ học"
                                            />
                                        </td>
                                        <td>
                                            <div class="inline-school-year-control">
                                                <input
                                                    :value="
                                                        inlineEducationYearStart(
                                                            person,
                                                            'dropped_year',
                                                        )
                                                    "
                                                    @input="
                                                        updateInlineEducationYear(
                                                            person,
                                                            'dropped_year',
                                                            $event,
                                                        )
                                                    "
                                                    maxlength="4"
                                                    inputmode="numeric"
                                                    placeholder="YYYY"
                                                    aria-label="Năm bỏ học"
                                                />
                                                <span class="inline-year-suffix">
                                                    -{{
                                                        inlineEducationYearEndHint(
                                                            person,
                                                            "dropped_year",
                                                        )
                                                    }}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .literacy_current_class
                                                "
                                                maxlength="20"
                                                aria-label="Lớp mù chữ đang học"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .literacy_completed_class
                                                "
                                                maxlength="20"
                                                aria-label="Lớp đã hoàn thành"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .literacy_relapse_level
                                                "
                                                maxlength="2"
                                                aria-label="Mức tái mù chữ"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineEducation(person)
                                                        .learning_capacity
                                                "
                                                maxlength="50"
                                                aria-label="Năng lực học tập"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .mobility_disability
                                                "
                                                type="checkbox"
                                                aria-label="Khuyết tật vận động"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .hearing_speech_disability
                                                "
                                                type="checkbox"
                                                aria-label="Khuyết tật nghe nói"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .visual_disability
                                                "
                                                type="checkbox"
                                                aria-label="Khuyết tật thị giác"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .mental_disability
                                                "
                                                type="checkbox"
                                                aria-label="Khuyết tật thần kinh"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .intellectual_disability
                                                "
                                                type="checkbox"
                                                aria-label="Khuyết tật trí tuệ"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .learning_disability
                                                "
                                                type="checkbox"
                                                aria-label="Khuyết tật học tập"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .autism
                                                "
                                                type="checkbox"
                                                aria-label="Tự kỷ"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .other_disability
                                                "
                                                type="checkbox"
                                                aria-label="Khuyết tật khác"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .has_disability_cert
                                                "
                                                type="checkbox"
                                                aria-label="Có giấy xác nhận khuyết tật"
                                            />
                                        </td>
                                        <td class="household-inline-checkbox">
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .can_study
                                                "
                                                type="checkbox"
                                                aria-label="Có khả năng học"
                                            />
                                        </td>
                                        <td>
                                            <select
                                                v-model="
                                                    inlineDisability(person)
                                                        .special_circumstance
                                                "
                                                aria-label="Hoàn cảnh đặc biệt"
                                            >
                                                <option value="">Không</option>
                                                <option value="Chuyển đến">
                                                    Chuyển đến
                                                </option>
                                                <option value="Chuyển đi">
                                                    Chuyển đi
                                                </option>
                                            </select>
                                        </td>
                                        <td>
                                            <input
                                                v-model="
                                                    inlineDisability(person)
                                                        .special_circumstance_detail
                                                "
                                                maxlength="2000"
                                                aria-label="Chi tiết hoàn cảnh đặc biệt"
                                            />
                                        </td>
                                        <td
                                            class="household-inline-member-actions"
                                        >
                                            <button
                                                v-if="
                                                    !householdDetailReadOnly &&
                                                    permissions.includes(
                                                        'update-households',
                                                    )
                                                "
                                                class="household-edit-member-button"
                                                type="button"
                                                :disabled="
                                                    memberBusy ||
                                                    !inlineHouseholdPersonChanged(
                                                        person,
                                                        index,
                                                    )
                                                "
                                                @click="
                                                    saveInlineHouseholdMember(
                                                        person,
                                                        index,
                                                    )
                                                "
                                            >
                                                Lưu
                                            </button>
                                            <button
                                                v-if="
                                                    permissions.includes(
                                                        'delete-households',
                                                    )
                                                "
                                                class="household-edit-member-button household-edit-member-delete"
                                                type="button"
                                                :disabled="memberBusy"
                                                @click="
                                                    person.id === undefined
                                                        ? householdEditPersons.splice(
                                                              index,
                                                              1,
                                                          )
                                                        : deleteMember(person)
                                                "
                                            >
                                                Xóa
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div
                        v-if="householdEditPageCount > 1"
                        class="household-detail-pagination"
                        aria-label="Phân trang nhân khẩu"
                    >
                        <span aria-live="polite">
                            {{
                                (householdEditPage - 1) *
                                    householdEditPageSize +
                                1
                            }}–{{
                                Math.min(
                                    householdEditPage * householdEditPageSize,
                                    householdEditPersons.length,
                                )
                            }}
                            / {{ householdEditPersons.length }} nhân khẩu
                        </span>
                        <div>
                            <button
                                type="button"
                                :disabled="householdEditPage <= 1"
                                @click="householdEditPage--"
                            >
                                Trước
                            </button>
                            <span>
                                Trang {{ householdEditPage }} /
                                {{ householdEditPageCount }}
                            </span>
                            <button
                                type="button"
                                :disabled="
                                    householdEditPage >= householdEditPageCount
                                "
                                @click="householdEditPage++"
                            >
                                Sau
                            </button>
                        </div>
                    </div>
                </section>
                <div
                    class="pagination-actions"
                    :class="{
                        'role-form-actions': moduleKey === 'roles',
                        'user-form-actions': moduleKey === 'users',
                        'household-form-actions': moduleKey === 'households',
                        'village-merge-form-actions': moduleKey === 'merge',
                    }"
                >
                    <button
                        v-if="
                            moduleKey === 'households' &&
                            isHouseholdCreatePage &&
                            !editing
                        "
                        class="secondary-button"
                        type="button"
                        @click="closeConfigurationForm"
                    >
                        Quay lại danh sách phiếu
                    </button>
                    <button
                        class="primary-button"
                        type="submit"
                        :disabled="
                            busy ||
                            villageMergeBusy ||
                            formOptionsBusy ||
                            (moduleKey === 'users' &&
                                editing &&
                                !userFormChanged) ||
                            !villageFormReady ||
                            (moduleKey === 'merge' &&
                                (!form.target_code ||
                                    !selectedSourceCodes.length ||
                                    !selectedSourcesPaused))
                        "
                    >
                        {{
                            busy
                                ? "Đang lưu…"
                                : moduleKey === "households" && !editing
                                  ? createHouseholdWizardStep === 1
                                      ? "Lưu và tiếp tục"
                                      : createHouseholdWizardStep === 3
                                        ? "Hoàn tất phiếu"
                                        : "Tiếp tục"
                                  : moduleKey === "roles"
                                    ? "Lưu nhóm quyền"
                                    : moduleKey === "merge"
                                      ? "Gộp thôn"
                                      : "Lưu"
                        }}
                    </button>
                    <button
                        v-if="!isHouseholdCreatePage"
                        class="secondary-button"
                        type="button"
                        @click="
                            moduleKey === 'merge'
                                ? clearVillageMergeSelection()
                                : closeConfigurationForm()
                        "
                    >
                        {{ moduleKey === "merge" ? "Xóa lựa chọn" : "Hủy" }}
                    </button>
                </div>
            </form>
        </div>

        <section
            v-if="
                isHouseholdCreateContinuationPage &&
                createHouseholdWizardStep === 3 &&
                !memberEditorOpen
            "
            class="household-create-members-page household-create-members-page-backdrop"
        >
            <header class="household-create-members-heading">
                <div>
                    <p class="workspace-eyebrow">TẠO MỚI PHIẾU ĐIỀU TRA</p>
                    <h3>
                        Thành viên hộ gia đình
                        <span
                            v-if="String(form.household_code ?? '').trim()"
                            class="household-number-label"
                            :aria-label="`Mã phiếu ${form.household_code}`"
                        >
                            · Mã phiếu: {{ form.household_code }}
                        </span>
                    </h3>
                    <p>
                        Chủ hộ đã được lưu. Thêm các thành viên còn lại hoặc
                        hoàn tất phiếu.
                    </p>
                </div>
                <div class="household-create-members-heading-actions">
                    <button
                        class="primary-button"
                        type="button"
                        :disabled="memberBusy"
                        @click="beginMember(false)"
                    >
                        Thêm thành viên
                    </button>
                </div>
            </header>
            <div
                v-if="householdEditPersons.length"
                class="household-create-member-list"
            >
                <article
                    v-for="(person, index) in householdEditPersons"
                    :key="String(person.id ?? index)"
                    class="household-create-member-card"
                >
                    <span>{{ index + 1 }}</span>
                    <div>
                        <strong>{{
                            [person.last_name, person.first_name]
                                .filter(Boolean)
                                .join(" ") || "Chưa có họ tên"
                        }}</strong>
                        <small>{{
                            displayFormValue(person.relationship_with_head)
                        }}</small>
                    </div>
                </article>
            </div>
            <p v-else class="muted">Chưa có thành viên trong phiếu.</p>
            <footer class="household-create-members-actions">
                <button
                    class="primary-button"
                    type="button"
                    :disabled="busy || memberBusy"
                    @click="finishHouseholdCreation"
                >
                    Hoàn tất phiếu
                </button>
            </footer>
        </section>

        <section v-if="moduleKey === 'account'" class="account-profile-page">
            <div
                v-if="profile && accountSection !== 'password'"
                class="account-profile-grid"
            >
                <form
                    class="account-profile-card account-information-card account-edit-form"
                    @submit.prevent="saveAccountProfile"
                >
                    <div class="account-card-heading">
                        <span class="account-card-icon" aria-hidden="true"
                            >i</span
                        >
                        <div>
                            <h3>Thông tin cá nhân</h3>
                            <p>
                                Cập nhật thông tin cá nhân của tài khoản hiện
                                tại
                            </p>
                        </div>
                    </div>
                    <div class="account-edit-fields">
                        <label class="workspace-field">
                            <span>Họ và tên</span>
                            <input
                                v-model.trim="accountForm.name"
                                type="text"
                                maxlength="255"
                                required
                                autocomplete="name"
                            />
                            <small
                                v-if="accountFormErrors.name"
                                class="field-error"
                                role="alert"
                                >{{ accountFormErrors.name }}</small
                            >
                        </label>
                        <label class="workspace-field">
                            <span>Email</span>
                            <input
                                v-model.trim="accountForm.email"
                                type="email"
                                maxlength="255"
                                autocomplete="email"
                            />
                            <small
                                v-if="accountFormErrors.email"
                                class="field-error"
                                role="alert"
                                >{{ accountFormErrors.email }}</small
                            >
                        </label>
                        <label class="workspace-field">
                            <span>Số điện thoại</span>
                            <input
                                v-model.trim="accountForm.phone"
                                type="tel"
                                maxlength="20"
                                autocomplete="tel"
                            />
                            <small
                                v-if="accountFormErrors.phone"
                                class="field-error"
                                role="alert"
                                >{{ accountFormErrors.phone }}</small
                            >
                        </label>
                        <label class="workspace-field">
                            <span>Số CCCD</span>
                            <input
                                v-model.trim="accountForm.citizen_id"
                                type="text"
                                inputmode="numeric"
                                maxlength="12"
                                autocomplete="off"
                            />
                            <small
                                v-if="accountFormErrors.citizen_id"
                                class="field-error"
                                role="alert"
                                >{{ accountFormErrors.citizen_id }}</small
                            >
                        </label>
                        <label class="workspace-field">
                            <span>Ngày sinh</span>
                            <input
                                v-model="accountForm.date_of_birth"
                                type="date"
                                :max="latestBirthDate"
                                autocomplete="bday"
                            />
                            <small
                                v-if="accountFormErrors.date_of_birth"
                                class="field-error"
                                role="alert"
                                >{{ accountFormErrors.date_of_birth }}</small
                            >
                        </label>
                    </div>
                    <div class="pagination-actions account-profile-actions">
                        <button
                            class="primary-button"
                            type="submit"
                            :disabled="
                                busy ||
                                !accountFormChanged ||
                                !accountForm.name.trim()
                            "
                        >
                            {{ busy ? "Đang lưu…" : "Lưu thông tin" }}
                        </button>
                    </div>
                </form>
            </div>
            <form
                v-else-if="profile && accountSection === 'password'"
                class="account-profile-card account-password-card"
                @submit.prevent="changePassword"
            >
                <div class="account-card-heading">
                    <span
                        class="account-card-icon account-password-icon"
                        aria-hidden="true"
                        >⌑</span
                    >
                    <div>
                        <h3>Đổi mật khẩu</h3>
                        <p>
                            Nhập mật khẩu hiện tại và đặt mật khẩu mới có ít
                            nhất 12 ký tự
                        </p>
                    </div>
                </div>
                <div class="account-password-fields">
                    <label class="workspace-field">
                        <span>Mật khẩu hiện tại</span>
                        <span class="account-password-input">
                            <input
                                v-model="passwordForm.current_password"
                                :type="
                                    showCurrentPassword ? 'text' : 'password'
                                "
                                autocomplete="current-password"
                                required
                            />
                            <button
                                type="button"
                                :aria-label="
                                    showCurrentPassword
                                        ? 'Ẩn mật khẩu hiện tại'
                                        : 'Hiện mật khẩu hiện tại'
                                "
                                @click="
                                    showCurrentPassword = !showCurrentPassword
                                "
                            >
                                {{ showCurrentPassword ? "Ẩn" : "Hiện" }}
                            </button>
                        </span>
                        <small
                            v-if="passwordFormErrors.current_password"
                            class="field-error"
                            role="alert"
                            >{{ passwordFormErrors.current_password }}</small
                        >
                    </label>
                    <label class="workspace-field">
                        <span>Mật khẩu mới</span>
                        <span class="account-password-input">
                            <input
                                v-model="passwordForm.password"
                                :type="showNewPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                minlength="12"
                                maxlength="18"
                                required
                            />
                            <button
                                type="button"
                                :aria-label="
                                    showNewPassword
                                        ? 'Ẩn mật khẩu mới'
                                        : 'Hiện mật khẩu mới'
                                "
                                @click="showNewPassword = !showNewPassword"
                            >
                                {{ showNewPassword ? "Ẩn" : "Hiện" }}
                            </button>
                        </span>
                        <small
                            v-if="
                                passwordFormErrors.password ||
                                passwordForm.password.length > 18
                            "
                            class="field-error"
                            role="alert"
                            >{{
                                passwordFormErrors.password ||
                                "Mật khẩu mới không được vượt quá 18 ký tự."
                            }}</small
                        >
                    </label>
                    <label class="workspace-field">
                        <span>Xác nhận mật khẩu mới</span>
                        <span class="account-password-input">
                            <input
                                v-model="passwordForm.password_confirmation"
                                :type="
                                    showPasswordConfirmation
                                        ? 'text'
                                        : 'password'
                                "
                                autocomplete="new-password"
                                minlength="12"
                                maxlength="18"
                                required
                            />
                            <button
                                type="button"
                                :aria-label="
                                    showPasswordConfirmation
                                        ? 'Ẩn xác nhận mật khẩu'
                                        : 'Hiện xác nhận mật khẩu'
                                "
                                @click="
                                    showPasswordConfirmation =
                                        !showPasswordConfirmation
                                "
                            >
                                {{ showPasswordConfirmation ? "Ẩn" : "Hiện" }}
                            </button>
                        </span>
                    </label>
                </div>
                <small
                    v-if="passwordFormErrors.password_confirmation"
                    class="field-error"
                    role="alert"
                    >{{ passwordFormErrors.password_confirmation }}</small
                >
                <div class="pagination-actions account-profile-actions">
                    <button
                        class="primary-button"
                        type="submit"
                        :disabled="
                            busy ||
                            !passwordForm.current_password ||
                            passwordForm.password.length < 12 ||
                            passwordForm.password.length > 18 ||
                            !passwordForm.password_confirmation
                        "
                    >
                        {{ busy ? "Đang cập nhật…" : "Đổi mật khẩu" }}
                    </button>
                </div>
            </form>
            <div v-else-if="busy" class="account-profile-loading" role="status">
                Đang tải thông tin tài khoản…
            </div>
        </section>

        <section
            v-if="moduleKey === 'imports'"
            class="excel-import-view"
            :class="`excel-import-view-${importView ?? 'upload'}`"
        >
            <div v-if="importView !== 'history'" class="excel-import-grid">
                <form
                    v-if="permissions.includes('create-imports')"
                    class="excel-import-panel excel-import-form"
                    novalidate
                    @submit.prevent="submitImport"
                >
                    <div class="excel-location-grid">
                        <label class="workspace-field">
                            <span>Năm học</span>
                            <input
                                :value="currentImportSchoolYear"
                                readonly
                                required
                            />
                        </label>
                        <label class="workspace-field">
                            <span>Tỉnh / thành phố</span>
                            <select
                                v-model="form.province_code"
                                aria-required="true"
                                @change="changeFormProvince"
                            >
                                <option value="">Chọn tỉnh / thành phố</option>
                                <option
                                    v-for="province in provinces"
                                    :key="String(province.code)"
                                    :value="String(province.code)"
                                >
                                    {{ province.name }}
                                </option>
                            </select>
                            <small
                                v-if="
                                    importSubmitAttempted && !form.province_code
                                "
                                class="field-error"
                                role="alert"
                            >
                                Yêu cầu chọn tỉnh / thành phố.
                            </small>
                        </label>
                        <label class="workspace-field">
                            <span>Xã / phường</span>
                            <select
                                v-model="form.commune_code"
                                aria-required="true"
                                :disabled="!form.province_code"
                                @change="changeFormCommune"
                            >
                                <option value="">Chọn xã / phường</option>
                                <option
                                    v-for="commune in formCommunes"
                                    :key="String(commune.code)"
                                    :value="String(commune.code)"
                                >
                                    {{ commune.name }}
                                </option>
                            </select>
                            <small
                                v-if="
                                    importSubmitAttempted && !form.commune_code
                                "
                                class="field-error"
                                role="alert"
                            >
                                Yêu cầu chọn xã / phường.
                            </small>
                        </label>
                        <label class="workspace-field">
                            <span>Thôn / xóm</span>
                            <select
                                v-model="form.village_code"
                                aria-required="true"
                                :disabled="!form.commune_code"
                            >
                                <option value="">Chọn thôn / xóm</option>
                                <option
                                    v-for="village in formVillages"
                                    :key="String(village.code)"
                                    :value="String(village.code)"
                                >
                                    {{ village.name }}
                                </option>
                            </select>
                            <small
                                v-if="
                                    importSubmitAttempted && !form.village_code
                                "
                                class="field-error"
                                role="alert"
                            >
                                Yêu cầu chọn thôn / xóm.
                            </small>
                        </label>
                    </div>

                    <label
                        class="excel-file-drop"
                        :class="{ 'is-drag-active': importDropActive }"
                        @dragenter.prevent="importDropActive = true"
                        @dragover.prevent="importDropActive = true"
                        @dragleave.prevent="handleImportDragLeave"
                        @drop.prevent="handleImportDrop"
                    >
                        <input
                            ref="importFileInput"
                            type="file"
                            accept=".xls,.xlsx"
                            multiple
                            aria-required="true"
                            @change="handleImportFileChange"
                        />
                        <span class="excel-file-icon" aria-hidden="true"
                            >↑</span
                        >
                        <strong>{{
                            importFiles.length
                                ? `${importFiles.length} tệp đã chọn`
                                : "Chọn một hoặc nhiều tệp Excel"
                        }}</strong>
                        <small
                            >Tối đa 5 tệp, mỗi tệp 20 MB; tổng dung lượng 35
                            MB</small
                        >
                    </label>
                    <ul v-if="importFiles.length" class="excel-selected-files">
                        <li
                            v-for="(file, index) in importFiles"
                            :key="`${file.name}-${file.lastModified}-${index}`"
                        >
                            <span :title="file.name">{{ file.name }}</span>
                            <button
                                type="button"
                                :aria-label="`Bỏ tệp ${file.name}`"
                                @click="removeImportFile(index)"
                            >
                                ×
                            </button>
                        </li>
                    </ul>
                    <small
                        v-if="
                            importFileError ||
                            (importSubmitAttempted && importFiles.length === 0)
                        "
                        class="field-error excel-file-error"
                        role="alert"
                        style="color: #b42318"
                    >
                        {{
                            importFileError ||
                            "Yêu cầu chọn ít nhất một tệp Excel."
                        }}
                    </small>

                    <div class="excel-import-actions">
                        <button
                            class="primary-button"
                            type="submit"
                            :disabled="busy"
                        >
                            {{ busy ? "Đang tải lên…" : "Tải dữ liệu lên" }}
                        </button>
                        <a
                            class="secondary-button inline-button"
                            href="/api/v1/household-imports/template"
                            >↓&nbsp; Tải biểu mẫu chuẩn</a
                        >
                    </div>
                </form>

                <aside
                    v-if="permissions.includes('create-imports')"
                    class="excel-import-panel excel-import-guide"
                >
                    <span class="excel-guide-label">HƯỚNG DẪN NHẬP</span>
                    <ol>
                        <li>
                            <strong>Tải tệp mẫu</strong
                            ><span>Giữ nguyên cấu trúc cột trong tệp mẫu.</span>
                        </li>
                        <li>
                            <strong>Điền dữ liệu địa bàn</strong
                            ><span>Kiểm tra mã địa bàn trước khi tải lên.</span>
                        </li>
                        <li>
                            <strong>Tải tệp lên</strong
                            ><span
                                >Hệ thống xử lý và lưu lại kết quả nhập.</span
                            >
                        </li>
                    </ol>
                </aside>
                <p
                    v-if="!permissions.includes('create-imports')"
                    class="empty-state"
                >
                    Tài khoản chưa được cấp quyền nhập dữ liệu.
                </p>
            </div>

            <section
                v-if="
                    importView === 'history' &&
                    permissions.includes('view-imports')
                "
                class="excel-import-panel excel-import-history"
            >
                <p
                    v-if="busy && importHistory.length === 0"
                    class="excel-history-empty"
                >
                    Đang tải lịch sử tệp…
                </p>
                <p
                    v-else-if="!busy && !error && importHistory.length === 0"
                    class="excel-history-empty"
                >
                    Chưa có tệp nào được nhập.
                </p>
                <table v-if="importHistory.length" class="excel-history-table">
                    <thead>
                        <tr>
                            <th>Tệp Excel</th>
                            <th>Thời gian</th>
                            <th>Người thực hiện</th>
                            <th>Kết quả xử lý</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in importHistory"
                            :key="String(item.id)"
                        >
                            <td data-label="Tệp Excel">
                                <div class="excel-history-file-cell">
                                    <strong :title="String(item.file_name)">
                                        {{
                                            displayImportFileName(
                                                item.file_name,
                                            )
                                        }}
                                    </strong>
                                </div>
                            </td>
                            <td data-label="Thời gian">
                                <div class="excel-history-meta-cell">
                                    <strong>{{
                                        formatImportDate(item.created_at)
                                    }}</strong>
                                </div>
                            </td>
                            <td data-label="Người thực hiện">
                                <div class="excel-history-meta-cell">
                                    <strong>{{
                                        item.uploaded_by_name ??
                                        "Không rõ người dùng"
                                    }}</strong>
                                </div>
                            </td>
                            <td data-label="Kết quả xử lý">
                                <div class="excel-history-result-cell">
                                    <span
                                        class="excel-history-status"
                                        :class="`excel-history-status-${String(item.status).toLowerCase()}`"
                                        >{{
                                            importStatusLabel(item.status)
                                        }}</span
                                    >
                                    <span class="excel-history-stat-success">
                                        {{
                                            formatImportRows(item.success_rows)
                                        }}
                                        thành công
                                    </span>
                                    <span
                                        v-if="Number(item.error_rows ?? 0) > 0"
                                        class="excel-history-stat-error"
                                    >
                                        {{ formatImportRows(item.error_rows) }}
                                        lỗi
                                    </span>
                                </div>
                            </td>
                            <td data-label="Thao tác">
                                <div class="excel-history-actions">
                                    <a
                                        v-if="
                                            item.status === 'PARTIAL' ||
                                            item.status === 'FAILED'
                                        "
                                        class="excel-history-action excel-history-action-error"
                                        :href="`/api/v1/household-imports/${item.id}/error-file`"
                                        title="Tải tệp lỗi"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M10 2.5v9m0 0 3.5-3.5M10 11.5 6.5 8M3.5 13.5v3h13v-3"
                                            />
                                        </svg>
                                        <span>Tải tệp lỗi</span>
                                    </a>
                                    <button
                                        v-if="
                                            permissions.includes(
                                                'delete-imports',
                                            ) &&
                                            !['PENDING', 'PROCESSING'].includes(
                                                String(item.status),
                                            )
                                        "
                                        class="excel-history-action excel-history-action-delete"
                                        type="button"
                                        :disabled="busy"
                                        title="Xóa lịch sử"
                                        @click="remove(item)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M3.5 5.5h13M8 5.5V3.7h4v1.8m3.3 0-.8 10.8H5.5L4.7 5.5m3.1 3v5m4.4-5v5"
                                            />
                                        </svg>
                                        <span>Xóa lịch sử</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="excel-history-pagination">
                    <span class="excel-history-page-summary">
                        {{
                            importHistory.length
                                ? `${(currentPage - 1) * 4 + 1}–${Math.min(currentPage * 4, totalRecords)} / ${formatImportRows(totalRecords)} tệp`
                                : "0 tệp"
                        }}
                    </span>
                    <template v-if="lastPage > 1">
                        <button
                            class="secondary-button"
                            type="button"
                            :disabled="currentPage <= 1"
                            @click="changePage(-1)"
                        >
                            Trước
                        </button>
                        <span class="muted"
                            >Trang {{ currentPage }} / {{ lastPage }}</span
                        >
                        <button
                            class="secondary-button"
                            type="button"
                            :disabled="currentPage >= lastPage"
                            @click="changePage(1)"
                        >
                            Sau
                        </button>
                    </template>
                </div>
            </section>
            <p
                v-if="
                    importView === 'history' &&
                    !permissions.includes('view-imports')
                "
                class="empty-state"
            >
                Tài khoản chưa được cấp quyền xem lịch sử tệp.
            </p>
        </section>

        <section
            v-if="moduleKey === 'trash'"
            class="workspace-table-wrap trash-management-panel"
        >
            <table
                v-if="rows.length"
                class="workspace-table trash-management-table"
            >
                <thead>
                    <tr>
                        <th
                            v-if="canUpdate || canDelete"
                            class="trash-checkbox-heading"
                        >
                            <input
                                type="checkbox"
                                aria-label="Chọn tất cả mục trên trang"
                                :checked="allTrashRowsSelected"
                                @change="
                                    toggleAllTrashRows(
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                        </th>
                        <th>Loại dữ liệu</th>
                        <th>Mã phiếu</th>
                        <th>Chủ hộ</th>
                        <th v-if="trashFilterType === 'person'">Nhân khẩu</th>
                        <th>Ngày xóa</th>
                        <th
                            v-if="
                                canUpdate ||
                                canDelete ||
                                permissions.includes('view-trash')
                            "
                            class="trash-actions-heading"
                        >
                            Thao tác
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="`${row.item_type}-${row.id}`">
                        <td
                            v-if="canUpdate || canDelete"
                            class="trash-checkbox-cell"
                        >
                            <input
                                type="checkbox"
                                :aria-label="`Chọn ${pathValue(row, 'record.person_name') || pathValue(row, 'record.household_code') || 'mục đã xóa'}`"
                                :checked="
                                    selectedTrashKeys.includes(trashKey(row))
                                "
                                @change="
                                    toggleTrashSelection(
                                        row,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                        </td>
                        <td>
                            <span
                                class="trash-type-badge"
                                :class="{
                                    'trash-type-person':
                                        row.item_type === 'person',
                                }"
                            >
                                {{
                                    row.item_type === "person"
                                        ? "Nhân khẩu"
                                        : "Phiếu điều tra"
                                }}
                            </span>
                        </td>
                        <td>
                            {{ pathValue(row, "record.household_code") || "—" }}
                        </td>
                        <td>{{ pathValue(row, "record.head_name") || "—" }}</td>
                        <td v-if="trashFilterType === 'person'">
                            {{ pathValue(row, "record.person_name") || "—" }}
                        </td>
                        <td class="trash-deleted-at">
                            {{ formatTrashDeletedAt(row.deleted_at) }}
                        </td>
                        <td
                            v-if="
                                canUpdate ||
                                canDelete ||
                                permissions.includes('view-trash')
                            "
                        >
                            <div class="trash-row-actions">
                                <button
                                    v-if="
                                        ['household', 'person'].includes(
                                            String(row.item_type),
                                        )
                                    "
                                    class="trash-icon-button trash-view-button"
                                    type="button"
                                    :title="
                                        row.item_type === 'household'
                                            ? 'Xem chi tiết phiếu đã xóa'
                                            : 'Xem chi tiết nhân khẩu đã xóa'
                                    "
                                    :aria-label="
                                        row.item_type === 'household'
                                            ? 'Xem chi tiết phiếu đã xóa'
                                            : 'Xem chi tiết nhân khẩu đã xóa'
                                    "
                                    @click="
                                        row.item_type === 'household'
                                            ? openTrashedHousehold(row)
                                            : openTrashedPerson(row)
                                    "
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path
                                            d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"
                                        />
                                        <circle cx="12" cy="12" r="2.5" />
                                    </svg>
                                </button>
                                <button
                                    v-if="canUpdate"
                                    class="trash-icon-button trash-restore-button"
                                    type="button"
                                    title="Khôi phục"
                                    aria-label="Khôi phục"
                                    @click="requestTrashRestore(row)"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path
                                            d="M4 12a8 8 0 1 0 2.3-5.7L4 8.6"
                                        />
                                        <path d="M4 4v4.6h4.6" />
                                    </svg>
                                </button>
                                <button
                                    v-if="canDelete"
                                    class="trash-icon-button trash-delete-button"
                                    type="button"
                                    title="Xóa vĩnh viễn"
                                    aria-label="Xóa vĩnh viễn"
                                    @click="requestTrashDelete(row)"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path
                                            d="M5 7h14M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-else class="empty-state trash-empty-state">
                <span aria-hidden="true"></span>
                <strong>Thùng rác trống</strong>
                <p>Không có dữ liệu đã xóa để hiển thị.</p>
            </div>
        </section>

        <section
            v-if="
                moduleKey !== 'trash' &&
                rows.length &&
                [
                    'households',
                    'schools',
                    'villages',
                    'roles',
                    'users',
                ].includes(moduleKey)
            "
            class="workspace-table-wrap"
        >
            <table
                class="workspace-table"
                :class="{
                    'account-management-table': moduleKey === 'users',
                    'role-management-table': moduleKey === 'roles',
                    'village-management-table': moduleKey === 'villages',
                    'school-management-table': moduleKey === 'schools',
                    'household-management-table': moduleKey === 'households',
                }"
            >
                <thead>
                    <tr>
                        <th
                            v-if="moduleKey === 'households' && canDelete"
                            class="household-selection-cell"
                        >
                            <input
                                type="checkbox"
                                aria-label="Chọn tất cả phiếu trên trang"
                                :checked="allHouseholdRowsSelected"
                                :indeterminate="someHouseholdRowsSelected"
                                @change="
                                    toggleAllHouseholdRows(
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                        </th>
                        <th
                            v-for="column in info.columns"
                            :key="column"
                            :class="{
                                'table-numeric-heading': [
                                    'phone',
                                    'members_count',
                                    'users_count',
                                ].includes(column),
                                'table-date-heading': [
                                    'date_of_birth',
                                    'dob',
                                    'created_at',
                                    'updated_at',
                                ].includes(column),
                                'table-status-heading': [
                                    'is_active',
                                    'operational_status',
                                    'status',
                                ].includes(column),
                                'village-operational-status-cell':
                                    moduleKey === 'villages' &&
                                    column === 'operational_status',
                                'school-active-status-cell':
                                    moduleKey === 'schools' &&
                                    column === 'is_active',
                            }"
                        >
                            {{
                                moduleKey === "users" && column === "name"
                                    ? "Tên / Cấp quản lý"
                                    : fieldLabel(column)
                            }}
                        </th>
                        <th
                            v-if="moduleKey === 'users'"
                            class="table-status-heading"
                        >
                            Trạng thái
                        </th>
                        <th
                            :class="{
                                'account-actions-heading':
                                    moduleKey === 'users',
                            }"
                        >
                            Thao tác
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="String(row.id ?? row.code)">
                        <td
                            v-if="moduleKey === 'households' && canDelete"
                            class="household-selection-cell"
                        >
                            <input
                                type="checkbox"
                                :aria-label="`Chọn phiếu ${row.household_code}`"
                                :checked="
                                    selectedHouseholdIds.includes(
                                        Number(row.id),
                                    )
                                "
                                @change="
                                    toggleHouseholdSelection(
                                        Number(row.id),
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                        </td>
                        <td
                            v-for="column in info.columns"
                            :key="column"
                            :class="{
                                'account-numeric-cell':
                                    moduleKey === 'users' && column === 'phone',
                                'role-members-cell':
                                    moduleKey === 'roles' &&
                                    column === 'users_count',
                                'household-members-cell':
                                    moduleKey === 'households' &&
                                    column === 'members_count',
                                'village-name-cell':
                                    ['villages', 'schools'].includes(
                                        moduleKey,
                                    ) && column === 'name',
                                'village-code-cell':
                                    ['villages', 'schools'].includes(
                                        moduleKey,
                                    ) && column === 'code',
                                'village-operational-status-cell':
                                    moduleKey === 'villages' &&
                                    column === 'operational_status',
                                'school-active-status-cell':
                                    moduleKey === 'schools' &&
                                    column === 'is_active',
                            }"
                        >
                            <span
                                v-if="
                                    moduleKey === 'villages' &&
                                    column === 'operational_status'
                                "
                                class="status-pill"
                                :class="{
                                    inactive:
                                        row.operational_status !== 'active',
                                }"
                            >
                                {{
                                    row.operational_status === "active"
                                        ? "Đang hoạt động"
                                        : "Không hoạt động"
                                }}
                            </span>
                            <span
                                v-else-if="
                                    moduleKey === 'schools' &&
                                    column === 'is_active'
                                "
                                class="status-pill"
                                :class="{ inactive: !row.is_active }"
                            >
                                {{
                                    row.is_active
                                        ? "Đang hoạt động"
                                        : "Đã vô hiệu hóa"
                                }}
                            </span>
                            <div
                                v-else-if="
                                    moduleKey === 'users' && column === 'name'
                                "
                                class="account-name-cell"
                            >
                                <span class="account-name">{{
                                    pathValue(row, column)
                                }}</span>
                                <small v-if="row.scope_level">
                                    {{
                                        scopeLabels[String(row.scope_level)] ||
                                        row.scope_level
                                    }}
                                </small>
                            </div>
                            <div
                                v-else-if="
                                    moduleKey === 'schools' && column === 'name'
                                "
                                class="school-name-cell"
                            >
                                <span>{{ pathValue(row, column) }}</span>
                                <small>
                                    {{ schoolLevelSummary(row) }}
                                </small>
                            </div>
                            <div
                                v-else-if="
                                    moduleKey === 'users' &&
                                    column === 'role_name'
                                "
                                class="account-role-cell"
                            >
                                <span>{{ pathValue(row, column) }}</span>
                                <small
                                    v-if="
                                        row.village_names ||
                                        (row.scope_level === 'commune'
                                            ? row.commune_name
                                            : row.scope_level === 'province' &&
                                              row.province_name)
                                    "
                                >
                                    {{
                                        row.village_names
                                            ? `Thôn: ${row.village_names}`
                                            : row.scope_level === "commune"
                                              ? row.commune_name
                                              : row.province_name
                                    }}
                                </small>
                            </div>
                            <span
                                v-else-if="
                                    moduleKey === 'roles' &&
                                    column === 'users_count'
                                "
                                :title="
                                    row.has_multiple_members
                                        ? 'Có thêm thành viên ngoài phạm vi đang hiển thị.'
                                        : undefined
                                "
                            >
                                {{
                                    row.total_users_count ??
                                    pathValue(row, column)
                                }}
                            </span>
                            <template v-else>
                                {{ pathValue(row, column) }}
                            </template>
                        </td>
                        <td
                            v-if="moduleKey === 'users'"
                            class="account-status-cell"
                        >
                            <span
                                class="status-pill"
                                :class="{ inactive: !row.is_active }"
                            >
                                {{
                                    row.is_active
                                        ? "Đang hoạt động"
                                        : "Đã vô hiệu hóa"
                                }}
                            </span>
                        </td>
                        <td class="table-actions">
                            <div
                                v-if="moduleKey === 'users'"
                                class="account-status-actions"
                            >
                                <div class="account-action-controls">
                                    <button
                                        v-if="row.can_view_details"
                                        class="secondary-button account-action-button account-icon-action"
                                        type="button"
                                        :aria-label="`Xem chi tiết tài khoản ${row.name}`"
                                        title="Xem chi tiết"
                                        @click="openUserDetail(row)"
                                    >
                                        <svg
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"
                                            />
                                            <circle cx="12" cy="12" r="2.7" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="row.can_edit_configuration"
                                        class="secondary-button account-action-button account-icon-action user-edit-account-button"
                                        type="button"
                                        :aria-label="`Sửa tài khoản ${row.name}`"
                                        title="Sửa tài khoản"
                                        @click="beginEdit(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"
                                            />
                                            <path d="m12.5 4.5 3 3" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="row.can_change_account_status"
                                        class="secondary-button account-action-button account-icon-action"
                                        :class="
                                            row.is_active
                                                ? 'user-deactivate-button'
                                                : 'user-activate-button'
                                        "
                                        type="button"
                                        :aria-label="
                                            row.is_active
                                                ? `Vô hiệu hóa ${row.name}`
                                                : `Kích hoạt ${row.name}`
                                        "
                                        :title="
                                            row.is_active
                                                ? 'Vô hiệu hóa'
                                                : 'Kích hoạt'
                                        "
                                        @click="
                                            openAccountAction(row, 'status')
                                        "
                                    >
                                        <svg
                                            v-if="row.is_active"
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                        >
                                            <rect
                                                x="4"
                                                y="10"
                                                width="16"
                                                height="11"
                                                rx="2"
                                            />
                                            <path
                                                d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"
                                            />
                                        </svg>
                                        <svg
                                            v-else
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                        >
                                            <rect
                                                x="4"
                                                y="10"
                                                width="16"
                                                height="11"
                                                rx="2"
                                            />
                                            <path
                                                d="M8 10V7a4 4 0 0 1 7.5-2m-3.5 9v3"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="row.can_reset_account_password"
                                        class="secondary-button account-action-button account-icon-action user-password-reset-button"
                                        type="button"
                                        :aria-label="`Đặt lại mật khẩu ${row.name}`"
                                        title="Đặt lại mật khẩu"
                                        @click="
                                            openAccountAction(row, 'password')
                                        "
                                    >
                                        <svg
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                        >
                                            <circle cx="8" cy="15" r="4" />
                                            <path
                                                d="m11 12 8-8 3 3-3 3m-4 1 2 2"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <template v-else>
                                <div
                                    v-if="moduleKey === 'households'"
                                    class="role-action-buttons village-action-buttons household-row-actions"
                                >
                                    <button
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Xem chi tiết phiếu ${row.household_code}`"
                                        title="Xem chi tiết"
                                        @click="openHousehold(row)"
                                    >
                                        <svg
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"
                                            />
                                            <circle cx="12" cy="12" r="2.7" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="canUpdate"
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa phiếu ${row.household_code}`"
                                        title="Sửa phiếu"
                                        @click="beginEdit(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"
                                            />
                                            <path d="m12.5 4.5 3 3" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        class="role-icon-button role-delete-button"
                                        type="button"
                                        :aria-label="`Xóa phiếu ${row.household_code}`"
                                        title="Xóa phiếu"
                                        @click="remove(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"
                                            />
                                        </svg>
                                    </button>
                                </div>
                                <div
                                    v-if="moduleKey === 'villages'"
                                    class="role-action-buttons village-action-buttons"
                                >
                                    <button
                                        v-if="
                                            canUpdate &&
                                            !row.merged_into_code
                                        "
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa thôn / xóm ${row.name}`"
                                        title="Sửa"
                                        @click="beginEdit(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"
                                            />
                                            <path d="m12.5 4.5 3 3" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        class="role-icon-button role-delete-button"
                                        type="button"
                                        :disabled="row.operational_status === 'active'"
                                        :aria-label="`Xóa thôn / xóm ${row.name}`"
                                        :title="
                                            row.operational_status === 'active'
                                                ? 'Không thể xóa thôn đang hoạt động. Hãy chuyển sang không hoạt động trước.'
                                                : 'Xóa'
                                        "
                                        @click="remove(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"
                                            />
                                        </svg>
                                    </button>
                                </div>
                                <div
                                    v-if="moduleKey === 'schools'"
                                    class="role-action-buttons village-action-buttons"
                                >
                                    <button
                                        v-if="canUpdate"
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa trường ${row.name}`"
                                        title="Sửa"
                                        @click="beginEdit(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"
                                            />
                                            <path d="m12.5 4.5 3 3" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        class="role-icon-button role-delete-button"
                                        type="button"
                                        :disabled="Boolean(row.is_active)"
                                        :aria-label="`Xóa trường ${row.name}`"
                                        :title="
                                            row.is_active
                                                ? 'Không thể xóa trường đang hoạt động. Hãy chuyển sang không hoạt động trước.'
                                                : 'Xóa'
                                        "
                                        @click="remove(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"
                                            />
                                        </svg>
                                    </button>
                                </div>
                                <button
                                    v-if="moduleKey === 'roles'"
                                    class="secondary-button role-permissions-button"
                                    type="button"
                                    :aria-label="`Xem quyền hạn của nhóm ${row.name}`"
                                    title="Xem quyền hạn"
                                    @click="openRolePermissionDetails(row)"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path
                                            d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"
                                        />
                                        <circle cx="12" cy="12" r="2.7" />
                                    </svg>
                                </button>
                                <div
                                    v-if="
                                        moduleKey === 'roles' && row.can_manage
                                    "
                                    class="role-action-buttons"
                                >
                                    <button
                                        v-if="canUpdate"
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa nhóm ${row.name}`"
                                        title="Sửa nhóm"
                                        @click="beginEdit(row)"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"
                                            />
                                            <path d="m12.5 4.5 3 3" />
                                        </svg>
                                    </button>
                                    <span
                                        v-if="canDelete"
                                        class="role-delete-control"
                                        :data-tooltip="
                                            row.has_members
                                                ? 'Không thể xóa vì nhóm vẫn còn thành viên.'
                                                : undefined
                                        "
                                        :title="
                                            row.has_members
                                                ? 'Không thể xóa vì nhóm vẫn còn thành viên.'
                                                : undefined
                                        "
                                        :aria-label="
                                            row.has_members
                                                ? 'Không thể xóa vì nhóm vẫn còn thành viên.'
                                                : undefined
                                        "
                                        :tabindex="
                                            row.has_members ? 0 : undefined
                                        "
                                    >
                                        <button
                                            class="role-icon-button role-delete-button"
                                            type="button"
                                            :disabled="Boolean(row.has_members)"
                                            :aria-label="
                                                row.has_members
                                                    ? `Không thể xóa nhóm ${row.name} vì còn thành viên`
                                                    : `Xóa nhóm ${row.name}`
                                            "
                                            :title="
                                                row.has_members
                                                    ? undefined
                                                    : 'Xóa nhóm'
                                            "
                                            @click="remove(row)"
                                        >
                                            <svg
                                                viewBox="0 0 20 20"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"
                                                />
                                            </svg>
                                        </button>
                                    </span>
                                </div>
                                <button
                                    v-if="
                                        ![
                                            'roles',
                                            'villages',
                                            'schools',
                                            'households',
                                        ].includes(moduleKey) && canUpdate
                                    "
                                    class="secondary-button"
                                    type="button"
                                    @click="beginEdit(row)"
                                >
                                    Sửa cấu hình
                                </button>
                                <button
                                    v-if="
                                        ![
                                            'roles',
                                            'villages',
                                            'schools',
                                            'households',
                                        ].includes(moduleKey) && canDelete
                                    "
                                    class="secondary-button danger-button"
                                    type="button"
                                    @click="remove(row)"
                                >
                                    Xóa
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p
            v-if="
                ['villages', 'schools'].includes(moduleKey) &&
                !busy &&
                rows.length === 0
            "
            class="village-empty-state"
        >
            {{
                moduleKey === "villages"
                    ? totalRecords
                        ? "Không tìm thấy thôn / xóm phù hợp với bộ lọc."
                        : "Chưa có dữ liệu thôn / xóm tại địa bàn này."
                    : totalRecords
                      ? "Không tìm thấy trường học phù hợp với bộ lọc."
                      : "Chưa có dữ liệu trường học tại địa bàn này."
            }}
        </p>
        <p
            v-if="
                moduleKey === 'households' &&
                !householdDetail &&
                !busy &&
                rows.length === 0
            "
            class="village-empty-state"
        >
            {{
                totalRecords
                    ? "Không tìm thấy phiếu điều tra phù hợp với bộ lọc."
                    : "Chưa có phiếu điều tra tại địa bàn này."
            }}
        </p>
        <p
            v-if="moduleKey === 'roles' && !busy && rows.length === 0"
            class="village-empty-state"
        >
            {{
                roleFilterQuery.trim()
                    ? "Không tìm thấy nhóm người dùng phù hợp."
                    : "Chưa có nhóm người dùng."
            }}
        </p>
        <div v-if="moduleKey === 'users'" class="user-pagination-footer">
            <div class="user-pagination-footer-info">
                <label class="page-size-control">
                    <span>Mỗi trang</span>
                    <select
                        v-model.number="userPageSize"
                        :disabled="busy"
                        @change="changeUserPageSize"
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </label>
                <span class="record-count"
                    >{{ rows.length }} / {{ totalRecords }} bản ghi</span
                >
            </div>
            <div
                class="pagination-actions compact-pagination"
                aria-label="Phân trang danh sách"
            >
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage <= 1 || busy"
                    @click="changePage(-1)"
                >
                    Trước
                </button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select
                        v-model.number="currentPage"
                        aria-label="Chọn trang tài khoản"
                        :disabled="busy || lastPage <= 1"
                        @change="load"
                    >
                        <option
                            v-for="pageNumber in lastPage"
                            :key="pageNumber"
                            :value="pageNumber"
                        >
                            {{ pageNumber }}
                        </option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage >= lastPage || busy"
                    @click="changePage(1)"
                >
                    Sau
                </button>
            </div>
        </div>
        <div
            v-if="moduleKey === 'roles'"
            class="user-pagination-footer role-pagination-footer"
        >
            <div class="user-pagination-footer-info">
                <label class="page-size-control">
                    <span>Mỗi trang</span>
                    <select
                        v-model.number="rolePageSize"
                        :disabled="busy"
                        @change="changeRolePageSize"
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </label>
                <span class="record-count"
                    >{{ rows.length }} / {{ totalRecords }} bản ghi</span
                >
            </div>
            <div
                class="pagination-actions compact-pagination"
                aria-label="Phân trang danh sách nhóm người dùng"
            >
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage <= 1 || busy"
                    @click="changePage(-1)"
                >
                    Trước
                </button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select
                        v-model.number="currentPage"
                        aria-label="Chọn trang nhóm người dùng"
                        :disabled="busy || lastPage <= 1"
                        @change="load"
                    >
                        <option
                            v-for="pageNumber in lastPage"
                            :key="pageNumber"
                            :value="pageNumber"
                        >
                            {{ pageNumber }}
                        </option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage >= lastPage || busy"
                    @click="changePage(1)"
                >
                    Sau
                </button>
            </div>
        </div>
        <div
            v-if="moduleKey === 'trash'"
            class="user-pagination-footer trash-pagination-footer"
        >
            <div class="village-pagination-summary">
                <label class="page-size-control">
                    <span>Mỗi trang</span>
                    <select
                        v-model.number="trashPageSize"
                        :disabled="busy"
                        @change="
                            currentPage = 1;
                            load();
                        "
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                    </select>
                </label>
                <span class="record-count">
                    {{ rows.length }} / {{ totalRecords }} bản ghi
                </span>
            </div>
            <div
                class="pagination-actions compact-pagination"
                aria-label="Phân trang thùng rác"
            >
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage <= 1 || busy"
                    @click="changePage(-1)"
                >
                    Trước
                </button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select
                        v-model.number="currentPage"
                        aria-label="Chọn trang thùng rác"
                        :disabled="busy || lastPage <= 1"
                        @change="load"
                    >
                        <option
                            v-for="pageNumber in lastPage"
                            :key="pageNumber"
                            :value="pageNumber"
                        >
                            {{ pageNumber }}
                        </option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage >= lastPage || busy"
                    @click="changePage(1)"
                >
                    Sau
                </button>
            </div>
        </div>
        <div
            v-if="['villages', 'schools'].includes(moduleKey)"
            class="user-pagination-footer village-pagination-footer"
        >
            <div class="village-pagination-summary">
                <label class="page-size-control">
                    <span>Mỗi trang</span>
                    <select
                        v-if="moduleKey === 'villages'"
                        v-model.number="villagePageSize"
                        :disabled="busy"
                        @change="changeVillagePageSize"
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                    </select>
                    <select
                        v-else
                        v-model.number="schoolPageSize"
                        :disabled="busy"
                        @change="changeSchoolPageSize"
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                    </select>
                </label>
                <span class="record-count">
                    {{ rows.length }} / {{ totalRecords }} bản ghi
                </span>
            </div>
            <div
                class="pagination-actions compact-pagination"
                :aria-label="
                    moduleKey === 'villages'
                        ? 'Phân trang danh sách thôn / xóm'
                        : 'Phân trang danh sách trường học'
                "
            >
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage <= 1 || busy"
                    @click="changePage(-1)"
                >
                    Trước
                </button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select
                        v-model.number="currentPage"
                        :aria-label="
                            moduleKey === 'villages'
                                ? 'Chọn trang thôn / xóm'
                                : 'Chọn trang trường học'
                        "
                        :disabled="busy || lastPage <= 1"
                        @change="load"
                    >
                        <option
                            v-for="pageNumber in lastPage"
                            :key="pageNumber"
                            :value="pageNumber"
                        >
                            {{ pageNumber }}
                        </option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage >= lastPage || busy"
                    @click="changePage(1)"
                >
                    Sau
                </button>
            </div>
        </div>
        <div
            v-if="moduleKey === 'households'"
            class="user-pagination-footer village-pagination-footer household-pagination-footer"
        >
            <div class="village-pagination-summary">
                <label class="page-size-control">
                    <span>Mỗi trang</span>
                    <select
                        v-model.number="householdPageSize"
                        :disabled="busy"
                        @change="changeHouseholdPageSize"
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </label>
                <span class="record-count">
                    {{ rows.length }} / {{ totalRecords }} bản ghi
                </span>
            </div>
            <div
                class="pagination-actions compact-pagination"
                aria-label="Phân trang danh sách phiếu điều tra"
            >
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage <= 1 || busy"
                    @click="changePage(-1)"
                >
                    Trước
                </button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select
                        v-model.number="currentPage"
                        aria-label="Chọn trang phiếu điều tra"
                        :disabled="busy || lastPage <= 1"
                        @change="load"
                    >
                        <option
                            v-for="pageNumber in lastPage"
                            :key="pageNumber"
                            :value="pageNumber"
                        >
                            {{ pageNumber }}
                        </option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="currentPage >= lastPage || busy"
                    @click="changePage(1)"
                >
                    Sau
                </button>
            </div>
        </div>
        <div
            v-if="
                lastPage > 1 &&
                !isCompactManagement &&
                !['households', 'villages', 'schools'].includes(moduleKey) &&
                ['roles', 'users', 'trash'].includes(moduleKey)
            "
            class="pagination-actions workspace-pagination"
        >
            <button
                class="secondary-button"
                type="button"
                :disabled="currentPage <= 1"
                @click="changePage(-1)"
            >
                Trước
            </button>
            <span class="muted">Trang {{ currentPage }} / {{ lastPage }}</span>
            <button
                class="secondary-button"
                type="button"
                :disabled="currentPage >= lastPage"
                @click="changePage(1)"
            >
                Sau
            </button>
        </div>
        <div
            v-if="selectedRolePermissions"
            class="workspace-modal-backdrop role-permissions-backdrop"
            role="presentation"
            @click.self="selectedRolePermissions = null"
            @keydown.esc="selectedRolePermissions = null"
        >
            <section
                class="confirm-dialog role-permissions-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="role-permissions-title"
                tabindex="-1"
            >
                <header class="role-permissions-dialog-header">
                    <div>
                        <span class="role-permissions-dialog-eyebrow"
                            >CHI TIẾT PHÂN QUYỀN</span
                        >
                        <h3 id="role-permissions-title">
                            {{ selectedRolePermissions.name }}
                        </h3>
                        <p>
                            {{
                                scopeLabels[
                                    String(selectedRolePermissions.scope_level)
                                ] ?? "Chưa xác định cấp quản lý"
                            }}
                        </p>
                    </div>
                    <button
                        v-if="!isHouseholdWizardMemberPage"
                        class="modal-icon-button"
                        type="button"
                        aria-label="Đóng chi tiết quyền hạn"
                        autofocus
                        @click="selectedRolePermissions = null"
                    >
                        ×
                    </button>
                </header>
                <div class="role-permissions-dialog-content">
                    <table
                        v-if="
                            permissionGroupsForDisplay(
                                selectedRolePermissions.permissions,
                            ).length > 0
                        "
                        class="role-permissions-table"
                    >
                        <thead>
                            <tr>
                                <th scope="col">Nhóm quyền</th>
                                <th scope="col">Quyền được cấp</th>
                            </tr>
                        </thead>
                        <tbody
                            v-for="group in permissionGroupsForDisplay(
                                selectedRolePermissions.permissions,
                            )"
                            :key="group.key"
                        >
                            <tr
                                v-for="(permission, index) in group.options"
                                :key="`${group.key}-${permissionName(permission)}`"
                            >
                                <th
                                    v-if="index === 0"
                                    scope="rowgroup"
                                    :rowspan="group.options.length"
                                >
                                    {{ group.label }}
                                </th>
                                <td>
                                    {{
                                        typeof permission === "object" &&
                                        permission !== null &&
                                        "label" in permission
                                            ? String(permission.label)
                                            : permissionLabel(
                                                  permissionName(permission),
                                              )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="
                            permissionGroupsForDisplay(
                                selectedRolePermissions.permissions,
                            ).length === 0
                        "
                        class="role-permissions-empty"
                    >
                        Nhóm này chưa được cấp quyền.
                    </p>
                </div>
            </section>
        </div>
        <div
            v-if="
                householdDetail &&
                (moduleKey === 'households' ||
                    (moduleKey === 'trash' && householdDetailFromTrash))
            "
            class="workspace-modal-backdrop household-detail-backdrop"
            role="presentation"
            @keydown.esc="closeHouseholdDetail"
        >
            <section
                class="household-detail"
                role="dialog"
                aria-modal="true"
                aria-labelledby="household-detail-title"
            >
                <header class="household-detail-header">
                    <div>
                        <h2 id="household-detail-title">
                            {{
                                householdDetailFromTrash
                                    ? "Chi tiết phiếu đã xóa -"
                                    : "Chi tiết Phiếu điều tra -"
                            }}
                            {{ householdDetail.household_code }}
                        </h2>
                    </div>
                    <div class="household-detail-actions">
                        <button
                            class="household-detail-icon-button"
                            type="button"
                            aria-label="Đóng chi tiết phiếu"
                            title="Đóng"
                            @click="closeHouseholdDetail"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="m6 6 12 12M18 6 6 18" />
                            </svg>
                        </button>
                        <button
                            class="household-detail-icon-button"
                            type="button"
                            aria-label="In phiếu điều tra"
                            title="In phiếu điều tra (PDF)"
                            @click="printHousehold"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path
                                    d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5h-2"
                                />
                                <path d="M7 14h10v7H7zM17 11h.01" />
                            </svg>
                        </button>
                        <button
                            v-if="
                                !householdDetailFromTrash &&
                                permissions.includes('update-households')
                            "
                            class="household-detail-icon-button"
                            type="button"
                            aria-label="Chỉnh sửa phiếu"
                            title="Chỉnh sửa phiếu"
                            @click="editHousehold"
                        >
                            <svg viewBox="0 0 20 20" aria-hidden="true">
                                <path
                                    d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"
                                />
                                <path d="m12.5 4.5 3 3" />
                            </svg>
                        </button>
                        <button
                            v-if="
                                !householdDetailReadOnly &&
                                permissions.includes('create-households')
                            "
                            class="household-detail-icon-button"
                            type="button"
                            aria-label="Thêm nhân khẩu"
                            title="Thêm nhân khẩu"
                            @click="
                                beginMember(
                                    householdDetail.has_household_head !== true,
                                )
                            "
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                        </button>
                    </div>
                </header>

                <p
                    v-if="householdLoading || householdDetailPageLoading"
                    class="household-detail-loading"
                    role="status"
                >
                    Đang tải thông tin nhân khẩu…
                </p>
                <p
                    v-if="error && !memberEditorOpen"
                    class="notice household-detail-notice"
                    role="alert"
                >
                    {{ error }}
                </p>

                <section class="household-view-form">
                    <h4 class="household-form-section-heading">
                        Thông tin đối tượng và chủ hộ
                    </h4>
                    <div
                        class="household-view-field-grid household-view-household-fields"
                    >
                        <div class="household-view-field">
                            <span>Mã phiếu</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.household_code,
                                )
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Năm học</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.school_year,
                                )
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Họ chủ hộ</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.head_last_name,
                                )
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Tên chủ hộ</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.head_first_name,
                                )
                            }}</strong>
                        </div>
                    </div>
                    <h4 class="household-form-section-heading">
                        Thông tin nơi đang ở
                    </h4>
                    <div
                        class="household-view-field-grid household-view-location-fields"
                    >
                        <div class="household-view-field">
                            <span>Tỉnh / thành phố</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.province_name ??
                                        pathValue(
                                            householdDetail,
                                            "province.name",
                                        ),
                                )
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Xã / phường</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.commune_name ??
                                        pathValue(
                                            householdDetail,
                                            "commune.name",
                                        ),
                                )
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Thôn / xóm</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.village_name ??
                                        pathValue(
                                            householdDetail,
                                            "village.name",
                                        ),
                                )
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Địa chỉ</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.address,
                                )
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Loại cư trú</span>
                            <strong>{{
                                householdDetail.residence_type === "TAM_TRU"
                                    ? "Tạm trú"
                                    : householdDetail.residence_type === "KHAC"
                                      ? "Khác"
                                      : householdDetail.residence_type ===
                                          "THUONG_TRU"
                                        ? "Thường trú"
                                        : "-"
                            }}</strong>
                        </div>
                        <div class="household-view-field">
                            <span>Tình trạng cư trú</span>
                            <strong>{{
                                displayHouseholdViewValue(
                                    householdDetail.residence_status,
                                )
                            }}</strong>
                        </div>
                    </div>
                </section>

                <section
                    v-if="householdDetail"
                    class="household-overview-card household-readonly-form"
                >
                    <div class="household-section-title">
                        <div>
                            <h3>Thông tin hộ gia đình</h3>
                            <p>
                                Thông tin chung theo biểu mẫu chỉnh sửa phiếu.
                            </p>
                        </div>
                    </div>
                    <div class="household-readonly-fields">
                        <div class="household-member-readonly-field">
                            <span>Mã phiếu</span>
                            <strong>{{
                                displayFormValue(householdDetail.household_code)
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Năm học</span>
                            <strong>{{
                                displayFormValue(householdDetail.school_year)
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Họ và tên đệm chủ hộ</span>
                            <strong>{{
                                displayFormValue(householdDetail.head_last_name)
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Tên chủ hộ</span>
                            <strong>{{
                                displayFormValue(
                                    householdDetail.head_first_name,
                                )
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Tỉnh / thành phố</span>
                            <strong>{{
                                displayFormValue(
                                    householdDetail.province_name ??
                                        pathValue(
                                            householdDetail,
                                            "province.name",
                                        ),
                                )
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Xã / phường</span>
                            <strong>{{
                                displayFormValue(
                                    householdDetail.commune_name ??
                                        pathValue(
                                            householdDetail,
                                            "commune.name",
                                        ),
                                )
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Thôn / xóm</span>
                            <strong>{{
                                displayFormValue(
                                    householdDetail.village_name ??
                                        pathValue(
                                            householdDetail,
                                            "village.name",
                                        ),
                                )
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Loại cư trú</span>
                            <strong>{{
                                householdDetail.residence_type === "TAM_TRU"
                                    ? "Tạm trú"
                                    : householdDetail.residence_type === "KHAC"
                                      ? "Khác"
                                      : householdDetail.residence_type ===
                                          "THUONG_TRU"
                                        ? "Thường trú"
                                        : "Chưa cập nhật"
                            }}</strong>
                        </div>
                        <div
                            class="household-member-readonly-field household-member-wide-field"
                        >
                            <span>Địa chỉ</span>
                            <strong>{{
                                displayFormValue(householdDetail.address)
                            }}</strong>
                        </div>
                        <div
                            class="household-member-readonly-field household-member-wide-field"
                        >
                            <span>Tình trạng cư trú</span>
                            <strong>{{
                                displayFormValue(
                                    householdDetail.residence_status,
                                )
                            }}</strong>
                        </div>
                    </div>
                </section>

                <section
                    class="household-members-section"
                    aria-labelledby="household-members-title"
                >
                    <div class="household-edit-members household-view-members">
                        <div class="household-edit-members-heading">
                            <div>
                                <h4 id="household-members-title">
                                    Danh sách nhân khẩu
                                </h4>
                            </div>
                            <span class="household-view-member-count">
                                {{ householdDetailTotal }} nhân khẩu
                            </span>
                        </div>
                        <div class="household-edit-members-table-wrap">
                            <table
                                v-member-column-resize
                                class="household-edit-members-table household-view-member-table"
                            >
                                <colgroup>
                                    <col class="member-col-stt" />
                                    <col class="member-col-name" />
                                    <col class="member-col-name" />
                                    <col class="member-col-short" />
                                    <col class="member-col-date" />
                                    <col class="member-col-gender" />
                                    <col class="member-col-select" />
                                    <col class="member-col-medium" />
                                    <col class="member-col-medium" />
                                    <col class="member-col-medium" />
                                    <col class="member-col-phone" />
                                    <col class="member-col-medium" />
                                    <col class="member-col-school-year" />
                                    <col
                                        class="member-col-small"
                                        v-for="column in 28"
                                        :key="`view-member-col-${column}`"
                                    />
                                    <col
                                        v-if="
                                            !householdDetailReadOnly &&
                                            (permissions.includes(
                                                'update-households',
                                            ) ||
                                                permissions.includes(
                                                    'delete-households',
                                                ))
                                        "
                                        class="member-col-actions"
                                    />
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>STT</th>
                                        <th
                                            v-for="column in householdViewMemberColumns"
                                            :key="column.key"
                                            :class="{
                                                'is-required':
                                                    'required' in column &&
                                                    column.required,
                                            }"
                                        >
                                            {{ column.label }}
                                        </th>
                                        <th
                                            v-if="
                                                !householdDetailReadOnly &&
                                                (permissions.includes(
                                                    'update-households',
                                                ) ||
                                                    permissions.includes(
                                                        'delete-households',
                                                    ))
                                            "
                                        >
                                            Thao tác
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(
                                            person, index
                                        ) in householdPersons"
                                        :key="`readonly-${String(person.id ?? index)}`"
                                    >
                                        <td>
                                            {{
                                                (householdDetailPage - 1) *
                                                    householdDetailPageSize +
                                                index +
                                                1
                                            }}
                                        </td>
                                        <td
                                            v-for="column in householdViewMemberColumns"
                                            :key="column.key"
                                            :title="
                                                householdViewMemberDisplayValue(
                                                    person,
                                                    column.key,
                                                )
                                            "
                                            :class="{
                                                'household-view-check-cell': [
                                                    'is_complementary',
                                                    'mobility_disability',
                                                    'hearing_speech_disability',
                                                    'visual_disability',
                                                    'mental_disability',
                                                    'intellectual_disability',
                                                    'learning_disability',
                                                    'autism',
                                                    'other_disability',
                                                    'has_disability_cert',
                                                    'can_study',
                                                ].includes(column.key),
                                            }"
                                        >
                                            {{
                                                householdViewMemberDisplayValue(
                                                    person,
                                                    column.key,
                                                )
                                            }}
                                        </td>
                                        <td
                                            v-if="
                                                !householdDetailReadOnly &&
                                                (permissions.includes(
                                                    'update-households',
                                                ) ||
                                                    permissions.includes(
                                                        'delete-households',
                                                    ))
                                            "
                                        >
                                            <div
                                                class="household-member-actions household-view-member-actions"
                                            >
                                                <button
                                                    v-if="
                                                        !householdDetailReadOnly &&
                                                        permissions.includes(
                                                            'update-households',
                                                        )
                                                    "
                                                    class="household-icon-action"
                                                    type="button"
                                                    :aria-label="`Sửa thông tin ${person.first_name ?? 'nhân khẩu'}`"
                                                    title="Sửa nhân khẩu"
                                                    @click="
                                                        beginMember(
                                                            false,
                                                            person,
                                                        )
                                                    "
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            d="M13.8 4.2a2 2 0 0 1 2.8 2.8L8 15.6l-4 1 1-4 8.8-8.4Z"
                                                        />
                                                        <path
                                                            d="m12.5 5.5 3 3M13 20h8"
                                                        />
                                                    </svg>
                                                </button>
                                                <button
                                                    v-if="
                                                        !householdDetailReadOnly &&
                                                        permissions.includes(
                                                            'delete-households',
                                                        )
                                                    "
                                                    class="household-icon-action household-icon-danger"
                                                    type="button"
                                                    :aria-label="`Xóa ${person.first_name ?? 'nhân khẩu'}`"
                                                    title="Xóa nhân khẩu"
                                                    @click="
                                                        deleteMember(person)
                                                    "
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            d="M4 7h16M10 11v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"
                                                        />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="!householdPersons.length">
                                        <td
                                            :colspan="
                                                householdViewMemberColumns.length +
                                                (householdDetailReadOnly ||
                                                (!permissions.includes(
                                                    'update-households',
                                                ) &&
                                                    !permissions.includes(
                                                        'delete-households',
                                                    ))
                                                    ? 1
                                                    : 2)
                                            "
                                            class="household-grid-empty"
                                        >
                                            Chưa có nhân khẩu trong phiếu này.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            v-if="householdDetailLastPage > 1"
                            class="household-detail-pagination"
                            aria-label="Phân trang nhân khẩu"
                        >
                            <span aria-live="polite">
                                {{
                                    (householdDetailPage - 1) *
                                        householdDetailPageSize +
                                    1
                                }}–{{
                                    Math.min(
                                        householdDetailPage *
                                            householdDetailPageSize,
                                        householdDetailTotal,
                                    )
                                }}
                                / {{ householdDetailTotal }} nhân khẩu
                            </span>
                            <div>
                                <button
                                    type="button"
                                    :disabled="
                                        householdDetailPageLoading ||
                                        householdDetailPage <= 1
                                    "
                                    @click="
                                        loadHouseholdDetailPage(
                                            householdDetailPage - 1,
                                        )
                                    "
                                >
                                    Trước
                                </button>
                                <span>
                                    Trang {{ householdDetailPage }} /
                                    {{ householdDetailLastPage }}
                                </span>
                                <button
                                    type="button"
                                    :disabled="
                                        householdDetailPageLoading ||
                                        householdDetailPage >=
                                            householdDetailLastPage
                                    "
                                    @click="
                                        loadHouseholdDetailPage(
                                            householdDetailPage + 1,
                                        )
                                    "
                                >
                                    Sau
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="household-section-title">
                        <span
                            class="household-section-icon household-education-icon"
                            aria-hidden="true"
                            ><svg viewBox="0 0 24 24">
                                <path d="m2 9 10-5 10 5-10 5L2 9Z" />
                                <path
                                    d="M6 11v5c3.5 3 8.5 3 12 0v-5M22 9v6"
                                /></svg
                        ></span>
                        <div>
                            <h3>Danh sách Nhân khẩu</h3>
                            <p>
                                {{ householdPersons.length }} thành viên trong
                                hộ gia đình
                            </p>
                        </div>
                    </div>
                    <div v-if="false" class="household-member-readonly-list">
                        <article
                            v-for="(person, index) in householdPersons"
                            :key="`form-${String(person.id ?? index)}`"
                            class="household-member-readonly-card"
                        >
                            <header
                                class="household-member-readonly-card-header"
                            >
                                <div>
                                    <span>NHÂN KHẨU {{ index + 1 }}</span>
                                    <h4>
                                        {{
                                            [
                                                person.last_name,
                                                person.first_name,
                                            ]
                                                .filter(Boolean)
                                                .join(" ") || "Chưa có họ tên"
                                        }}
                                    </h4>
                                </div>
                                <span
                                    v-if="
                                        person.relationship_with_head ===
                                        'Chủ hộ'
                                    "
                                    class="household-head-badge"
                                    >Chủ hộ</span
                                >
                                <span
                                    v-else
                                    class="household-member-readonly-relation"
                                >
                                    {{
                                        displayFormValue(
                                            person.relationship_with_head,
                                        )
                                    }}
                                </span>
                            </header>
                            <section class="household-member-form-section">
                                <h4>Thông tin đối tượng</h4>
                                <div class="household-member-readonly-field">
                                    <span>Họ và tên đệm</span>
                                    <strong>{{
                                        displayFormValue(person.last_name)
                                    }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Tên</span>
                                    <strong>{{
                                        displayFormValue(person.first_name)
                                    }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Ngày sinh</span>
                                    <strong>{{
                                        displayDate(person.dob)
                                    }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Giới tính</span>
                                    <strong>{{ personGender(person) }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Dân tộc</span>
                                    <strong>{{
                                        personEthnicity(person)
                                    }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Tôn giáo</span>
                                    <strong>{{
                                        displayFormValue(person.religion)
                                    }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Diện ưu tiên</span>
                                    <strong>{{
                                        displayFormValue(person.priority_type)
                                    }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Họ tên cha / mẹ</span>
                                    <strong>{{
                                        displayFormValue(person.parent_name)
                                    }}</strong>
                                </div>
                            </section>
                            <section class="household-member-form-section">
                                <h4>Thông tin liên lạc</h4>
                                <div class="household-member-readonly-field">
                                    <span>Quan hệ với chủ hộ</span>
                                    <strong>{{
                                        displayFormValue(
                                            person.relationship_with_head,
                                        )
                                    }}</strong>
                                </div>
                                <div class="household-member-readonly-field">
                                    <span>Số điện thoại</span>
                                    <strong>{{
                                        displayFormValue(person.phone)
                                    }}</strong>
                                </div>
                                <div
                                    class="household-member-readonly-field household-member-wide-field"
                                >
                                    <span>Ghi chú</span>
                                    <strong>{{
                                        displayFormValue(person.note)
                                    }}</strong>
                                </div>
                            </section>
                            <section
                                class="household-member-form-section household-member-readonly-education"
                            >
                                <h4>Thông tin học tập</h4>
                                <article
                                    v-for="education in personEducations(
                                        person,
                                    )"
                                    :key="
                                        String(
                                            education.id ??
                                                education.school_year,
                                        )
                                    "
                                    class="household-member-readonly-year"
                                >
                                    <h5>
                                        {{
                                            displayFormValue(
                                                education.school_year,
                                            )
                                        }}
                                    </h5>
                                    <div class="household-member-readonly-grid">
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Trường học</span>
                                            <strong>{{
                                                displayFormValue(
                                                    pathValue(
                                                        education,
                                                        "school.name",
                                                    ),
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Khối học</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.academic_block,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Lớp hiện tại</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.current_class,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Trình độ phổ cập</span>
                                            <strong>{{
                                                displayFormValue(
                                                    educationGraduationLabel(
                                                        education,
                                                    ) ||
                                                        education.graduation_level,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Năm tốt nghiệp</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.graduation_year,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Học bổ túc</span>
                                            <strong>{{
                                                displayFormBoolean(
                                                    education.is_complementary,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span
                                                >Trình độ tốt nghiệp nghề</span
                                            >
                                            <strong>{{
                                                displayFormValue(
                                                    education.vocational_grad_level,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Năm tốt nghiệp nghề</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.vocational_grad_year,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Lớp đã hoàn thành</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.finished_class,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Năm hoàn thành lớp</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.finished_year,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Lớp đã bỏ học</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.dropped_class,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Năm bỏ học</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.dropped_year,
                                                )
                                            }}</strong>
                                        </div>
                                    </div>
                                </article>
                                <p
                                    v-if="!personEducations(person).length"
                                    class="household-member-readonly-empty"
                                >
                                    Chưa cập nhật thông tin học tập.
                                </p>
                            </section>
                            <section
                                class="household-member-form-section household-member-readonly-literacy"
                            >
                                <h4>Xóa mù chữ</h4>
                                <article
                                    v-for="education in personEducations(
                                        person,
                                    )"
                                    :key="`literacy-${String(education.id ?? education.school_year)}`"
                                    class="household-member-readonly-year"
                                >
                                    <h5>
                                        {{
                                            displayFormValue(
                                                education.school_year,
                                            )
                                        }}
                                    </h5>
                                    <div class="household-member-readonly-grid">
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Đang học lớp</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.literacy_current_class,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Lớp XMC đã hoàn thành</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.literacy_completed_class ??
                                                        education.completed_class,
                                                )
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Mức độ tái mù chữ</span>
                                            <strong>{{
                                                education.literacy_relapse_level
                                                    ? `Mức độ ${String(education.literacy_relapse_level)}`
                                                    : "Chưa cập nhật"
                                            }}</strong>
                                        </div>
                                        <div
                                            class="household-member-readonly-field"
                                        >
                                            <span>Khả năng học tập</span>
                                            <strong>{{
                                                displayFormValue(
                                                    education.learning_capacity,
                                                )
                                            }}</strong>
                                        </div>
                                    </div>
                                </article>
                                <p
                                    v-if="!personEducations(person).length"
                                    class="household-member-readonly-empty"
                                >
                                    Chưa cập nhật thông tin xóa mù chữ.
                                </p>
                            </section>
                            <section
                                class="household-member-form-section household-member-readonly-disability"
                            >
                                <h4>Khuyết tật</h4>
                                <div class="household-member-readonly-grid">
                                    <div
                                        v-for="option in memberDisabilityOptions"
                                        :key="option.key"
                                        class="household-member-readonly-field"
                                    >
                                        <span>{{ option.label }}</span>
                                        <strong>{{
                                            displayFormBoolean(
                                                asRow(person.disability)[
                                                    option.key
                                                ],
                                            )
                                        }}</strong>
                                    </div>
                                    <div
                                        class="household-member-readonly-field"
                                    >
                                        <span>Khả năng học tập</span>
                                        <strong>{{
                                            displayFormBoolean(
                                                asRow(person.disability)
                                                    .can_study,
                                            )
                                        }}</strong>
                                    </div>
                                </div>
                            </section>
                            <section
                                class="household-member-form-section household-member-readonly-circumstance"
                            >
                                <h4>Hoàn cảnh</h4>
                                <div class="household-member-readonly-grid">
                                    <div
                                        class="household-member-readonly-field"
                                    >
                                        <span>Hoàn cảnh đặc biệt</span>
                                        <strong>{{
                                            displayFormValue(
                                                asRow(person.disability)
                                                    .special_circumstance,
                                            )
                                        }}</strong>
                                    </div>
                                    <div
                                        class="household-member-readonly-field household-member-wide-field"
                                    >
                                        <span>Chi tiết hoàn cảnh</span>
                                        <strong>{{
                                            displayFormValue(
                                                asRow(person.disability)
                                                    .special_circumstance_detail,
                                            )
                                        }}</strong>
                                    </div>
                                </div>
                            </section>
                            <div class="household-member-readonly-actions">
                                <button
                                    v-if="
                                        !householdDetailReadOnly &&
                                        permissions.includes(
                                            'update-households',
                                        )
                                    "
                                    class="secondary-button"
                                    type="button"
                                    @click="beginMember(false, person)"
                                >
                                    Sửa nhân khẩu
                                </button>
                                <button
                                    v-if="
                                        !householdDetailReadOnly &&
                                        permissions.includes(
                                            'delete-households',
                                        )
                                    "
                                    class="secondary-button household-member-readonly-delete"
                                    type="button"
                                    @click="deleteMember(person)"
                                >
                                    Xóa nhân khẩu
                                </button>
                            </div>
                        </article>
                        <p
                            v-if="!householdPersons.length"
                            class="household-member-readonly-empty"
                        >
                            Chưa có nhân khẩu trong phiếu này.
                        </p>
                    </div>
                    <details
                        v-if="false"
                        class="household-detail-table-details"
                    >
                        <summary>Xem bảng tổng hợp và thao tác nhanh</summary>
                        <div class="household-grid-wrap">
                            <table
                                class="household-grid household-detail-table"
                            >
                                <thead>
                                    <tr>
                                        <th>STT</th>
                                        <th>Quan hệ với chủ hộ</th>
                                        <th>Họ và tên</th>
                                        <th>Ngày sinh / Giới tính</th>
                                        <th>Học tập / Phổ cập (từng năm)</th>
                                        <th>Trường / Lớp (từng năm)</th>
                                        <th>Diện ưu tiên / Khuyết tật</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(
                                            person, index
                                        ) in householdPersons"
                                        :key="String(person.id)"
                                        :class="{
                                            'household-head-row':
                                                person.relationship_with_head ===
                                                'Chủ hộ',
                                        }"
                                    >
                                        <td class="household-grid-index">
                                            {{ index + 1 }}
                                        </td>
                                        <td>
                                            <span
                                                v-if="
                                                    person.relationship_with_head ===
                                                    'Chủ hộ'
                                                "
                                                class="household-head-badge"
                                                >Chủ hộ</span
                                            >
                                            <span v-else>{{
                                                person.relationship_with_head ||
                                                "—"
                                            }}</span>
                                        </td>
                                        <td class="household-grid-name">
                                            {{
                                                [
                                                    person.last_name,
                                                    person.first_name,
                                                ]
                                                    .filter(Boolean)
                                                    .join(" ") ||
                                                "Chưa có họ tên"
                                            }}
                                        </td>
                                        <td>
                                            <span>{{
                                                displayDate(person.dob)
                                            }}</span
                                            ><small>{{
                                                personGender(person)
                                            }}</small>
                                        </td>
                                        <td>
                                            <div
                                                v-if="
                                                    personEducations(person)
                                                        .length
                                                "
                                                class="household-detail-record-list"
                                            >
                                                <article
                                                    v-for="education in personEducations(
                                                        person,
                                                    )"
                                                    :key="
                                                        String(
                                                            education.id ??
                                                                education.school_year,
                                                        )
                                                    "
                                                    class="household-detail-record"
                                                >
                                                    <strong>{{
                                                        education.school_year ||
                                                        "Chưa rõ năm học"
                                                    }}</strong>
                                                    <span
                                                        v-if="
                                                            educationGraduationLabel(
                                                                education,
                                                            )
                                                        "
                                                        >{{
                                                            educationGraduationLabel(
                                                                education,
                                                            )
                                                        }}</span
                                                    >
                                                    <span
                                                        v-for="line in educationLiteracyLabels(
                                                            education,
                                                        )"
                                                        :key="line"
                                                        >{{ line }}</span
                                                    >
                                                    <span
                                                        v-if="
                                                            !educationGraduationLabel(
                                                                education,
                                                            ) &&
                                                            !educationLiteracyLabels(
                                                                education,
                                                            ).length
                                                        "
                                                        >Chưa cập nhật kết quả
                                                        học tập</span
                                                    >
                                                </article>
                                            </div>
                                            <span v-else>Chưa cập nhật</span>
                                        </td>
                                        <td>
                                            <div
                                                v-if="
                                                    personEducations(person)
                                                        .length
                                                "
                                                class="household-detail-record-list"
                                            >
                                                <article
                                                    v-for="education in personEducations(
                                                        person,
                                                    )"
                                                    :key="`school-${String(education.id ?? education.school_year)}`"
                                                    class="household-detail-record"
                                                >
                                                    <strong>{{
                                                        education.school_year ||
                                                        "Chưa rõ năm học"
                                                    }}</strong>
                                                    <span>{{
                                                        pathValue(
                                                            education,
                                                            "school.name",
                                                        )
                                                    }}</span>
                                                    <span
                                                        >Lớp:
                                                        {{
                                                            education.current_class ||
                                                            "—"
                                                        }}<template
                                                            v-if="
                                                                education.academic_block
                                                            "
                                                        >
                                                            · Khối:
                                                            {{
                                                                education.academic_block
                                                            }}</template
                                                        ></span
                                                    >
                                                </article>
                                            </div>
                                            <span v-else>Chưa cập nhật</span>
                                        </td>
                                        <td>
                                            <div
                                                class="household-detail-record-list"
                                            >
                                                <article
                                                    class="household-detail-record"
                                                >
                                                    <strong>Ưu tiên</strong>
                                                    <span>{{
                                                        person.priority_type ||
                                                        "Không ghi nhận"
                                                    }}</span>
                                                </article>
                                                <article
                                                    class="household-detail-record"
                                                >
                                                    <strong
                                                        >Khuyết tật / hoàn
                                                        cảnh</strong
                                                    >
                                                    <span
                                                        v-for="line in personDisabilityDetails(
                                                            person,
                                                        )"
                                                        :key="line"
                                                        >{{ line }}</span
                                                    >
                                                </article>
                                            </div>
                                        </td>
                                        <td>
                                            <div
                                                class="household-member-actions"
                                            >
                                                <button
                                                    class="household-icon-action"
                                                    type="button"
                                                    :aria-label="`Xem thông tin ${person.first_name ?? 'nhân khẩu'}`"
                                                    title="Xem chi tiết"
                                                    @click="
                                                        personDetailFromTrash = false;
                                                        selectedHouseholdPerson =
                                                            person
                                                    "
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"
                                                        />
                                                        <circle
                                                            cx="12"
                                                            cy="12"
                                                            r="2.7"
                                                        />
                                                    </svg>
                                                </button>
                                                <button
                                                    v-if="
                                                        !householdDetailReadOnly &&
                                                        permissions.includes(
                                                            'update-households',
                                                        )
                                                    "
                                                    class="household-icon-action"
                                                    type="button"
                                                    :aria-label="`Sửa thông tin ${person.first_name ?? 'nhân khẩu'}`"
                                                    title="Sửa nhân khẩu"
                                                    @click="
                                                        beginMember(
                                                            false,
                                                            person,
                                                        )
                                                    "
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            d="M13.8 4.2a2 2 0 0 1 2.8 2.8L8 15.6l-4 1 1-4 8.8-8.4Z"
                                                        />
                                                        <path
                                                            d="m12.5 5.5 3 3M13 20h8"
                                                        />
                                                    </svg>
                                                </button>
                                                <button
                                                    v-if="
                                                        !householdDetailReadOnly &&
                                                        permissions.includes(
                                                            'delete-households',
                                                        )
                                                    "
                                                    class="household-icon-action household-icon-danger"
                                                    type="button"
                                                    :aria-label="`Xóa ${person.first_name ?? 'nhân khẩu'}`"
                                                    title="Xóa nhân khẩu"
                                                    @click="
                                                        deleteMember(person)
                                                    "
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            d="M4 7h16M10 11v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"
                                                        />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="!householdPersons.length">
                                        <td
                                            colspan="8"
                                            class="household-grid-empty"
                                        >
                                            Chưa có nhân khẩu trong phiếu này.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </section>
            </section>
        </div>
        <div
            v-if="
                memberEditorOpen &&
                (householdDetail || createdHouseholdId !== null || !editing)
            "
            class="workspace-modal-backdrop household-member-editor-backdrop"
            :class="{
                'household-member-create-page-backdrop':
                    isHouseholdWizardMemberPage,
            }"
            role="presentation"
            @click.self="
                !isHouseholdWizardMemberPage && (memberEditorOpen = false)
            "
            @keydown.esc="memberEditorOpen = false"
        >
            <form
                ref="memberEditorForm"
                class="workspace-form household-member-editor household-member-dialog"
                novalidate
                :class="{
                    'household-head-education-step':
                        !editing &&
                        (memberId === null || isHouseholdWizardMemberPage),
                    'household-member-create-page': isHouseholdWizardMemberPage,
                }"
                :role="isHouseholdWizardMemberPage ? undefined : 'dialog'"
                :aria-modal="isHouseholdWizardMemberPage ? undefined : 'true'"
                aria-labelledby="household-member-form-title"
                @submit.prevent="saveMember"
            >
                <header class="household-member-dialog-header">
                    <div>
                        <h3 id="household-member-form-title">
                            {{
                                isHouseholdWizardMemberPage
                                    ? memberIsHead
                                        ? "Thông tin chủ hộ"
                                        : "Thông tin thành viên"
                                    : memberId === null
                                      ? "Thêm"
                                      : "Cập nhật"
                            }}
                            {{
                                isHouseholdWizardMemberPage
                                    ? ""
                                    : memberIsHead
                                      ? "chủ hộ"
                                      : "thành viên"
                            }}
                        </h3>
                    </div>
                    <span
                        v-if="
                            isHouseholdWizardMemberPage &&
                            String(form.household_code ?? '').trim()
                        "
                        class="household-number-label"
                        :aria-label="`Mã phiếu ${form.household_code}`"
                    >
                        Mã phiếu: {{ form.household_code }}
                    </span>
                    <button
                        v-if="!isHouseholdWizardMemberPage"
                        class="modal-icon-button"
                        type="button"
                        aria-label="Đóng biểu mẫu"
                        :disabled="memberBusy"
                        @click="memberEditorOpen = false"
                    >
                        ×
                    </button>
                </header>
                <div class="household-member-create-columns">
                    <div class="household-member-create-column">
                        <p
                            v-if="error && false"
                            class="notice household-member-form-error"
                            role="alert"
                        >
                            {{ error }}
                        </p>
                        <h4
                            class="household-member-group-title household-member-person-title"
                            data-step="01"
                        >
                            <span>Thông tin đối tượng</span>
                            <small>
                                Thông tin cá nhân và các thông tin nhận diện
                                nhân khẩu
                            </small>
                        </h4>
                        <section
                            v-if="
                                isHouseholdWizardMemberPage ||
                                (memberIsHead &&
                                    !editing &&
                                    memberId === null) ||
                                !memberIsHead ||
                                headMemberEducationStep
                            "
                            class="household-member-form-section household-member-person-section"
                        >
                            <label class="workspace-field">
                                <span>Họ và tên đệm</span>
                                <input
                                    v-model.trim="memberForm.last_name"
                                    type="text"
                                    required
                                    maxlength="50"
                                    :readonly="isNewHouseholdHead"
                                    :class="{
                                        'household-member-locked-field':
                                            isNewHouseholdHead,
                                    }"
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.last_name ||
                                            memberFormErrors.last_name,
                                        )
                                    "
                                />

                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.last_name ||
                                        memberFormErrors.last_name ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label class="workspace-field">
                                <span>Tên</span>
                                <input
                                    v-model.trim="memberForm.first_name"
                                    type="text"
                                    required
                                    maxlength="100"
                                    :readonly="isNewHouseholdHead"
                                    :class="{
                                        'household-member-locked-field':
                                            isNewHouseholdHead,
                                    }"
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.first_name ||
                                            memberFormErrors.first_name,
                                        )
                                    "
                                />

                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.first_name ||
                                        memberFormErrors.first_name ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label class="workspace-field">
                                <span>Ngày sinh</span>
                                <button
                                    class="household-date-picker-trigger"
                                    type="button"
                                    aria-label="Chọn ngày sinh"
                                    aria-required="true"
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.dob ||
                                            memberFormErrors.dob,
                                        )
                                    "
                                    @click="
                                        openHouseholdDatePicker($event, {
                                            kind: 'member',
                                        })
                                    "
                                >
                                    {{
                                        datePickerDisplayValue(memberForm.dob) ||
                                        "dd/mm/yyyy"
                                    }}
                                    <span aria-hidden="true">▦</span>
                                </button>
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.dob ||
                                        memberFormErrors.dob ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label class="workspace-field">
                                <span>Giới tính</span>
                                <select
                                    v-model="memberForm.gender"
                                    required
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.gender ||
                                            memberFormErrors.gender,
                                        )
                                    "
                                >
                                    <option value="NAM">Nam</option>
                                    <option value="NU">Nữ</option>
                                </select>
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.gender ||
                                        memberFormErrors.gender ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label class="workspace-field">
                                <span>Dân tộc</span>
                                <select
                                    v-model="memberForm.ethnicity_code"
                                    required
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.ethnicity_code ||
                                            memberFormErrors.ethnicity_code,
                                        )
                                    "
                                >
                                    <option value="">Chọn dân tộc</option>
                                    <option
                                        v-for="ethnicity in ethnicities"
                                        :key="String(ethnicity.code)"
                                        :value="String(ethnicity.code)"
                                    >
                                        {{ ethnicity.name }}
                                    </option>
                                </select>
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.ethnicity_code ||
                                        memberFormErrors.ethnicity_code ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label class="workspace-field"
                                ><span>Tôn giáo</span
                                ><input
                                    v-model.trim="memberForm.religion"
                                    type="text"
                                    maxlength="50"
                            /></label>
                            <label class="workspace-field"
                                ><span>Diện ưu tiên</span
                                ><input
                                    v-model.trim="memberForm.priority_type"
                                    type="text"
                                    maxlength="100"
                            /></label>
                        </section>
                    </div>
                    <div class="household-member-create-column">
                        <h4
                            class="household-member-group-title household-member-education-title"
                            data-step="02"
                        >
                            <span>Thông tin học tập</span>
                            <small>
                                Năm học, trường lớp và kết quả học tập
                            </small>
                        </h4>
                        <section
                            v-if="
                                isHouseholdWizardMemberPage ||
                                (memberIsHead &&
                                    !editing &&
                                    memberId === null) ||
                                !memberIsHead ||
                                headMemberEducationStep
                            "
                            class="household-member-form-section household-member-education-section"
                        >
                            <h5 class="household-member-subsection-title">
                                Tình hình học tập
                            </h5>
                            <label
                                class="workspace-field household-member-compact-field"
                            >
                                <span>Năm học</span>
                                <input
                                    v-model="educationForm.school_year"
                                    type="text"
                                    readonly
                                    aria-readonly="true"
                                    required
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors[
                                                'education.school_year'
                                            ] ||
                                            memberFormErrors[
                                                'education.school_year'
                                            ],
                                        )
                                    "
                                />
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors[
                                            "education.school_year"
                                        ] ||
                                        memberFormErrors[
                                            "education.school_year"
                                        ] ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label
                                class="workspace-field household-member-compact-field"
                                ><span>Lớp học</span
                                ><input
                                    v-model.trim="educationForm.current_class"
                                    type="text"
                                    maxlength="50"
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors[
                                                'education.current_class'
                                            ] ||
                                            memberFormErrors[
                                                'education.current_class'
                                            ],
                                        )
                                    "
                                />
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors[
                                            "education.current_class"
                                        ] ||
                                        memberFormErrors[
                                            "education.current_class"
                                        ] ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label
                                class="workspace-field household-member-mini-field"
                                ><span>Khối học (tự động)</span
                                ><input
                                    v-model="educationForm.academic_block"
                                    type="text"
                                    maxlength="50"
                                    readonly
                                    aria-readonly="true"
                            /></label>
                            <label class="workspace-field">
                                <span>Trường học / Mã trường</span>
                                <select
                                    v-model="educationForm.school_code"
                                    :aria-busy="personSchoolOptionsLoading"
                                >
                                    <option value="">
                                        {{
                                            !educationForm.academic_block
                                                ? "Nhập lớp để chọn trường"
                                                : personSchools.length
                                                  ? "Chưa chọn trường"
                                                  : "Không có trường phù hợp"
                                        }}
                                    </option>
                                    <option
                                        v-for="school in personSchools"
                                        :key="String(school.code)"
                                        :value="String(school.code)"
                                    >
                                        {{ school.name }} · {{ school.code }}
                                    </option>
                                </select>
                                <small v-if="personSchoolOptionsLoading"
                                    >Đang tải trường phù hợp...</small
                                >
                                <small
                                    v-if="personSchoolOptionsError"
                                    class="field-error"
                                    role="alert"
                                    >{{ personSchoolOptionsError }}</small
                                >
                                <small
                                    v-if="
                                        memberFormErrors[
                                            'education.school_code'
                                        ]
                                    "
                                    class="field-error"
                                    role="alert"
                                    >{{
                                        memberFormErrors[
                                            "education.school_code"
                                        ]
                                    }}</small
                                >
                            </label>
                            <h5 class="household-member-subsection-title">
                                Kết quả và quá trình học
                            </h5>
                            <div class="household-member-education-pair">
                                <label class="workspace-field">
                                    <span>Bậc tốt nghiệp</span>
                                    <select
                                        v-model="educationForm.graduation_level"
                                    >
                                        <option value="">Chưa xác định</option>
                                        <option value="MN">Mầm non</option>
                                        <option value="TH">Tiểu học</option>
                                        <option value="THCS">
                                            Trung học cơ sở
                                        </option>
                                        <option value="THPT">
                                            Trung học phổ thông
                                        </option>
                                    </select>
                                </label>
                                <div
                                    class="household-member-education-completion-row"
                                >
                                    <label class="workspace-field">
                                        <span>Năm tốt nghiệp</span>
                                        <div class="education-year-control">
                                            <input
                                                v-model.trim="graduationYearStart"
                                                type="text"
                                                maxlength="4"
                                                placeholder="YYYY"
                                                :aria-invalid="
                                                    Boolean(
                                                        memberClientValidationErrors[
                                                            'education.graduation_year'
                                                        ] ||
                                                        memberFormErrors[
                                                            'education.graduation_year'
                                                        ],
                                                    )
                                                "
                                            />
                                            <span
                                                v-if="
                                                    educationYearEnd(
                                                        'graduation_year',
                                                    )
                                                "
                                                >-{{
                                                    educationYearEnd(
                                                        'graduation_year',
                                                    )
                                                }}</span
                                            >
                                        </div>
                                        <small
                                            class="field-error household-member-required-error"
                                            role="alert"
                                        >
                                            {{
                                                memberClientValidationErrors[
                                                    "education.graduation_year"
                                                ] ||
                                                memberFormErrors[
                                                    "education.graduation_year"
                                                ] ||
                                                ""
                                            }}
                                        </small>
                                    </label>
                                    <label
                                        class="household-member-complementary-checkbox"
                                    >
                                        <input
                                            v-model="
                                                educationForm.is_complementary
                                            "
                                            type="checkbox"
                                            true-value="1"
                                            false-value="0"
                                        />
                                        <span>Học bổ túc</span>
                                    </label>
                                </div>
                            </div>
                            <div class="household-member-education-pair">
                                <label class="workspace-field">
                                    <span>Bậc tốt nghiệp nghề</span>
                                    <input
                                        v-model.trim="
                                            educationForm.vocational_grad_level
                                        "
                                        type="text"
                                        maxlength="50"
                                    />
                                </label>
                                <label
                                    class="workspace-field household-member-compact-field"
                                >
                                    <span>Năm tốt nghiệp nghề</span>
                                    <div class="education-year-control">
                                        <input
                                            v-model.trim="
                                                vocationalGraduationYearStart
                                            "
                                            type="text"
                                            maxlength="4"
                                            placeholder="YYYY"
                                            :aria-invalid="
                                                Boolean(
                                                    memberClientValidationErrors[
                                                        'education.vocational_grad_year'
                                                    ] ||
                                                    memberFormErrors[
                                                        'education.vocational_grad_year'
                                                    ],
                                                )
                                            "
                                        />
                                        <span
                                            v-if="
                                                educationYearEnd(
                                                    'vocational_grad_year',
                                                )
                                            "
                                            >-{{
                                                educationYearEnd(
                                                    'vocational_grad_year',
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <small
                                        class="field-error household-member-required-error"
                                        role="alert"
                                    >
                                        {{
                                            memberClientValidationErrors[
                                                "education.vocational_grad_year"
                                            ] ||
                                            memberFormErrors[
                                                "education.vocational_grad_year"
                                            ] ||
                                            ""
                                        }}
                                    </small>
                                </label>
                            </div>
                            <div class="household-member-education-pair">
                                <label
                                    class="workspace-field household-member-compact-field"
                                >
                                    <span>Lớp đã hoàn thành</span>
                                    <input
                                        v-model.trim="
                                            educationForm.finished_class
                                        "
                                        type="text"
                                        maxlength="20"
                                        :aria-invalid="
                                            Boolean(
                                                memberClientValidationErrors[
                                                    'education.finished_class'
                                                ] ||
                                                memberFormErrors[
                                                    'education.finished_class'
                                                ],
                                            )
                                        "
                                    />
                                    <small
                                        class="field-error household-member-required-error"
                                        role="alert"
                                    >
                                        {{
                                            memberClientValidationErrors[
                                                "education.finished_class"
                                            ] ||
                                            memberFormErrors[
                                                "education.finished_class"
                                            ] ||
                                            ""
                                        }}
                                    </small>
                                </label>
                                <label
                                    class="workspace-field household-member-compact-field"
                                >
                                    <span>Năm hoàn thành lớp</span>
                                    <div class="education-year-control">
                                        <input
                                            v-model.trim="finishedYearStart"
                                            type="text"
                                            maxlength="4"
                                            placeholder="YYYY"
                                            :aria-invalid="
                                                Boolean(
                                                    memberClientValidationErrors[
                                                        'education.finished_year'
                                                    ] ||
                                                    memberFormErrors[
                                                        'education.finished_year'
                                                    ],
                                                )
                                            "
                                        />
                                        <span
                                            v-if="
                                                educationYearEnd('finished_year')
                                            "
                                            >-{{
                                                educationYearEnd('finished_year')
                                            }}</span
                                        >
                                    </div>
                                    <small
                                        class="field-error household-member-required-error"
                                        role="alert"
                                    >
                                        {{
                                            memberClientValidationErrors[
                                                "education.finished_year"
                                            ] ||
                                            memberFormErrors[
                                                "education.finished_year"
                                            ] ||
                                            ""
                                        }}
                                    </small>
                                </label>
                            </div>
                            <div class="household-member-education-pair">
                                <label
                                    class="workspace-field household-member-compact-field"
                                >
                                    <span>Lớp đã bỏ học</span>
                                    <input
                                        v-model.trim="
                                            educationForm.dropped_class
                                        "
                                        type="text"
                                        maxlength="20"
                                        :aria-invalid="
                                            Boolean(
                                                memberClientValidationErrors[
                                                    'education.dropped_class'
                                                ] ||
                                                memberFormErrors[
                                                    'education.dropped_class'
                                                ],
                                            )
                                        "
                                    />
                                    <small
                                        class="field-error household-member-required-error"
                                        role="alert"
                                    >
                                        {{
                                            memberClientValidationErrors[
                                                "education.dropped_class"
                                            ] ||
                                            memberFormErrors[
                                                "education.dropped_class"
                                            ] ||
                                            ""
                                        }}
                                    </small>
                                </label>
                                <label
                                    class="workspace-field household-member-compact-field"
                                >
                                    <span>Năm bỏ học</span>
                                    <div class="education-year-control">
                                        <input
                                            v-model.trim="droppedYearStart"
                                            type="text"
                                            maxlength="4"
                                            placeholder="YYYY"
                                            :aria-invalid="
                                                Boolean(
                                                    memberClientValidationErrors[
                                                        'education.dropped_year'
                                                    ] ||
                                                    memberFormErrors[
                                                        'education.dropped_year'
                                                    ],
                                                )
                                            "
                                        />
                                        <span
                                            v-if="
                                                educationYearEnd('dropped_year')
                                            "
                                            >-{{
                                                educationYearEnd('dropped_year')
                                            }}</span
                                        >
                                    </div>
                                    <small
                                        class="field-error household-member-required-error"
                                        role="alert"
                                    >
                                        {{
                                            memberClientValidationErrors[
                                                "education.dropped_year"
                                            ] ||
                                            memberFormErrors[
                                                "education.dropped_year"
                                            ] ||
                                            ""
                                        }}
                                    </small>
                                </label>
                            </div>
                        </section>
                        <h4
                            class="household-member-group-title household-member-literacy-title"
                            data-step="03"
                        >
                            <span>Xóa mù chữ</span>
                            <small>
                                Thông tin học tập và hoàn thành chương trình xóa
                                mù chữ
                            </small>
                        </h4>
                        <section
                            v-if="
                                isHouseholdWizardMemberPage ||
                                (memberIsHead &&
                                    !editing &&
                                    memberId === null) ||
                                !memberIsHead ||
                                headMemberEducationStep
                            "
                            class="household-member-form-section household-member-literacy-section"
                        >
                            <label
                                class="workspace-field household-member-compact-field"
                                ><span>Đang học lớp</span
                                ><input
                                    v-model.trim="
                                        educationForm.literacy_current_class
                                    "
                                    type="text"
                                    maxlength="20"
                            /></label>
                            <label
                                class="workspace-field household-member-compact-field"
                                ><span>Hoàn thành lớp</span
                                ><input
                                    v-model.trim="
                                        educationForm.literacy_completed_class
                                    "
                                    type="text"
                                    maxlength="20"
                            /></label>
                            <label
                                class="workspace-field household-member-compact-field"
                            >
                                <span>Mức độ tái mù chữ</span>
                                <select
                                    v-model="
                                        educationForm.literacy_relapse_level
                                    "
                                >
                                    <option value="">Không ghi nhận</option>
                                    <option value="1">Mức độ 1</option>
                                    <option value="2">Mức độ 2</option>
                                </select>
                            </label>
                        </section>
                        <h4
                            class="household-member-group-title household-member-circumstance-title"
                            data-step="05"
                        >
                            <span>Hoàn cảnh</span>
                            <small>
                                Ghi nhận hoàn cảnh đặc biệt và thông tin chi
                                tiết
                            </small>
                        </h4>
                        <section
                            v-if="
                                isHouseholdWizardMemberPage ||
                                (memberIsHead &&
                                    !editing &&
                                    memberId === null) ||
                                !memberIsHead ||
                                headMemberEducationStep
                            "
                            class="household-member-form-section household-member-circumstance-section"
                        >
                            <label
                                class="workspace-field household-member-compact-field"
                            >
                                <span>Hoàn cảnh đặc biệt</span>
                                <select
                                    v-model="
                                        disabilityForm.special_circumstance
                                    "
                                >
                                    <option value="">Không ghi nhận</option>
                                    <option value="Chuyển đến">
                                        Chuyển đến
                                    </option>
                                    <option value="Chuyển đi">Chuyển đi</option>
                                </select>
                            </label>
                            <label
                                class="workspace-field household-member-wide-field"
                            >
                                <span>Chi tiết hoàn cảnh</span>
                                <textarea
                                    :value="
                                        String(
                                            disabilityForm.special_circumstance_detail ??
                                                '',
                                        )
                                    "
                                    rows="2"
                                    maxlength="2000"
                                    @input="
                                        disabilityForm.special_circumstance_detail =
                                            (
                                                $event.target as HTMLTextAreaElement
                                            ).value
                                    "
                                ></textarea>
                            </label>
                        </section>
                    </div>
                    <div class="household-member-create-column">
                        <h4
                            class="household-member-group-title household-member-disability-title"
                            data-step="04"
                        >
                            <span>Khuyết tật</span>
                            <small>
                                Tình trạng khuyết tật và khả năng học tập
                            </small>
                        </h4>
                        <section
                            v-if="
                                isHouseholdWizardMemberPage ||
                                (memberIsHead &&
                                    !editing &&
                                    memberId === null) ||
                                !memberIsHead ||
                                headMemberEducationStep
                            "
                            class="household-member-form-section household-member-disability-section"
                        >
                            <div class="household-member-checkbox-grid">
                                <label
                                    v-for="option in memberDisabilityOptions"
                                    :key="option.key"
                                    class="household-member-checkbox"
                                >
                                    <input
                                        v-model="disabilityForm[option.key]"
                                        type="checkbox"
                                    />
                                    <span>{{ option.label }}</span>
                                </label>
                            </div>
                            <label
                                class="workspace-field household-member-compact-field"
                            >
                                <span>Khả năng học tập</span>
                                <select v-model="disabilityForm.can_study">
                                    <option value="">Chưa xác định</option>
                                    <option value="true">
                                        Có khả năng học tập
                                    </option>
                                    <option value="false">
                                        Không có khả năng học tập
                                    </option>
                                </select>
                            </label>
                        </section>
                    </div>
                    <div class="household-member-create-column">
                        <h4
                            class="household-member-group-title household-member-contact-title"
                            data-step="06"
                        >
                            <span>Thông tin liên lạc</span>
                            <small>
                                Quan hệ với chủ hộ, cha mẹ và thông tin liên hệ
                            </small>
                        </h4>
                        <section
                            v-if="
                                isHouseholdWizardMemberPage ||
                                (memberIsHead &&
                                    !editing &&
                                    memberId === null) ||
                                !memberIsHead ||
                                headMemberEducationStep
                            "
                            class="household-member-form-section household-member-contact-section"
                        >
                            <label v-if="memberIsHead" class="workspace-field">
                                <span>Quan hệ với chủ hộ</span>
                                <input
                                    :value="
                                        memberForm.relationship_with_head ||
                                        'Chủ hộ'
                                    "
                                    type="text"
                                    readonly
                                />
                            </label>
                            <label v-else class="workspace-field">
                                <span>Quan hệ với chủ hộ</span>
                                <select
                                    v-model="memberForm.relationship_with_head"
                                    required
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.relationship_with_head ||
                                            memberFormErrors.relationship_with_head,
                                        )
                                    "
                                >
                                    <option value="">Chọn quan hệ</option>
                                    <option
                                        v-for="relationship in [
                                            ...new Set(
                                                [
                                                    ...memberRelationships,
                                                    memberForm.relationship_with_head,
                                                ].filter(Boolean),
                                            ),
                                        ]"
                                        :key="relationship"
                                        :value="relationship"
                                    >
                                        {{ relationship }}
                                    </option>
                                </select>
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.relationship_with_head ||
                                        memberFormErrors.relationship_with_head ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label class="workspace-field"
                                ><span>Họ tên cha hoặc mẹ</span
                                ><input
                                    v-model.trim="memberForm.parent_name"
                                    type="text"
                                    maxlength="150"
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.parent_name ||
                                            memberFormErrors.parent_name,
                                        )
                                    "
                                />
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.parent_name ||
                                        memberFormErrors.parent_name ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label class="workspace-field"
                                ><span>Điện thoại</span
                                ><input
                                    v-model.trim="memberForm.phone"
                                    type="tel"
                                    maxlength="20"
                                    pattern="\+?[0-9]+"
                                    @input="sanitizeMemberPhone"
                                    :aria-invalid="
                                        Boolean(
                                            memberClientValidationErrors.phone ||
                                            memberFormErrors.phone,
                                        )
                                    "
                                />
                                <small
                                    class="field-error household-member-required-error"
                                    role="alert"
                                >
                                    {{
                                        memberClientValidationErrors.phone ||
                                        memberFormErrors.phone ||
                                        ""
                                    }}
                                </small>
                            </label>
                            <label
                                class="workspace-field household-member-wide-field"
                                ><span>Ghi chú</span
                                ><textarea
                                    v-model.trim="memberForm.note"
                                    rows="2"
                                    maxlength="2000"
                                ></textarea>
                            </label>
                        </section>
                    </div>
                </div>
                <div class="pagination-actions household-member-form-actions">
                    <button
                        class="primary-button"
                        type="submit"
                        :disabled="memberBusy"
                    >
                        {{
                            memberBusy
                                ? "Đang lưu…"
                                : memberIsHead
                                  ? "Lưu thông tin chủ hộ"
                                  : "Lưu thành viên"
                        }}
                    </button>
                    <button
                        v-if="!isHouseholdWizardMemberPage"
                        class="secondary-button"
                        type="button"
                        :disabled="memberBusy"
                        @click="memberEditorOpen = false"
                    >
                        Hủy
                    </button>
                </div>
            </form>
        </div>
        <div
            v-if="pendingHouseholdPersonDelete"
            class="workspace-modal-backdrop household-member-delete-backdrop"
            role="presentation"
            @click.self="pendingHouseholdPersonDelete = null"
            @keydown.esc="pendingHouseholdPersonDelete = null"
        >
            <section
                class="confirm-dialog"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="delete-person-title"
                aria-describedby="delete-person-message"
            >
                <span class="confirm-dialog-icon" aria-hidden="true">!</span>
                <h3 id="delete-person-title">Xóa nhân khẩu</h3>
                <p id="delete-person-message">
                    Bạn có chắc muốn xóa “{{
                        [
                            pendingHouseholdPersonDelete.last_name,
                            pendingHouseholdPersonDelete.first_name,
                        ]
                            .filter(Boolean)
                            .join(" ")
                    }}” khỏi phiếu điều tra?
                </p>
                <p v-if="error" class="notice" role="alert">{{ error }}</p>
                <div class="confirm-dialog-actions">
                    <button
                        class="secondary-button management-tool-button"
                        type="button"
                        :disabled="memberBusy"
                        @click="pendingHouseholdPersonDelete = null"
                    >
                        Hủy
                    </button>
                    <button
                        v-if="
                            pendingHouseholdPersonDelete.relationship_with_head !==
                            'Chủ hộ'
                        "
                        class="confirm-delete-button"
                        type="button"
                        :disabled="memberBusy"
                        @click="confirmDeleteMember"
                    >
                        {{ memberBusy ? "Đang xóa…" : "Xóa nhân khẩu" }}
                    </button>
                </div>
            </section>
        </div>
        <div
            v-if="selectedHouseholdPerson"
            class="workspace-modal-backdrop household-person-backdrop"
            role="presentation"
            @click.self="closeHouseholdPersonDetail"
            @keydown.esc="closeHouseholdPersonDetail"
        >
            <section
                class="household-person-dialog household-member-editor household-member-readonly-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="household-person-title"
            >
                <header class="household-member-dialog-header">
                    <div>
                        <span>THÔNG TIN THEO MẪU PHIẾU</span>
                        <h3 id="household-person-title">
                            <template v-if="personDetailFromTrash">
                                Chi tiết nhân khẩu đã xóa:
                            </template>
                            {{
                                [
                                    selectedHouseholdPerson.last_name,
                                    selectedHouseholdPerson.first_name,
                                ]
                                    .filter(Boolean)
                                    .join(" ")
                            }}
                        </h3>
                    </div>
                    <button
                        class="modal-icon-button"
                        type="button"
                        aria-label="Đóng"
                        @click="closeHouseholdPersonDetail"
                    >
                        ×
                    </button>
                </header>
                <section class="household-member-form-section">
                    <h4>Thông tin đối tượng</h4>
                    <div class="household-member-readonly-field">
                        <span>Họ và tên đệm</span>
                        <strong>{{
                            displayFormValue(selectedHouseholdPerson.last_name)
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Tên</span>
                        <strong>{{
                            displayFormValue(selectedHouseholdPerson.first_name)
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Ngày sinh</span>
                        <strong>{{
                            displayDate(selectedHouseholdPerson.dob)
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Giới tính</span>
                        <strong>{{
                            personGender(selectedHouseholdPerson)
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Dân tộc</span>
                        <strong>{{
                            personEthnicity(selectedHouseholdPerson)
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Tôn giáo</span>
                        <strong>{{
                            displayFormValue(selectedHouseholdPerson.religion)
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Diện ưu tiên</span>
                        <strong>{{
                            displayFormValue(
                                selectedHouseholdPerson.priority_type,
                            )
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Họ tên cha / mẹ</span>
                        <strong>{{
                            displayFormValue(
                                selectedHouseholdPerson.parent_name,
                            )
                        }}</strong>
                    </div>
                </section>
                <section class="household-member-form-section">
                    <h4>Thông tin liên lạc</h4>
                    <div class="household-member-readonly-field">
                        <span>Quan hệ với chủ hộ</span>
                        <strong>{{
                            displayFormValue(
                                selectedHouseholdPerson.relationship_with_head,
                            )
                        }}</strong>
                    </div>
                    <div class="household-member-readonly-field">
                        <span>Số điện thoại</span>
                        <strong>{{
                            displayFormValue(selectedHouseholdPerson.phone)
                        }}</strong>
                    </div>
                    <div
                        class="household-member-readonly-field household-member-wide-field"
                    >
                        <span>Ghi chú</span>
                        <strong>{{
                            displayFormValue(selectedHouseholdPerson.note)
                        }}</strong>
                    </div>
                </section>
                <section
                    class="household-member-form-section household-member-readonly-education"
                >
                    <h4>Thông tin học tập</h4>
                    <article
                        v-for="education in personEducations(
                            selectedHouseholdPerson,
                        )"
                        :key="String(education.id ?? education.school_year)"
                        class="household-member-readonly-year"
                    >
                        <h5>{{ displayFormValue(education.school_year) }}</h5>
                        <div class="household-member-readonly-grid">
                            <div class="household-member-readonly-field">
                                <span>Trường học</span>
                                <strong>{{
                                    displayFormValue(
                                        pathValue(education, "school.name"),
                                    )
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Khối học</span>
                                <strong>{{
                                    displayFormValue(education.academic_block)
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Lớp hiện tại</span>
                                <strong>{{
                                    displayFormValue(education.current_class)
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Trình độ phổ cập</span>
                                <strong>{{
                                    displayFormValue(
                                        educationGraduationLabel(education) ||
                                            education.graduation_level,
                                    )
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Học bổ túc</span>
                                <strong>{{
                                    displayFormBoolean(
                                        education.is_complementary,
                                    )
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Năm tốt nghiệp</span>
                                <strong>{{
                                    displayFormValue(education.graduation_year)
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Trình độ tốt nghiệp nghề</span>
                                <strong>{{
                                    displayFormValue(
                                        education.vocational_grad_level,
                                    )
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Năm tốt nghiệp nghề</span>
                                <strong>{{
                                    displayFormValue(
                                        education.vocational_grad_year,
                                    )
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Lớp đã hoàn thành</span>
                                <strong>{{
                                    displayFormValue(education.finished_class)
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Năm hoàn thành lớp</span>
                                <strong>{{
                                    displayFormValue(education.finished_year)
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Lớp đã bỏ học</span>
                                <strong>{{
                                    displayFormValue(education.dropped_class)
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Năm bỏ học</span>
                                <strong>{{
                                    displayFormValue(education.dropped_year)
                                }}</strong>
                            </div>
                        </div>
                    </article>
                    <p
                        v-if="!personEducations(selectedHouseholdPerson).length"
                        class="household-member-readonly-empty"
                    >
                        Chưa cập nhật thông tin học tập.
                    </p>
                </section>
                <section
                    class="household-member-form-section household-member-readonly-literacy"
                >
                    <h4>Xóa mù chữ</h4>
                    <article
                        v-for="education in personEducations(
                            selectedHouseholdPerson,
                        )"
                        :key="`literacy-${String(education.id ?? education.school_year)}`"
                        class="household-member-readonly-year"
                    >
                        <h5>{{ displayFormValue(education.school_year) }}</h5>
                        <div class="household-member-readonly-grid">
                            <div class="household-member-readonly-field">
                                <span>Đang học lớp</span>
                                <strong>{{
                                    displayFormValue(
                                        education.literacy_current_class,
                                    )
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Lớp XMC đã hoàn thành</span>
                                <strong>{{
                                    displayFormValue(
                                        education.literacy_completed_class ??
                                            education.completed_class,
                                    )
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Mức độ tái mù chữ</span>
                                <strong>{{
                                    education.literacy_relapse_level
                                        ? `Mức độ ${String(education.literacy_relapse_level)}`
                                        : "Chưa cập nhật"
                                }}</strong>
                            </div>
                            <div class="household-member-readonly-field">
                                <span>Khả năng học tập</span>
                                <strong>{{
                                    displayFormValue(
                                        education.learning_capacity,
                                    )
                                }}</strong>
                            </div>
                        </div>
                    </article>
                    <p
                        v-if="!personEducations(selectedHouseholdPerson).length"
                        class="household-member-readonly-empty"
                    >
                        Chưa cập nhật thông tin xóa mù chữ.
                    </p>
                </section>
                <section
                    class="household-member-form-section household-member-readonly-disability"
                >
                    <h4>Khuyết tật</h4>
                    <div class="household-member-readonly-grid">
                        <div
                            v-for="option in memberDisabilityOptions"
                            :key="option.key"
                            class="household-member-readonly-field"
                        >
                            <span>{{ option.label }}</span>
                            <strong>{{
                                displayFormBoolean(
                                    asRow(selectedHouseholdPerson.disability)[
                                        option.key
                                    ],
                                )
                            }}</strong>
                        </div>
                        <div class="household-member-readonly-field">
                            <span>Khả năng học tập</span>
                            <strong>{{
                                displayFormBoolean(
                                    asRow(selectedHouseholdPerson.disability)
                                        .can_study,
                                )
                            }}</strong>
                        </div>
                    </div>
                </section>
                <section
                    class="household-member-form-section household-member-readonly-circumstance"
                >
                    <h4>Hoàn cảnh</h4>
                    <div class="household-member-readonly-grid">
                        <div class="household-member-readonly-field">
                            <span>Hoàn cảnh đặc biệt</span>
                            <strong>{{
                                displayFormValue(
                                    asRow(selectedHouseholdPerson.disability)
                                        .special_circumstance,
                                )
                            }}</strong>
                        </div>
                        <div
                            class="household-member-readonly-field household-member-wide-field"
                        >
                            <span>Chi tiết hoàn cảnh</span>
                            <strong>{{
                                displayFormValue(
                                    asRow(selectedHouseholdPerson.disability)
                                        .special_circumstance_detail,
                                )
                            }}</strong>
                        </div>
                    </div>
                </section>
                <footer>
                    <button
                        class="secondary-button"
                        type="button"
                        @click="closeHouseholdPersonDetail"
                    >
                        Đóng
                    </button>
                </footer>
            </section>
        </div>
        <p
            v-if="
                moduleKey !== 'households' &&
                !['schools', 'villages'].includes(moduleKey) &&
                ['roles', 'users', 'trash'].includes(moduleKey) &&
                !rows.length &&
                !busy
            "
            class="empty-state"
        >
            Không có dữ liệu.
        </p>
        <div
            v-if="busy"
            class="workspace-loading-overlay"
            role="status"
            aria-live="polite"
        >
            <span class="workspace-loading-indicator" aria-hidden="true"></span>
            <span>Đang xử lý, vui lòng chờ…</span>
        </div>
    </section>

    <div
        v-if="pendingDelete"
        class="workspace-modal-backdrop"
        role="presentation"
        @click.self="pendingDelete = null"
        @keydown.esc="pendingDelete = null"
    >
        <section
            class="confirm-dialog"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="delete-confirm-title"
            aria-describedby="delete-confirm-message"
            tabindex="-1"
        >
            <span class="confirm-dialog-icon" aria-hidden="true">!</span>
            <h3 id="delete-confirm-title">
                {{
                    moduleKey === "imports"
                        ? "Xác nhận xóa lịch sử import"
                        : "Xác nhận xóa"
                }}
            </h3>
            <p id="delete-confirm-message">
                {{
                    moduleKey === "imports"
                        ? `Bạn có chắc chắn muốn xóa lịch sử của tệp “${displayImportFileName(pendingDelete.file_name)}”? Tệp gốc và báo cáo lỗi cũng sẽ bị xóa.`
                        : `Bạn có chắc chắn muốn xóa “${pendingDelete.name ?? pendingDelete.code ?? "mục đã chọn"}”? Thao tác này không thể hoàn tác.`
                }}
            </p>
            <div class="confirm-dialog-actions">
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    @click="pendingDelete = null"
                >
                    Hủy
                </button>
                <button
                    class="confirm-delete-button"
                    type="button"
                    :disabled="busy"
                    @click="confirmRemove"
                >
                    {{
                        busy
                            ? "Đang xóa…"
                            : moduleKey === "imports"
                              ? "Xóa lịch sử"
                              : "Xóa dữ liệu"
                    }}
                </button>
            </div>
        </section>
    </div>
    <div
        v-if="pendingHouseholdBulkDelete"
        class="workspace-modal-backdrop"
        role="presentation"
        @click.self="pendingHouseholdBulkDelete = false"
        @keydown.esc="pendingHouseholdBulkDelete = false"
    >
        <section
            class="confirm-dialog"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="bulk-household-delete-title"
            aria-describedby="bulk-household-delete-message"
            tabindex="-1"
        >
            <span class="confirm-dialog-icon" aria-hidden="true">!</span>
            <h3 id="bulk-household-delete-title">Xóa nhiều phiếu điều tra</h3>
            <p id="bulk-household-delete-message">
                Bạn đã chọn {{ selectedHouseholdIds.length }} phiếu. Bạn có chắc
                chắn muốn xóa các phiếu này? Thao tác có thể khôi phục trong
                thùng rác.
            </p>
            <div class="confirm-dialog-actions">
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="pendingHouseholdBulkDelete = false"
                >
                    Hủy
                </button>
                <button
                    class="confirm-delete-button"
                    type="button"
                    :disabled="busy"
                    @click="confirmBulkHouseholdDelete"
                >
                    {{
                        busy
                            ? "Đang xóa…"
                            : `Xóa ${selectedHouseholdIds.length} phiếu`
                    }}
                </button>
            </div>
        </section>
    </div>
    <div
        v-if="deleteResult"
        class="workspace-modal-backdrop"
        role="presentation"
        @click.self="deleteResult = null"
        @keydown.esc="deleteResult = null"
    >
        <section
            class="confirm-dialog account-action-dialog"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="delete-result-title"
            aria-describedby="delete-result-message"
            tabindex="-1"
        >
            <span
                class="confirm-dialog-icon"
                :class="{ 'account-action-success-icon': deleteResult.success }"
                aria-hidden="true"
            >
                {{ deleteResult.success ? "✓" : "!" }}
            </span>
            <h3 id="delete-result-title">
                {{
                    deleteResult.title ??
                    (deleteResult.success ? "Xóa thành công" : "Xóa thất bại")
                }}
            </h3>
            <p id="delete-result-message">{{ deleteResult.message }}</p>
            <div class="confirm-dialog-actions">
                <button
                    class="primary-button management-tool-button"
                    type="button"
                    autofocus
                    @click="deleteResult = null"
                >
                    Đóng
                </button>
            </div>
        </section>
    </div>
    <div
        v-if="trashActionDialog"
        class="workspace-modal-backdrop"
        role="presentation"
        @click.self="!busy && (trashActionDialog = null)"
        @keydown.esc="!busy && (trashActionDialog = null)"
    >
        <section
            class="confirm-dialog"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="trash-action-title"
            aria-describedby="trash-action-message"
            tabindex="-1"
        >
            <span
                class="confirm-dialog-icon"
                :class="{
                    'account-action-failure-icon':
                        trashActionDialog.operation === 'force_delete',
                }"
                aria-hidden="true"
            >
                {{ trashActionDialog.operation === "restore" ? "↻" : "!" }}
            </span>
            <h3 id="trash-action-title">
                {{
                    trashActionDialog.operation === "restore"
                        ? "Khôi phục dữ liệu"
                        : "Xóa dữ liệu vĩnh viễn"
                }}
            </h3>
            <p id="trash-action-message">
                {{
                    trashActionDialog.row
                        ? trashActionDialog.operation === "restore"
                            ? "Bạn có chắc chắn muốn khôi phục mục đã chọn? Dữ liệu sẽ trở lại danh sách quản lý."
                            : "Bạn có chắc chắn muốn xóa vĩnh viễn mục đã chọn? Dữ liệu sẽ không thể khôi phục lại."
                        : trashActionDialog.operation === "restore"
                          ? `Bạn có chắc chắn muốn khôi phục ${selectedTrashKeys.length} mục đã chọn?`
                          : `Bạn có chắc chắn muốn xóa vĩnh viễn ${selectedTrashKeys.length} mục đã chọn? Dữ liệu sẽ không thể khôi phục lại.`
                }}
            </p>
            <div class="confirm-dialog-actions">
                <button
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="trashActionDialog = null"
                >
                    Hủy
                </button>
                <button
                    :class="
                        trashActionDialog.operation === 'force_delete'
                            ? 'confirm-delete-button'
                            : 'primary-button management-tool-button'
                    "
                    type="button"
                    :disabled="busy"
                    @click="confirmTrashAction"
                >
                    {{
                        busy
                            ? "Đang xử lý…"
                            : trashActionDialog.operation === "restore"
                              ? "Khôi phục"
                              : "Xóa vĩnh viễn"
                    }}
                </button>
            </div>
        </section>
    </div>
    <div
        v-if="
            moduleKey === 'users' &&
            (userDetail || userDetailLoading || userDetailError)
        "
        class="workspace-modal-backdrop user-detail-backdrop"
        role="presentation"
        @click.self="closeUserDetail"
        @keydown.esc="closeUserDetail"
    >
        <section
            class="workspace-modal-card user-detail-card"
            role="dialog"
            aria-modal="true"
            aria-labelledby="user-detail-title"
        >
            <header class="user-detail-header">
                <div>
                    <p class="workspace-eyebrow">THÔNG TIN TÀI KHOẢN</p>
                    <h2 id="user-detail-title">
                        {{
                            userDetail?.name ??
                            userDetailRow?.name ??
                            "Chi tiết tài khoản"
                        }}
                    </h2>
                </div>
                <button
                    class="household-detail-icon-button"
                    type="button"
                    aria-label="Đóng chi tiết tài khoản"
                    title="Đóng"
                    @click="closeUserDetail"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m6 6 12 12M18 6 6 18" />
                    </svg>
                </button>
            </header>
            <p
                v-if="userDetailLoading"
                class="user-detail-loading"
                role="status"
            >
                Đang tải thông tin tài khoản…
            </p>
            <p
                v-else-if="userDetailError"
                class="notice login-error"
                role="alert"
            >
                {{ userDetailError }}
            </p>
            <template v-else-if="userDetail">
                <dl class="user-detail-grid">
                    <div>
                        <dt>Tên đăng nhập</dt>
                        <dd>{{ userDetail.username || "—" }}</dd>
                    </div>
                    <div>
                        <dt>Số điện thoại</dt>
                        <dd>{{ userDetail.phone || "—" }}</dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd>{{ userDetail.email || "—" }}</dd>
                    </div>
                    <div>
                        <dt>Số CCCD</dt>
                        <dd>{{ userDetail.citizen_id || "—" }}</dd>
                    </div>
                    <div>
                        <dt>Ngày sinh</dt>
                        <dd>{{ userDetail.date_of_birth || "—" }}</dd>
                    </div>
                    <div>
                        <dt>Trạng thái</dt>
                        <dd>
                            {{
                                userDetail.is_active
                                    ? "Đang hoạt động"
                                    : "Đã vô hiệu hóa"
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Cấp quản lý</dt>
                        <dd>
                            {{
                                scopeLabels[String(userDetail.scope_level)] ||
                                "—"
                            }}
                        </dd>
                    </div>
                    <div v-if="userDetail.province">
                        <dt>Tỉnh / thành phố</dt>
                        <dd>{{ asRow(userDetail.province).name }}</dd>
                    </div>
                    <div v-if="userDetail.commune">
                        <dt>Xã / phường</dt>
                        <dd>{{ asRow(userDetail.commune).name }}</dd>
                    </div>
                    <div v-if="asRows(userDetail.villages).length">
                        <dt>Thôn / xóm</dt>
                        <dd>
                            {{
                                asRows(userDetail.villages)
                                    .map((village) =>
                                        String(village.name ?? ""),
                                    )
                                    .filter(Boolean)
                                    .join(", ")
                            }}
                        </dd>
                    </div>
                    <div class="user-detail-roles">
                        <dt>Nhóm quyền</dt>
                        <dd>
                            {{
                                asRows(userDetail.roles)
                                    .map((role) => String(role.name ?? ""))
                                    .filter(Boolean)
                                    .join(", ") || "Chưa được gán nhóm"
                            }}
                        </dd>
                    </div>
                </dl>
                <div class="user-detail-actions">
                    <button
                        class="secondary-button management-tool-button"
                        type="button"
                        @click="closeUserDetail"
                    >
                        Đóng
                    </button>
                    <button
                        v-if="userDetailRow?.can_edit_configuration"
                        class="primary-button management-tool-button"
                        type="button"
                        @click="editUserDetail"
                    >
                        Sửa tài khoản
                    </button>
                </div>
            </template>
        </section>
    </div>
    <div
        v-if="accountActionDialog"
        class="workspace-modal-backdrop"
        role="presentation"
        @click.self="closeAccountAction"
        @keydown.esc="closeAccountAction"
    >
        <section
            class="confirm-dialog account-action-dialog"
            :role="
                accountActionDialog.mode === 'password-form'
                    ? 'dialog'
                    : 'alertdialog'
            "
            aria-modal="true"
            aria-labelledby="account-action-title"
            aria-describedby="account-action-message"
            tabindex="-1"
        >
            <span
                class="confirm-dialog-icon"
                :class="{
                    'account-action-success-icon':
                        accountActionDialog.mode === 'result' &&
                        accountActionDialog.success,
                }"
                aria-hidden="true"
            >
                {{
                    accountActionDialog.mode === "result" &&
                    accountActionDialog.success
                        ? "✓"
                        : "!"
                }}
            </span>
            <h3 id="account-action-title">
                {{ accountActionTitle(accountActionDialog) }}
            </h3>
            <p id="account-action-message">
                <template v-if="accountActionDialog.mode === 'confirm'">
                    Bạn có chắc muốn
                    {{
                        accountActionDialog.action === "password"
                            ? "đặt lại mật khẩu"
                            : accountActionDialog.row.is_active
                              ? "vô hiệu hóa"
                              : "kích hoạt"
                    }}
                    tài khoản
                    <strong>“{{ accountActionDialog.accountName }}”</strong>?
                </template>
                <template
                    v-else-if="accountActionDialog.mode === 'password-form'"
                >
                    Nhập mật khẩu mới cho tài khoản
                    <strong>“{{ accountActionDialog.accountName }}”</strong>,
                    hoặc tự động điền mật khẩu an toàn.
                </template>
                <template v-else>
                    {{ accountActionMessage(accountActionDialog) }}
                </template>
            </p>
            <div
                v-if="accountActionDialog.mode === 'password-form'"
                class="account-password-reset-fields"
            >
                <label>
                    <span>Mật khẩu mới</span>
                    <div class="account-password-reset-input">
                        <input
                            v-model="accountPassword"
                            :type="showAccountPassword ? 'text' : 'password'"
                            minlength="12"
                            maxlength="18"
                            autocomplete="new-password"
                            aria-label="Mật khẩu mới"
                            @input="accountPasswordError = ''"
                        />
                        <button
                            class="password-visibility-button"
                            type="button"
                            @click="showAccountPassword = !showAccountPassword"
                        >
                            {{ showAccountPassword ? "Ẩn" : "Hiện" }}
                        </button>
                    </div>
                </label>
                <label>
                    <span>Xác nhận mật khẩu mới</span>
                    <div class="account-password-reset-input">
                        <input
                            v-model="accountPasswordConfirmation"
                            :type="
                                showAccountPasswordConfirmation
                                    ? 'text'
                                    : 'password'
                            "
                            minlength="12"
                            maxlength="18"
                            autocomplete="new-password"
                            aria-label="Xác nhận mật khẩu mới"
                            @input="accountPasswordError = ''"
                        />
                        <button
                            class="password-visibility-button"
                            type="button"
                            @click="
                                showAccountPasswordConfirmation =
                                    !showAccountPasswordConfirmation
                            "
                        >
                            {{
                                showAccountPasswordConfirmation ? "Ẩn" : "Hiện"
                            }}
                        </button>
                    </div>
                </label>
                <button
                    class="secondary-button management-tool-button account-password-generate-button"
                    type="button"
                    @click="generateAccountPassword"
                >
                    Tự động điền mật khẩu
                </button>
                <p
                    v-if="accountPasswordError"
                    class="confirm-dialog-error"
                    role="alert"
                >
                    {{ accountPasswordError }}
                </p>
            </div>
            <div class="confirm-dialog-actions">
                <template v-if="accountActionDialog.mode === 'password-form'">
                    <button
                        class="secondary-button management-tool-button"
                        type="button"
                        :disabled="busy"
                        @click="closeAccountAction"
                    >
                        Hủy
                    </button>
                    <button
                        class="primary-button management-tool-button account-action-confirm-button"
                        type="button"
                        :disabled="busy"
                        @click="confirmAccountAction"
                    >
                        {{ busy ? "Đang lưu…" : "Lưu" }}
                    </button>
                </template>
                <template v-else-if="accountActionDialog.mode === 'confirm'">
                    <button
                        class="secondary-button management-tool-button"
                        type="button"
                        :disabled="busy"
                        @click="closeAccountAction"
                    >
                        Hủy
                    </button>
                    <button
                        class="confirm-delete-button"
                        :class="{
                            'account-action-confirm-button':
                                !accountActionDialog.row.is_active,
                        }"
                        type="button"
                        :disabled="busy"
                        @click="confirmAccountAction"
                    >
                        {{
                            busy
                                ? "Đang xử lý…"
                                : accountActionDialog.row.is_active
                                  ? "Vô hiệu hóa"
                                  : "Kích hoạt"
                        }}
                    </button>
                </template>
                <button
                    v-else-if="accountActionDialog.mode === 'result'"
                    class="primary-button management-tool-button"
                    type="button"
                    autofocus
                    @click="closeAccountAction"
                >
                    Đóng
                </button>
            </div>
        </section>
    </div>
    <div
        v-if="managementResult"
        class="workspace-modal-backdrop account-creation-result-backdrop"
        role="presentation"
        @click.self="managementResult = null"
        @keydown.esc="managementResult = null"
    >
        <section
            class="confirm-dialog account-creation-result-dialog"
            :class="
                managementResult.success
                    ? 'account-creation-success-dialog'
                    : 'account-creation-failure-dialog'
            "
            role="dialog"
            aria-modal="true"
            aria-labelledby="account-creation-result-title"
            aria-describedby="account-creation-result-message"
            tabindex="-1"
        >
            <span
                class="confirm-dialog-icon account-creation-result-icon"
                :class="
                    managementResult.success
                        ? 'account-creation-success-icon'
                        : 'account-action-failure-icon'
                "
                aria-hidden="true"
                >{{ managementResult.success ? "✓" : "!" }}</span
            >
            <p class="account-creation-result-eyebrow">
                {{ managementResult.success ? "HOÀN TẤT" : "CÓ LỖI" }}
            </p>
            <h3 id="account-creation-result-title">
                {{ managementResult.title }}
            </h3>
            <p id="account-creation-result-message">
                {{ managementResult.message }}
            </p>
            <span
                v-if="managementResult.username"
                class="account-creation-result-username"
            >
                {{ managementResult.username }}
            </span>
            <div class="confirm-dialog-actions">
                <button
                    class="primary-button management-tool-button"
                    type="button"
                    autofocus
                    @click="managementResult = null"
                >
                    Đóng
                </button>
            </div>
        </section>
    </div>
    <div
        v-if="householdDatePicker"
        class="household-date-picker-backdrop"
        @click.self="closeHouseholdDatePicker"
        @keydown.esc="closeHouseholdDatePicker"
    >
        <section
            class="household-date-picker"
            :style="{
                left: `${householdDatePicker.left}px`,
                top: `${householdDatePicker.top}px`,
            }"
            role="dialog"
            aria-label="Chọn ngày sinh"
        >
            <header class="household-date-picker-heading">
                <button
                    class="household-date-picker-month-button"
                    type="button"
                    aria-label="Tháng trước"
                    :disabled="
                        householdDatePicker.year === 1900 &&
                        householdDatePicker.month === 0
                    "
                    @click="shiftHouseholdDatePickerMonth(-1)"
                >
                    ‹
                </button>
                <select
                    v-model.number="householdDatePicker.month"
                    aria-label="Tháng"
                >
                    <option
                        v-for="(month, index) in householdDatePickerMonths"
                        :key="month"
                        :value="index"
                    >
                        {{ month }}
                    </option>
                </select>
                <select
                    v-model.number="householdDatePicker.year"
                    aria-label="Năm"
                >
                    <option
                        v-for="year in householdDatePickerYears"
                        :key="year"
                        :value="year"
                    >
                        {{ year }}
                    </option>
                </select>
                <button
                    class="household-date-picker-month-button"
                    type="button"
                    aria-label="Tháng sau"
                    :disabled="
                        householdDatePicker.year === new Date().getFullYear() &&
                        householdDatePicker.month === new Date().getMonth()
                    "
                    @click="shiftHouseholdDatePickerMonth(1)"
                >
                    ›
                </button>
            </header>
            <div class="household-date-picker-grid">
                <span
                    v-for="weekday in householdDatePickerWeekdays"
                    :key="weekday"
                    class="household-date-picker-weekday"
                >
                    {{ weekday }}
                </span>
                <template
                    v-for="(day, index) in householdDatePickerDays"
                    :key="`date-day-${index}`"
                >
                    <span
                        v-if="day === null"
                        class="household-date-picker-empty"
                    />
                    <button
                        v-else
                        class="household-date-picker-day"
                        :class="{
                            'is-selected':
                                householdDatePickerValue() ===
                                `${householdDatePicker.year}-${String(householdDatePicker.month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
                        }"
                        type="button"
                        :disabled="isHouseholdDatePickerDayFuture(day)"
                        @click="selectHouseholdDate(day)"
                    >
                        {{ day }}
                    </button>
                </template>
            </div>
            <footer class="household-date-picker-footer">
                <button
                    class="household-date-picker-today"
                    type="button"
                    @click="selectHouseholdDate(new Date().getDate())"
                    :disabled="
                        householdDatePicker.year !== new Date().getFullYear() ||
                        householdDatePicker.month !== new Date().getMonth()
                    "
                >
                    Hôm nay
                </button>
                <button
                    class="household-date-picker-close"
                    type="button"
                    @click="closeHouseholdDatePicker"
                >
                    Đóng
                </button>
            </footer>
        </section>
    </div>
</template>
