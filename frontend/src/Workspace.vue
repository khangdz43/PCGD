<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, apiData, apiPage, apiRows, errorMessage, validationErrors } from './api';

type ModuleKey = 'households' | 'schools' | 'villages' | 'roles' | 'users' | 'account' | 'imports' | 'trash' | 'merge';
type Row = Record<string, unknown>;
type AccountAction = 'status' | 'password';
type AccountActionDialog = {
    mode: 'confirm' | 'result';
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
    operation: 'restore' | 'force_delete';
    row?: Row;
};

const props = defineProps<{
    moduleKey: ModuleKey;
    permissions: string[];
    householdFilters?: { province_code: string; commune_code: string; village_code: string };
    villageFilters?: { province_code: string; commune_code: string; q: string };
}>();
const emit = defineEmits<{
    close: [];
    navigate: [module: 'imports' | 'exports'];
    villageOptions: [data: { provinces?: unknown; province_code?: string; commune_code?: string }];
}>();

const busy = ref(false);
const error = ref('');
const notice = ref('');
const configurationFormOpen = ref(false);
const formErrors = ref<Record<string, string>>({});
const initialVillageFormState = ref<string | null>(null);
const initialSchoolFormState = ref<string | null>(null);
const initialHouseholdFormState = ref<string | null>(null);
const rows = ref<Row[]>([]);
const selected = ref<Row | null>(null);
const selectedRolePermissions = ref<Row | null>(null);
const pendingDelete = ref<Row | null>(null);
const pendingHouseholdBulkDelete = ref(false);
const selectedHouseholdIds = ref<number[]>([]);
const selectedTrashKeys = ref<string[]>([]);
const trashFilterType = ref('household');
const trashFilterQuery = ref('');
const trashPageSize = ref(10);
const deleteResult = ref<DeleteResult | null>(null);
const trashActionDialog = ref<TrashActionDialog | null>(null);
const accountActionDialog = ref<AccountActionDialog | null>(null);
const managementResult = ref<{ success: boolean; title: string; message: string; username?: string } | null>(null);
const userFormRequestError = ref('');
const roleFormRequestError = ref('');
const form = ref<Record<string, string>>({});
const showUserPassword = ref(false);
const showUserPasswordConfirmation = ref(false);
const permissionIds = ref<string[]>([]);
const initialRoleFormState = ref<string | null>(null);
const permissionOptions = ref<Row[]>([]);
const roleScopeLevels = ref<string[]>(['global', 'province', 'commune', 'village']);
const accountLevels = ref<string[]>([]);
const roleOptions = ref<Row[]>([]);
const availablePrimaryAdminScopes = ref<Record<string, boolean>>({});
const formOptionsBusy = ref(false);
const provinces = ref<Row[]>([]);
const formCommunes = ref<Row[]>([]);
const formVillages = ref<Row[]>([]);
const communesCache = new Map<string, Row[]>();
const villagesCache = new Map<string, Row[]>();
const schoolFilterCommunes = ref<Row[]>([]);
const schoolFilterProvince = ref('');
const schoolFilterCommune = ref('');
const schoolFilterLevel = ref('');
const schoolFilterQuery = ref('');
const appliedSchoolFilters = ref({ province_code: '', commune_code: '', level: '', q: '' });
const userFilterCommunes = ref<Row[]>([]);
const userFilterVillages = ref<Row[]>([]);
const userFilterProvince = ref('');
const userFilterCommune = ref('');
const userFilterVillage = ref('');
const userFilterRole = ref('');
const userFilterQuery = ref('');
const userFilterRoles = ref<Row[]>([]);
const userFiltersInitialized = ref(false);
const currentActorScopeLevel = ref<string | null>(null);
const canEditAccountConfigurations = ref(false);
const password = ref('');
const passwordConfirmation = ref('');
const importFile = ref<File | null>(null);
const importHistory = ref<Row[]>([]);
const profile = ref<Row | null>(null);
const selectedSourceCodes = ref<string[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const totalRecords = ref(0);
const userPageSize = ref(10);
const villagePageSize = ref(10);
const schoolPageSize = ref(10);
const householdPageSize = ref(10);
const householdDetail = ref<Row | null>(null);
const householdDetailReadOnly = ref(false);
const householdListRow = ref<Row | null>(null);
const householdEditPersons = ref<Row[]>([]);
const inlineHouseholdPersonBaseline = ref<Record<string, string>>({});
const householdLoading = ref(false);
const selectedHouseholdPerson = ref<Row | null>(null);
const pendingHouseholdPersonDelete = ref<Row | null>(null);
const memberBusy = ref(false);
const memberFormErrors = ref<Record<string, string>>({});
const householdQuery = ref('');
const householdSchoolYear = ref('');
const householdFormSchoolYears = ref<string[]>([]);
const personSchools = ref<Row[]>([]);
const disabilityForm = ref<Record<string, string | boolean>>({});
const householdSchoolYearOptions = Array.from({ length: 11 }, (_, index) => {
    const year = 2020 + index;
    return `${year}-${year + 1}`;
});
const memberSchoolYearOptions = Array.from({ length: Math.max(1, new Date().getFullYear() - 2019) }, (_, index) => {
    const year = 2020 + index;
    return `${year}-${year + 1}`;
});
const memberRelationships = ['Vợ', 'Chồng', 'Con', 'Cha', 'Mẹ', 'Anh', 'Chị', 'Em', 'Ông', 'Bà', 'Cháu', 'Khác'];
const memberDisabilityOptions = [
    { key: 'mobility_disability', label: 'Khuyết tật vận động' },
    { key: 'hearing_speech_disability', label: 'Khuyết tật nghe / nói' },
    { key: 'visual_disability', label: 'Khuyết tật thị giác' },
    { key: 'mental_disability', label: 'Khuyết tật tâm thần' },
    { key: 'intellectual_disability', label: 'Khuyết tật trí tuệ' },
    { key: 'learning_disability', label: 'Khuyết tật học tập' },
    { key: 'autism', label: 'Tự kỷ' },
    { key: 'other_disability', label: 'Khuyết tật khác' },
    { key: 'has_disability_cert', label: 'Có giấy xác nhận khuyết tật' },
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

const moduleInfo: Record<ModuleKey, { title: string; endpoint: string; columns: string[]; fields: string[] }> = {
    households: {
        title: 'Phiếu điều tra',
        endpoint: '/households',
        columns: ['household_code', 'head_name', 'province_name', 'commune_name', 'village_name', 'members_count'],
        fields: ['household_code', 'school_year', 'head_last_name', 'head_first_name', 'province_code', 'commune_code', 'village_code', 'address', 'residence_type', 'residence_status'],
    },
    schools: {
        title: 'Trường học',
        endpoint: '/schools',
        columns: ['code', 'name', 'level', 'province.name', 'commune.name'],
        fields: ['name', 'level', 'province_code', 'commune_code'],
    },
    villages: {
        title: 'Danh mục Thôn / Xóm / Tổ dân phố',
        endpoint: '/villages',
        columns: ['code', 'name'],
        fields: ['name', 'province_code', 'commune_code'],
    },
    roles: {
        title: 'Nhóm người dùng',
        endpoint: '/roles',
        columns: ['name', 'description', 'scope_level', 'users_count'],
        fields: ['name', 'description', 'scope_level'],
    },
    users: {
        title: 'Tài khoản',
        endpoint: '/users',
        columns: ['name', 'username', 'phone', 'role_name', 'scope_level'],
        fields: ['name', 'username', 'phone', 'scope_level', 'role_id', 'province_code', 'commune_code', 'village_codes'],
    },
    account: { title: 'Tài khoản của tôi', endpoint: '/account', columns: [], fields: [] },
    imports: { title: 'Nhập / xuất Excel', endpoint: '/household-imports', columns: [], fields: [] },
    trash: { title: 'Thùng rác', endpoint: '/trash', columns: ['item_type', 'deleted_at', 'record.household_code'], fields: [] },
    merge: { title: 'Gộp thôn / xóm', endpoint: '/village-merges', columns: [], fields: ['target_code', 'source_codes'] },
};

const info = computed(() => moduleInfo[props.moduleKey]);
const editing = computed(() => selected.value !== null);
const isCompactManagement = computed(() => ['users', 'roles', 'trash'].includes(props.moduleKey));
const isDialogForm = computed(() => ['users', 'roles', 'villages', 'schools', 'households'].includes(props.moduleKey));
const showUserProvinceFilter = computed(() => currentActorScopeLevel.value === 'global');
const showUserCommuneFilter = computed(() => currentActorScopeLevel.value === 'province');
const showUserVillageFilter = computed(() => currentActorScopeLevel.value === 'commune');
const hasConfigurationForm = computed(() => isDialogForm.value
    ? configurationFormOpen.value
    : selected.value !== null
        || ['households', 'schools', 'villages', 'merge'].includes(props.moduleKey) && Object.keys(form.value).length > 0);
const fieldsForForm = computed(() => info.value.fields);
const assignableUserRoles = computed(() => {
    const level = form.value.scope_level;
    if (!level) return [];

    return roleOptions.value.filter(role => Array.isArray(role.assignable_scope_levels)
        && role.assignable_scope_levels.includes(level));
});
const primaryAdminUnavailableMessage = computed(() => {
    if (props.moduleKey !== 'users' || !form.value.scope_level) return '';

    const hasPrimaryAdminRole = assignableUserRoles.value.some(role =>
        role.is_primary_admin === true && role.scope_level === form.value.scope_level
    );
    if (!hasPrimaryAdminRole || availablePrimaryAdminScopes.value[form.value.scope_level] !== false) return '';

    return form.value.scope_level === 'province'
        ? 'Đã đủ người quản trị tỉnh; không thể chọn thêm nhóm quyền Quản trị tỉnh.'
        : 'Đã đủ người quản trị xã; không thể chọn thêm nhóm quyền Quản trị xã.';
});
const userFormValidationErrors = computed<Record<string, string>>(() => {
    if (props.moduleKey !== 'users') return {};

    const errors: Record<string, string> = {};
    const requiredFields: Record<string, string> = {
        name: 'Vui lòng nhập họ và tên.',
        username: 'Vui lòng nhập tên đăng nhập.',
        role_id: 'Vui lòng chọn nhóm quyền.',
        scope_level: 'Vui lòng chọn cấp quản lý.',
    };
    for (const [field, message] of Object.entries(requiredFields)) {
        if (!form.value[field]?.trim()) errors[field] = message;
    }

    if (form.value.scope_level !== 'global' && !form.value.province_code) {
        errors.province_code = 'Vui lòng chọn tỉnh/thành phố.';
    }
    if (['commune', 'village'].includes(form.value.scope_level) && !form.value.commune_code) {
        errors.commune_code = 'Vui lòng chọn xã/phường.';
    }
    if (form.value.scope_level === 'village' && !form.value.village_codes?.trim()) {
        errors.village_codes = 'Vui lòng chọn ít nhất một thôn/xóm.';
    }

    const passwordValue = form.value.password ?? '';
    if (!editing.value && !passwordValue) {
        errors.password = 'Vui lòng nhập mật khẩu.';
    } else if (passwordValue && passwordValue.length < 12) {
        errors.password = 'Mật khẩu phải có ít nhất 12 ký tự.';
    }
    if ((!editing.value || passwordValue)
        && passwordValue !== (form.value.password_confirmation ?? '')) {
        errors.password_confirmation = form.value.password_confirmation
            ? 'Mật khẩu xác nhận chưa khớp.'
            : 'Vui lòng nhập mật khẩu xác nhận.';
    }

    if (form.value.role_id
        && !assignableUserRoles.value.some(role => String(role.id) === form.value.role_id)) {
        errors.role_id = 'Nhóm quyền không phù hợp với cấp quản lý đã chọn.';
    }

    if (form.value.role_id) {
        const selectedRole = assignableUserRoles.value.find(role => String(role.id) === form.value.role_id);
        if (selectedRole?.is_primary_admin === true
            && availablePrimaryAdminScopes.value[form.value.scope_level] === false) {
            errors.role_id = primaryAdminUnavailableMessage.value;
        }
    }

    return errors;
});
const userFormReady = computed(() => props.moduleKey !== 'users'
    || !formOptionsBusy.value && Object.keys(userFormValidationErrors.value).length === 0);
const villageFormReady = computed(() => props.moduleKey !== 'villages'
    || Boolean(form.value.name?.trim() && form.value.province_code && form.value.commune_code));
const schoolFormReady = computed(() => props.moduleKey !== 'schools' || Boolean(form.value.name?.trim()));
const userFormValidationMessages = computed(() => Object.entries(userFormValidationErrors.value)
    .map(([field, message]) => `${fieldLabel(field)}: ${message}`));
const roleFormValidationErrors = computed<Record<string, string>>(() => {
    if (props.moduleKey !== 'roles') return {};

    const errors: Record<string, string> = {};
    if (!form.value.name?.trim()) errors.name = 'Vui lòng nhập tên nhóm người dùng.';
    if (!form.value.scope_level) errors.scope_level = 'Vui lòng chọn cấp quản lý.';
    if (!editing.value && permissionIds.value.length === 0) {
        errors.permissions = 'Chọn ít nhất một quyền cho nhóm người dùng.';
    }
    return errors;
});
const roleFormReady = computed(() => props.moduleKey !== 'roles'
    || Object.keys(roleFormValidationErrors.value).length === 0);
const rolePermissionError = computed(() => Object.entries(formErrors.value)
    .find(([field]) => field === 'permissions' || field === 'permission_groups' || field.startsWith('permissions.'))?.[1] ?? '');
const sectionDescription = computed(() => ({
    households: 'Danh sách phiếu điều tra trong địa bàn bạn được phân quyền.',
    schools: 'Danh mục trường học phục vụ công tác phổ cập giáo dục.',
    villages: 'Danh mục thôn, xóm thuộc địa bàn quản lý.',
    roles: 'Nhóm quyền và phạm vi quản lý được cấp cho người dùng.',
    users: 'Tài khoản và phạm vi địa bàn được phân công.',
    account: 'Thông tin đăng nhập và bảo mật tài khoản của bạn.',
    imports: 'Nhập dữ liệu từ tệp Excel hoặc tải dữ liệu đang quản lý.',
    trash: 'Các dữ liệu đã xóa có thể được khôi phục hoặc xóa vĩnh viễn.',
    merge: 'Gộp các thôn, xóm vào một địa bàn đích.',
}[props.moduleKey]));
const fieldLabels: Record<string, string> = {
    household_code: 'Mã phiếu',
    school_year: 'Năm học',
    head_name: 'Chủ hộ',
    head_first_name: 'Tên chủ hộ',
    head_last_name: 'Họ chủ hộ',
    first_name: 'Tên',
    last_name: 'Họ và tên đệm',
    dob: 'Ngày sinh',
    gender: 'Giới tính',
    ethnicity_code: 'Dân tộc',
    relationship_with_head: 'Quan hệ với chủ hộ',
    religion: 'Tôn giáo',
    priority_type: 'Diện ưu tiên',
    parent_name: 'Tên cha / mẹ',
    note: 'Ghi chú',
    academic_block: 'Khối',
    current_class: 'Lớp hiện tại',
    school_code: 'Trường học',
    graduation_level: 'Trình độ phổ cập',
    graduation_year: 'Năm hoàn thành',
    province_name: 'Tỉnh / thành phố',
    commune_name: 'Xã / phường',
    village_name: 'Thôn / xóm',
    province_code: 'Tỉnh / thành phố',
    commune_code: 'Xã / phường',
    village_code: 'Thôn / xóm',
    address: 'Địa chỉ',
    residence_type: 'Loại cư trú',
    residence_status: 'Tình trạng cư trú',
    members_count: 'Nhân khẩu',
    code: 'Mã',
    name: 'Tên',
    level: 'Cấp học',
    description: 'Mô tả',
    scope_level: 'Cấp quản lý',
    users_count: 'Thành viên',
    username: 'Tên đăng nhập',
    phone: 'Số điện thoại',
    role_name: 'Nhóm người dùng',
    role_id: 'Nhóm quyền',
    password: 'Mật khẩu',
    password_confirmation: 'Nhập lại mật khẩu',
    village_codes: 'Mã thôn quản lý (phân cách bằng dấu phẩy)',
    is_active: 'Trạng thái',
    item_type: 'Loại dữ liệu',
    deleted_at: 'Ngày xóa',
    'record.household_code': 'Mã phiếu',
    'province.name': 'Tỉnh / thành phố',
    'commune.name': 'Xã / phường',
    'commune.province.name': 'Tỉnh / thành phố',
};
const scopeLabels: Record<string, string> = {
    global: 'Trung ương',
    province: 'Cấp tỉnh',
    commune: 'Cấp xã',
    village: 'Cấp thôn / xóm',
};
const permissionLabels: Record<string, string> = {
    'view-households': 'Xem phiếu điều tra',
    'create-households': 'Tạo phiếu điều tra',
    'update-households': 'Cập nhật phiếu điều tra',
    'delete-households': 'Xóa phiếu điều tra',
    'import-households': 'Nhập dữ liệu phiếu',
    'export-households': 'Xuất dữ liệu phiếu',
    'manage-villages': 'Quản lý thôn / xóm',
    'manage-schools': 'Quản lý trường học',
    'manage-access-control': 'Quản lý nhóm người dùng',
    'manage-user-accounts': 'Quản lý tài khoản',
    'view-organization-accounts': 'Xem cây đơn vị',
};
const permissionGroups = [
    {
        key: 'accounts',
        label: 'Tài khoản & phân quyền',
        permissions: ['manage-user-accounts', 'view-organization-accounts'],
    },
    {
        key: 'roles',
        label: 'Nhóm người dùng',
        permissions: ['manage-access-control'],
    },
    {
        key: 'villages',
        label: 'Thôn / xóm',
        permissions: ['manage-villages'],
    },
    {
        key: 'schools',
        label: 'Trường học',
        permissions: ['manage-schools'],
    },
    {
        key: 'households',
        label: 'Phiếu điều tra',
        permissions: ['view-households', 'create-households', 'update-households', 'delete-households'],
    },
    {
        key: 'imports',
        label: 'Import từ Excel',
        permissions: ['import-households'],
    },
    {
        key: 'exports',
        label: 'Xuất Excel',
        permissions: ['export-households'],
    },
];
const permissionsByScope: Record<string, string[]> = {
    global: [
        'view-households',
        'create-households',
        'update-households',
        'delete-households',
        'import-households',
        'export-households',
        'manage-access-control',
        'manage-user-accounts',
        'view-organization-accounts',
    ],
    province: ['manage-schools', 'manage-access-control', 'manage-user-accounts'],
    commune: ['view-households', 'create-households', 'update-households', 'delete-households', 'import-households', 'export-households', 'manage-villages', 'manage-access-control', 'manage-user-accounts'],
    village: ['view-households', 'create-households', 'update-households', 'delete-households', 'import-households', 'export-households'],
};
const scopedPermissionGroups = computed(() => {
    const allowed = permissionsByScope[form.value.scope_level] ?? [];
    return permissionGroups
        .map(group => ({
            ...group,
            options: permissionOptions.value.filter(option => group.permissions.includes(permissionName(option)) && allowed.includes(permissionName(option))),
        }))
        .filter(group => group.options.length > 0);
});
const effectivePermissions = computed(() => scopedPermissionGroups.value.flatMap(group => group.options));
function permissionGroupState(groupKey: string): { selected: number; total: number; allSelected: boolean } {
    const group = scopedPermissionGroups.value.find(item => item.key === groupKey);
    if (!group || group.options.length === 0) return { selected: 0, total: 0, allSelected: false };

    const selectedIds = new Set(permissionIds.value);
    const selected = group.options.filter(option => selectedIds.has(String(option.id))).length;
    return {
        selected,
        total: group.options.length,
        allSelected: selected === group.options.length,
    };
}
function togglePermissionGroup(groupKey: string, checked: boolean): void {
    const group = scopedPermissionGroups.value.find(item => item.key === groupKey);
    if (!group) return;

    const groupIds = new Set(group.options.map(option => String(option.id)));
    const retainedIds = permissionIds.value.filter(id => !groupIds.has(id));
    permissionIds.value = checked
        ? [...retainedIds, ...groupIds]
        : retainedIds;
}
const inlineEditableAccountIds = computed(() => {
    const levels = ['global', 'province', 'commune', 'village'];
    const actorLevelIndex = levels.indexOf(currentActorScopeLevel.value ?? '');

    return new Set(rows.value
        .filter(row => {
            if (!canEditAccountConfigurations.value) return false;
            if (actorLevelIndex !== 0) return true;

            const rowScopeIndex = levels.indexOf(String(row.scope_level ?? ''));
            return rowScopeIndex >= 0 && rowScopeIndex <= actorLevelIndex;
        })
        .map(row => String(row.id)));
});
const canCreate = computed(() => props.permissions.includes(
    props.moduleKey === 'households' ? 'create-households'
        : props.moduleKey === 'schools' ? 'manage-schools'
            : props.moduleKey === 'villages' ? 'manage-villages'
                : props.moduleKey === 'roles' ? 'manage-access-control'
                    : props.moduleKey === 'users' ? 'manage-user-accounts' : '',
) && (props.moduleKey !== 'users' || canEditAccountConfigurations.value));
const canUpdate = computed(() => props.permissions.includes(
    props.moduleKey === 'households' ? 'update-households'
        : props.moduleKey === 'schools' ? 'manage-schools'
            : props.moduleKey === 'villages' ? 'manage-villages'
                : props.moduleKey === 'roles' ? 'manage-access-control'
                    : props.moduleKey === 'users' ? 'manage-user-accounts' : '',
));
const canDelete = computed(() => props.permissions.includes(
    props.moduleKey === 'households' ? 'delete-households'
        : props.moduleKey === 'schools' ? 'manage-schools'
            : props.moduleKey === 'villages' ? 'manage-villages'
                : props.moduleKey === 'roles' ? 'manage-access-control' : '',
));

function asRows(value: unknown): Row[] {
    if (Array.isArray(value)) return value.filter((item): item is Row => typeof item === 'object' && item !== null);
    if (typeof value === 'object' && value !== null && 'data' in value) {
        const nested = (value as { data?: unknown }).data;
        return Array.isArray(nested) ? nested.filter((item): item is Row => typeof item === 'object' && item !== null) : [];
    }
    return [];
}

const householdPersons = computed(() => {
    const persons = asRows(householdDetail.value?.persons);
    const head = asRow(householdDetail.value?.head);
    let members = persons;
    if (Object.keys(head).length) {
        const headId = head.id;
        const headIndex = headId === undefined
            ? persons.findIndex(person => person.relationship_with_head === 'Chủ hộ')
            : persons.findIndex(person => String(person.id) === String(headId));
        if (headIndex === -1) {
            members = [{ ...head, relationship_with_head: 'Chủ hộ' }, ...persons];
        } else {
            members = persons.map((person, index) => index === headIndex
                ? { ...person, relationship_with_head: 'Chủ hộ' }
                : person);
        }
    }
    return [...members].sort((left, right) =>
        Number(right.relationship_with_head === 'Chủ hộ') - Number(left.relationship_with_head === 'Chủ hộ')
    );
});

function asRow(value: unknown): Row {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Row : {};
}

function latestPersonEducation(person: Row): Row {
    return personEducations(person)[0] ?? {};
}

function personEducations(person: Row): Row[] {
    return asRows(person.educations).sort((left, right) =>
        String(right.school_year ?? '').localeCompare(String(left.school_year ?? ''))
    );
}

function educationGraduationLabel(education: Row): string {
    const levelLabels: Record<string, string> = {
        MN: 'Mầm non',
        TH: 'Tiểu học',
        THCS: 'THCS',
        THPT: 'THPT',
    };
    const level = typeof education.graduation_level === 'string'
        ? levelLabels[education.graduation_level] ?? education.graduation_level
        : '';
    const year = education.graduation_year ? `năm ${String(education.graduation_year)}` : '';
    return [level, year].filter(Boolean).join(' · ');
}

function educationLiteracyLabels(education: Row): string[] {
    const labels: string[] = [];
    const completed = education.literacy_completed_class ?? education.completed_class;
    if (completed) labels.push(`Đã hoàn thành lớp ${String(completed)}`);
    if (education.literacy_current_class) labels.push(`Đang học XMC lớp ${String(education.literacy_current_class)}`);
    if (education.learning_capacity) labels.push(`Khả năng học tập: ${String(education.learning_capacity)}`);
    if (education.literacy_relapse_level) labels.push(`Tái mù chữ: ${String(education.literacy_relapse_level)}`);
    if (education.finished_class) labels.push(`Hoàn thành lớp ${String(education.finished_class)}${education.finished_year ? ` (${String(education.finished_year)})` : ''}`);
    if (education.dropped_class) labels.push(`Bỏ học lớp ${String(education.dropped_class)}${education.dropped_year ? ` (${String(education.dropped_year)})` : ''}`);
    if (education.vocational_grad_level) {
        labels.push(`Tốt nghiệp nghề: ${String(education.vocational_grad_level)}${education.vocational_grad_year ? ` (${String(education.vocational_grad_year)})` : ''}`);
    }
    if (education.is_complementary === true || education.is_complementary === 1) labels.push('Học bổ túc');
    return labels;
}

function personDisabilityDetails(person: Row): string[] {
    const disability = typeof person.disability === 'object' && person.disability !== null
        ? person.disability as Row
        : {};
    const labels: Record<string, string> = {
        mobility_disability: 'Vận động',
        hearing_speech_disability: 'Nghe / nói',
        visual_disability: 'Thị giác',
        mental_disability: 'Tâm thần',
        intellectual_disability: 'Trí tuệ',
        learning_disability: 'Học tập',
        autism: 'Tự kỷ',
        other_disability: 'Khác',
    };
    const active = Object.entries(labels)
        .filter(([field]) => disability[field] === true || disability[field] === 1)
        .map(([, label]) => label);
    if (disability.has_disability_cert === true || disability.has_disability_cert === 1) {
        active.push('Có giấy xác nhận');
    }
    if (disability.can_study === false || disability.can_study === 0) {
        active.push('Không có khả năng học tập');
    } else if (disability.can_study === true || disability.can_study === 1) {
        active.push('Có khả năng học tập');
    }
    if (typeof disability.special_circumstance === 'string' && disability.special_circumstance) {
        active.push(`Hoàn cảnh: ${disability.special_circumstance}`);
    }
    if (typeof disability.special_circumstance_detail === 'string' && disability.special_circumstance_detail) {
        active.push(disability.special_circumstance_detail);
    }
    return active.length ? active : ['Chưa ghi nhận khuyết tật'];
}

function personGender(person: Row): string {
    return person.gender === 'NU' ? 'Nữ' : person.gender === 'NAM' ? 'Nam' : 'Chưa cập nhật';
}

function personEthnicity(person: Row): string {
    const ethnicity = person.ethnicity;
    if (typeof ethnicity === 'object' && ethnicity !== null && 'name' in ethnicity) {
        return String((ethnicity as Row).name ?? 'Chưa cập nhật');
    }
    return typeof ethnicity === 'string' && ethnicity
        ? ethnicity
        : String(person.ethnicity_code ?? 'Chưa cập nhật');
}

function displayDate(value: unknown): string {
    if (typeof value !== 'string' || !value) return 'Chưa cập nhật';
    const date = value.slice(0, 10).split('-');
    return date.length === 3 ? `${date[2]}/${date[1]}/${date[0]}` : value;
}

function pathValue(source: Row, path: string): string {
    const value = path.split('.').reduce<unknown>((current, segment) => {
        if (typeof current !== 'object' || current === null || !(segment in current)) return undefined;
        return (current as Row)[segment];
    }, source);
    if (typeof value === 'boolean') return value ? 'Đang hoạt động' : 'Đã vô hiệu hóa';
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'string' && path === 'scope_level') return scopeLabels[value] ?? value;
    if (typeof value === 'string' && path === 'level') {
        return ({ MN: 'Mầm non', TH: 'Tiểu học', THCS: 'Trung học cơ sở', THPT: 'Trung học phổ thông' } as Record<string, string>)[value] ?? value;
    }
    if (typeof value === 'string' && path === 'item_type') {
        return value === 'household' ? 'Phiếu điều tra' : value === 'person' ? 'Nhân khẩu' : value;
    }
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

function fieldLabel(field: string): string {
    return fieldLabels[field] ?? field.replaceAll('_', ' ');
}

function permissionLabel(permission: unknown): string {
    return permissionLabels[String(permission)] ?? String(permission);
}

function permissionName(permission: unknown): string {
    if (typeof permission === 'object' && permission !== null) {
        const value = permission as Row;
        return String(value.name ?? value.key ?? '');
    }

    return String(permission);
}

function roleFormState(): string {
    return JSON.stringify({
        name: form.value.name ?? '',
        description: form.value.description ?? '',
        scope_level: form.value.scope_level ?? '',
        permissions: [...permissionIds.value].sort(),
    });
}

function permissionGroupsForDisplay(value: unknown): { key: string; label: string; options: unknown[] }[] {
    const permissions = Array.isArray(value) ? value : [];

    return permissionGroups
        .map(group => ({
            key: group.key,
            label: group.label,
            options: permissions.filter(permission => group.permissions.includes(permissionName(permission))),
        }))
        .filter(group => group.options.length > 0);
}

function shouldShowFormField(field: string): boolean {
    if (props.moduleKey !== 'users') return true;

    const level = form.value.scope_level;
    if (field === 'role_id') return Boolean(level);
    if (['province_code', 'commune_code', 'village_codes'].includes(field)) {
        if (!form.value.role_id || !level || level === 'global') return false;
        if (field === 'commune_code') return ['commune', 'village'].includes(level);
        if (field === 'village_codes') return level === 'village';
    }

    return true;
}

async function loadFormCommunes(code: string): Promise<void> {
    if (!code) {
        formCommunes.value = [];
        return;
    }
    if (!communesCache.has(code)) {
        const response = await api.get<unknown>(`/provinces/${code}/communes`);
        communesCache.set(code, apiRows<Row>(apiData<unknown>(response.data)));
    }
    formCommunes.value = communesCache.get(code) ?? [];
}

async function loadFormVillages(code: string): Promise<void> {
    if (!code) {
        formVillages.value = [];
        return;
    }
    if (!villagesCache.has(code)) {
        const response = await api.get<unknown>(`/communes/${code}/villages`);
        villagesCache.set(code, apiRows<Row>(apiData<unknown>(response.data)));
    }
    formVillages.value = villagesCache.get(code) ?? [];
}

async function loadSchoolFilterCommunes(code: string): Promise<void> {
    schoolFilterCommunes.value = code
        ? apiRows<Row>(apiData<unknown>((await api.get<unknown>(`/provinces/${code}/communes`)).data))
        : [];
}

async function changeSchoolFilterProvince(): Promise<void> {
    schoolFilterCommune.value = '';
    try {
        await loadSchoolFilterCommunes(schoolFilterProvince.value);
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

async function changeFormProvince(): Promise<void> {
    form.value.commune_code = '';
    form.value.village_code = '';
    if (props.moduleKey === 'users') form.value.village_codes = '';
    formCommunes.value = [];
    formVillages.value = [];
    try {
        if (['villages', 'households', 'imports'].includes(props.moduleKey) || ['commune', 'village'].includes(form.value.scope_level)) {
            await loadFormCommunes(form.value.province_code);
        } else {
            formCommunes.value = [];
        }

    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function changeFormCommune(): Promise<void> {
    form.value.village_code = '';
    formVillages.value = [];
    try {
        await loadFormVillages(form.value.commune_code);
    } catch (cause) {
        formErrors.value.commune_code = errorMessage(cause);
    }
}

async function changeHouseholdFormCommune(): Promise<void> {
    form.value.village_code = '';
    formVillages.value = [];
    if (!form.value.commune_code) return;
    try {
        await loadFormVillages(form.value.commune_code);
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function loadUserFilterCommunes(code: string): Promise<void> {
    userFilterCommunes.value = code
        ? apiRows<Row>(apiData<unknown>((await api.get<unknown>(`/provinces/${code}/communes`)).data))
        : [];
}

async function loadUserFilterVillages(code: string): Promise<void> {
    userFilterVillages.value = code
        ? apiRows<Row>(apiData<unknown>((await api.get<unknown>(`/communes/${code}/villages`)).data))
        : [];
}

async function changeUserProvince(): Promise<void> {
    userFilterCommune.value = '';
    userFilterVillage.value = '';
    userFilterVillages.value = [];
    currentPage.value = 1;
    try {
        await loadUserFilterCommunes(userFilterProvince.value);
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function changeUserCommune(): Promise<void> {
    userFilterVillage.value = '';
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

async function searchUsers(): Promise<void> {
    currentPage.value = 1;
    await load();
}

watch(() => form.value.scope_level, async (level) => {
    if (props.moduleKey === 'users') {
        if (!assignableUserRoles.value.some(role => String(role.id) === form.value.role_id)) {
            form.value.role_id = '';
        }
        if (level === 'global') {
            form.value.province_code = '';
            form.value.commune_code = '';
            form.value.village_codes = '';
            formCommunes.value = [];
        } else if (level === 'province') {
            form.value.commune_code = '';
            form.value.village_codes = '';
            formCommunes.value = [];
        } else if (level === 'commune') {
            form.value.village_codes = '';
        } else if (!level) {
            form.value.province_code = '';
            form.value.commune_code = '';
            form.value.village_codes = '';
            formCommunes.value = [];
        }

        if (['commune', 'village'].includes(level) && form.value.province_code) {
            try {
                await loadFormCommunes(form.value.province_code);
            } catch (cause) {
                error.value = errorMessage(cause);
            }
        }
    }
    const allowedIds = new Set(effectivePermissions.value.map(option => String(option.id)));
    permissionIds.value = permissionIds.value.filter(id => allowedIds.has(id));
});

watch(form, () => {
    formErrors.value = {};
    userFormRequestError.value = '';
    roleFormRequestError.value = '';
}, { deep: true });

watch(permissionIds, () => {
    delete formErrors.value.permissions;
    delete formErrors.value['permissions.0'];
    roleFormRequestError.value = '';
});

async function load(): Promise<void> {
    busy.value = true;
    error.value = '';
    try {
        if (props.moduleKey === 'account') {
            profile.value = apiData<Row>((await api.get<unknown>('/account')).data);
        } else if (props.moduleKey === 'imports') {
            const [historyResponse, settingsResponse] = await Promise.all([
                api.get<unknown>('/household-imports', { params: { page: currentPage.value } }),
                api.get<unknown>('/household-imports/form'),
            ]);
            const history = apiData<Row>(historyResponse.data);
            importHistory.value = asRows(history.imports);
            lastPage.value = apiPage<Row>(historyResponse.data, history.imports).last_page;
            const settingsData = apiData<Row>(settingsResponse.data);
            provinces.value = asRows(settingsData.provinces);
            form.value = {
                province_code: String(settingsData.province_code ?? ''),
                commune_code: String(settingsData.commune_code ?? ''),
                village_code: String(settingsData.village_code ?? ''),
            };
            if (form.value.province_code) {
                await loadFormCommunes(form.value.province_code);
                if (form.value.commune_code) await loadFormVillages(form.value.commune_code);
            }
        } else if (props.moduleKey === 'merge') {
            rows.value = apiRows<Row>(apiData<unknown>((await api.get<unknown>(info.value.endpoint)).data));
            form.value = { target_code: '', source_codes: '' };
        } else {
            let completeRoleRows: Row[] | null = null;
            const response = (await api.get<unknown>(info.value.endpoint, {
                params: {
                    page: props.moduleKey === 'roles' ? 1 : currentPage.value,
                    ...(props.moduleKey === 'households' ? {
                        ...(householdQuery.value.trim() ? { q: householdQuery.value.trim() } : {}),
                        ...(householdSchoolYear.value ? { school_year: householdSchoolYear.value } : {}),
                        per_page: householdPageSize.value,
                        ...(props.householdFilters?.province_code ? { province_code: props.householdFilters.province_code } : {}),
                        ...(props.householdFilters?.commune_code ? { commune_code: props.householdFilters.commune_code } : {}),
                        ...(props.householdFilters?.village_code ? { village_code: props.householdFilters.village_code } : {}),
                    } : {}),
                    ...(props.moduleKey === 'villages' ? {
                        ...props.villageFilters,
                        per_page: villagePageSize.value,
                    } : {}),
                    ...(props.moduleKey === 'schools' ? {
                        ...appliedSchoolFilters.value,
                        per_page: schoolPageSize.value,
                    } : {}),
                    ...(props.moduleKey === 'users' ? {
                        province_code: userFilterProvince.value || undefined,
                        commune_code: userFilterCommune.value || undefined,
                        village_code: userFilterVillage.value || undefined,
                        role_id: userFilterRole.value || undefined,
                        q: userFilterQuery.value.trim() || undefined,
                    } : {}),
                    ...(isCompactManagement.value ? {
                        per_page: props.moduleKey === 'users' ? userPageSize.value
                            : props.moduleKey === 'roles' ? 100 : 10,
                    } : {}),
                    ...(props.moduleKey === 'trash' ? { per_page: trashPageSize.value } : {}),
                    ...(props.moduleKey === 'trash' ? {
                        type: trashFilterType.value || undefined,
                        q: trashFilterQuery.value.trim() || undefined,
                    } : {}),
                },
            })).data;
            if (props.moduleKey === 'roles') {
                const firstPage = apiPage<Row>(response);
                const remainingPages = await Promise.all(
                    Array.from({ length: Math.max(0, firstPage.last_page - 1) }, (_, index) =>
                        api.get<unknown>(info.value.endpoint, {
                            params: { page: index + 2, per_page: 100 },
                        })
                    )
                );
                completeRoleRows = [
                    ...apiRows<Row>(apiData<unknown>(response)),
                    ...remainingPages.flatMap(page => apiRows<Row>(apiData<unknown>(page.data))),
                ];
            }
            const result = apiData<Row | unknown[]>(response);
            const data = typeof result === 'object' && result !== null && !Array.isArray(result) ? result as Row : {};
            if (props.moduleKey === 'households') rows.value = apiPage<Row>(response, result).data;
            else if (props.moduleKey === 'schools') rows.value = asRows(data.schools);
            else if (props.moduleKey === 'villages') rows.value = asRows(data.villages);
            else if (props.moduleKey === 'roles') rows.value = completeRoleRows ?? apiRows<Row>(result);
            else if (props.moduleKey === 'users') rows.value = asRows(data.allUsers);
            else rows.value = asRows(data.items);

            const collection = props.moduleKey === 'households' ? result
                : props.moduleKey === 'schools' ? data.schools
                : props.moduleKey === 'villages' ? data.villages
                : props.moduleKey === 'roles' ? result
                : props.moduleKey === 'users' ? data.allUsers : data.items;
            const page = apiPage<Row>(response, collection);
            lastPage.value = page.last_page;
            currentPage.value = props.moduleKey === 'roles' ? 1 : page.current_page;
            totalRecords.value = page.total;
            if (Array.isArray(data.provinces)) provinces.value = asRows(data.provinces);
            if (props.moduleKey === 'villages') {
                emit('villageOptions', {
                    provinces: data.provinces,
                    province_code: typeof data.province_code === 'string' ? data.province_code : '',
                    commune_code: typeof data.commune_code === 'string' ? data.commune_code : '',
                });
            }
            if (props.moduleKey === 'users') {
                userFilterRoles.value = asRows(data.roles);
                currentActorScopeLevel.value = typeof data.actor_scope_level === 'string'
                    ? data.actor_scope_level
                    : null;
                canEditAccountConfigurations.value = data.can_edit_account_configurations !== false;
                if (!userFiltersInitialized.value) {
                    const selectedFilters = typeof data.selected_filters === 'object' && data.selected_filters !== null
                        ? data.selected_filters as Row
                        : {};
                    userFilterProvince.value = String(selectedFilters.province_code ?? '');
                    userFilterCommune.value = String(selectedFilters.commune_code ?? '');
                    userFilterVillage.value = String(selectedFilters.village_code ?? '');
                    userFilterRole.value = String(selectedFilters.role_id ?? '');
                    if (currentActorScopeLevel.value === 'global') {
                        userFilterCommune.value = '';
                        userFilterVillage.value = '';
                    } else if (currentActorScopeLevel.value === 'province') {
                        userFilterVillage.value = '';
                    }
                    userFiltersInitialized.value = true;
                    if (showUserCommuneFilter.value) {
                        await loadUserFilterCommunes(userFilterProvince.value);
                    }
                    if (showUserVillageFilter.value) {
                        await loadUserFilterVillages(userFilterCommune.value);
                    }
                }
            }
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

function newFormValues(): Record<string, string> {
    if (props.moduleKey === 'households') return {
        household_code: '', school_year: '', head_first_name: '', head_last_name: '',
        province_code: '', commune_code: '', village_code: '', address: '',
        residence_type: 'THUONG_TRU', residence_status: '',
    };
    if (props.moduleKey === 'schools') return { name: '', level: '', province_code: '', commune_code: '' };
    if (props.moduleKey === 'villages') return { name: '', province_code: '', commune_code: '' };
    if (props.moduleKey === 'roles') return { name: '', description: '', scope_level: '', permissions: '' };
    if (props.moduleKey === 'users') return {
        name: '', username: '', phone: '', password: '', password_confirmation: '', role_id: '', scope_level: '',
        province_code: '', commune_code: '', village_codes: '', is_active: '1',
    };
    if (props.moduleKey === 'merge') return { target_code: '', source_codes: '' };
    if (props.moduleKey === 'imports') return { province_code: '', commune_code: '', village_code: '' };
    return {};
}

async function beginEdit(row?: Row): Promise<void> {
    householdDetailReadOnly.value = false;
    configurationFormOpen.value = true;
    selected.value = row ?? null;
    showUserPassword.value = false;
    showUserPasswordConfirmation.value = false;
    error.value = '';
    notice.value = '';
    deleteResult.value = null;
    initialVillageFormState.value = null;
    initialSchoolFormState.value = null;
    initialHouseholdFormState.value = null;
    formErrors.value = {};
    userFormRequestError.value = '';
    roleFormRequestError.value = '';
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
    const route = props.moduleKey === 'schools'
        ? ''
        : props.moduleKey === 'villages'
            ? id === undefined ? '/villages/form' : `/villages/${id}/form`
            : props.moduleKey === 'roles'
                ? id === undefined ? '/roles/create' : `/roles/${id}/edit`
                : props.moduleKey === 'users'
                    ? id === undefined ? '/users/create' : `/users/${id}/edit`
                    : props.moduleKey === 'households'
                        ? id === undefined ? '/households/form' : `/households/${id}/form`
                        : '';
    form.value = newFormValues();
    if (props.moduleKey === 'households' && row) {
        for (const key of Object.keys(form.value)) {
            const value = row[key];
            if (value !== undefined && value !== null) form.value[key] = String(value);
        }
        const cachedPersons = asRows(row.persons);
        if (cachedPersons.length) {
            householdEditPersons.value = normalizeInlineHouseholdPersons(cachedPersons);
            saveInlineHouseholdPersonBaseline(householdEditPersons.value);
        }
    }
    if (props.moduleKey === 'schools') {
        if (row) {
            form.value.name = String(row.name ?? '');
            form.value.level = String(row.level ?? '');
            form.value.province_code = String(row.province_code ?? '');
            form.value.commune_code = String(row.commune_code ?? '');
        }
        if (row && form.value.province_code) {
            busy.value = true;
            try {
                if (schoolFilterProvince.value === form.value.province_code && schoolFilterCommunes.value.length) {
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
    const loadingUserForm = props.moduleKey === 'users';
    if (loadingUserForm) formOptionsBusy.value = true;
    else busy.value = true;
    try {
        const data = apiData<Row>((await api.get<unknown>(route)).data);
        if (Array.isArray(data.provinces)) provinces.value = asRows(data.provinces);
        if (props.moduleKey === 'roles') {
            permissionOptions.value = asRows(data.permissions);
            if (Array.isArray(data.scope_levels)) roleScopeLevels.value = data.scope_levels.map(String);
            const role = typeof data.role === 'object' && data.role !== null ? data.role as Row : {};
            for (const key of ['name', 'description', 'scope_level']) {
                form.value[key] = role[key] === null || role[key] === undefined ? '' : String(role[key]);
            }
            permissionIds.value = Array.isArray(role.permissions) ? role.permissions.map(String) : [];
            if (data.default_scope_level !== undefined) form.value.scope_level = String(data.default_scope_level);
            if (data.selected_scope_level !== undefined) form.value.scope_level = String(data.selected_scope_level);
            await nextTick();
            initialRoleFormState.value = roleFormState();
        } else if (props.moduleKey === 'users') {
            roleOptions.value = asRows(data.roles);
            accountLevels.value = Array.isArray(data.account_levels) ? data.account_levels.map(String) : [];
            availablePrimaryAdminScopes.value = typeof data.available_primary_admin_scopes === 'object'
                && data.available_primary_admin_scopes !== null
                ? data.available_primary_admin_scopes as Record<string, boolean>
                : {};
            const user = typeof data.user === 'object' && data.user !== null ? data.user as Row : {};
            for (const key of Object.keys(form.value)) {
                const value = user[key];
                if (Array.isArray(value)) form.value[key] = value.map(String).join(', ');
                else if (value !== undefined && value !== null) form.value[key] = String(value);
            }
            form.value.scope_level = String(user.scope_level ?? '');
            const assignedRoles = asRows(user.roles);
            if (assignedRoles[0]?.id !== undefined) form.value.role_id = String(assignedRoles[0].id);
            const locations = typeof data.selected_locations === 'object' && data.selected_locations !== null
                ? data.selected_locations as Row
                : {};
            for (const key of ['province_code', 'commune_code', 'village_codes']) {
                if (!form.value[key] && locations[key] !== undefined) {
                    const value = locations[key];
                    form.value[key] = Array.isArray(value) ? value.map(String).join(', ') : String(value);
                }
            }
        } else {
            const modelKey = props.moduleKey === 'villages' ? 'village' : 'household';
            const model = typeof data[modelKey] === 'object' && data[modelKey] !== null ? data[modelKey] as Row : {};
            for (const key of Object.keys(form.value)) {
                const value = model[key];
                if (value !== undefined && value !== null) form.value[key] = String(value);
            }
            if (props.moduleKey === 'households') {
                householdFormSchoolYears.value = Array.isArray(data.school_years)
                    ? data.school_years.map(String)
                    : householdSchoolYearOptions;
                householdEditPersons.value = normalizeInlineHouseholdPersons(ensureLegacyHouseholdHead(
                    asRows(data.persons),
                    model,
                    String(form.value.school_year ?? ''),
                ));
                saveInlineHouseholdPersonBaseline(householdEditPersons.value);
                ethnicities.value = asRows(data.ethnicities);
            }
            if (data.province_code !== undefined) form.value.province_code = String(data.province_code);
            if (data.commune_code !== undefined) form.value.commune_code = String(data.commune_code);
            if (['villages', 'households'].includes(props.moduleKey) && form.value.province_code) {
                await Promise.all([
                    loadFormCommunes(form.value.province_code),
                    props.moduleKey === 'households' && form.value.commune_code
                        ? loadFormVillages(form.value.commune_code)
                        : Promise.resolve(),
                ]);
            }
            if (props.moduleKey === 'households' && row) {
                initialHouseholdFormState.value = householdFormState();
            }
        }
        if (props.moduleKey === 'villages') {
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
    householdLoading.value = true;
    householdListRow.value = row;
    const cachedPersons = asRows(row.persons);
    if (householdDetail.value?.id !== id) {
        householdDetail.value = {
            ...row,
            persons: cachedPersons,
            members_count: cachedPersons.length || Number(row.members_count ?? row.persons_count ?? 0),
        };
    }
    try {
        const householdResponse = await api.get<unknown>(`/households/${id}`);
        const responseData = apiData<Row>(householdResponse.data);
        const household = typeof responseData.household === 'object' && responseData.household !== null
            ? responseData.household as Row
            : {};
        const persons = asRows(responseData.persons ?? household.persons ?? cachedPersons);
        householdDetail.value = {
            ...household,
            head: responseData.head,
            has_household_head: responseData.has_household_head,
            persons,
        };
        memberEditorOpen.value = false;
        error.value = '';
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        householdLoading.value = false;
    }
}

function closeHouseholdDetail(): void {
    householdDetail.value = null;
    householdDetailReadOnly.value = false;
    householdListRow.value = null;
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
        : selectedHouseholdIds.value.filter(selectedId => selectedId !== id);
}

const allHouseholdRowsSelected = computed(() => rows.value.length > 0
    && rows.value.every(row => selectedHouseholdIds.value.includes(Number(row.id))));

function toggleAllHouseholdRows(checked: boolean): void {
    const pageIds = rows.value
        .map(row => Number(row.id))
        .filter(Number.isInteger);
    selectedHouseholdIds.value = checked
        ? [...new Set([...selectedHouseholdIds.value, ...pageIds])]
        : selectedHouseholdIds.value.filter(id => !pageIds.includes(id));
}

const someHouseholdRowsSelected = computed(() => rows.value.some(row =>
    selectedHouseholdIds.value.includes(Number(row.id))
) && !allHouseholdRowsSelected.value);

function applyHouseholdFilters(): void {
    selectedHouseholdIds.value = [];
    currentPage.value = 1;
    void load();
}

async function updateHouseholdPageAfterDelete(): Promise<void> {
    lastPage.value = Math.max(1, Math.ceil(totalRecords.value / householdPageSize.value));
    if (currentPage.value > lastPage.value) {
        currentPage.value = lastPage.value;
        await load();
    }
}

async function confirmBulkHouseholdDelete(): Promise<void> {
    if (!selectedHouseholdIds.value.length || busy.value) return;
    busy.value = true;
    error.value = '';
    const ids = [...selectedHouseholdIds.value];
    try {
        const response = await api.delete<unknown>('/households', { data: { household_ids: ids } });
        const responseData = apiData<Row>(response.data);
        const deletedCount = Number(responseData.deleted_count);
        if (!Number.isInteger(deletedCount) || deletedCount < 0 || deletedCount > ids.length) {
            throw new Error('Phản hồi xóa phiếu không hợp lệ. Vui lòng tải lại danh sách.');
        }
        rows.value = rows.value.filter(row => !ids.includes(Number(row.id)));
        totalRecords.value = Math.max(0, totalRecords.value - deletedCount);
        selectedHouseholdIds.value = [];
        pendingHouseholdBulkDelete.value = false;
        await updateHouseholdPageAfterDelete();
        deleteResult.value = {
            success: true,
            title: 'Xóa phiếu thành công',
            message: `Đã xóa ${deletedCount} phiếu điều tra đã chọn.`,
        };
    } catch (cause) {
        pendingHouseholdBulkDelete.value = false;
        deleteResult.value = {
            success: false,
            title: 'Xóa phiếu thất bại',
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
    configurationFormOpen.value = false;
    selected.value = null;
    createdHouseholdId.value = null;
    createHouseholdWizardStep.value = 1;
    form.value = {};
    initialVillageFormState.value = null;
    initialSchoolFormState.value = null;
    initialHouseholdFormState.value = null;
    initialVillageFormState.value = null;
    showUserPassword.value = false;
    showUserPasswordConfirmation.value = false;
    formErrors.value = {};
}

function selectCreateHouseholdStep(step: number): void {
    if (step === 1) {
        createHouseholdWizardStep.value = 1;
        return;
    }
    createHouseholdWizardStep.value = step;
    if (step === 2) beginMember(true);
    if (step === 3) beginMember(false);
    userFormRequestError.value = '';
    roleFormRequestError.value = '';
    permissionIds.value = [];
    initialRoleFormState.value = null;
    formCommunes.value = [];
    availablePrimaryAdminScopes.value = {};
    formOptionsBusy.value = false;
    error.value = '';
}

async function beginMember(isHead: boolean, person?: Row): Promise<void> {
    const householdId = householdDetail.value?.id ?? createdHouseholdId.value ?? selected.value?.id;
    if (householdId === undefined) {
        memberId.value = person?.id === undefined ? null : Number(person.id);
        memberIsHead.value = person ? person.relationship_with_head === 'Chủ hộ' : isHead;
        memberForm.value = {
            first_name: '', last_name: '', dob: '', gender: 'NAM', ethnicity_code: '',
            relationship_with_head: isHead ? 'Chủ hộ' : '', religion: '', priority_type: '', parent_name: '',
            phone: '', note: '',
        };
        educationForm.value = {
            school_year: String(form.value.school_year ?? ''),
            school_code: '', current_class: '', academic_block: '', graduation_level: '',
            graduation_year: '', is_complementary: '0', vocational_grad_level: '',
            vocational_grad_year: '', finished_class: '', finished_year: '',
            dropped_class: '', dropped_year: '', literacy_current_class: '',
            literacy_completed_class: '', literacy_relapse_level: '', learning_capacity: '',
        };
        memberEditorOpen.value = true;
        return;
    }
    memberId.value = person?.id === undefined ? null : Number(person.id);
    memberIsHead.value = person ? person.relationship_with_head === 'Chủ hộ' : isHead;
    headMemberEducationStep.value = false;
    memberForm.value = {
        first_name: '', last_name: '', dob: '', gender: 'NAM', ethnicity_code: '',
        relationship_with_head: isHead ? 'Chủ hộ' : '', religion: '', priority_type: '', parent_name: '',
        phone: '', note: '',
    };
    educationForm.value = {
        school_year: String(householdDetail.value?.school_year ?? ''), school_code: '', current_class: '', academic_block: '',
        graduation_level: '', graduation_year: '', is_complementary: '0',
        vocational_grad_level: '', vocational_grad_year: '', finished_class: '',
        finished_year: '', dropped_class: '', dropped_year: '',
        literacy_current_class: '', literacy_completed_class: '',
        literacy_relapse_level: '', learning_capacity: '',
    };
    disabilityForm.value = Object.fromEntries([
        ...memberDisabilityOptions.map(({ key }) => [key, false]),
        ['can_study', ''],
        ['special_circumstance', ''],
        ['special_circumstance_detail', ''],
    ]);
    ethnicities.value = [];
    personSchools.value = [];
    memberFormErrors.value = {};
    notice.value = '';
    error.value = '';
    householdFormSchoolYears.value = memberSchoolYearOptions;
    memberEditorOpen.value = true;
    try {
        const path = memberId.value === null
            ? `/households/${householdId}/persons/form`
            : `/households/${householdId}/persons/${memberId.value}/form`;
        const result = apiData<Row>((await api.get<unknown>(path, { params: { is_head: memberIsHead.value ? 1 : 0 } })).data);
        const person = typeof result.person === 'object' && result.person !== null ? result.person as Row : {};
        for (const key of Object.keys(memberForm.value)) {
            const value = person[key];
            if (value !== null && value !== undefined && String(value).trim() !== '') memberForm.value[key] = String(value);
        }
        if (memberId.value === null && memberIsHead.value) {
            memberForm.value.relationship_with_head = 'Chủ hộ';
        }
        const education = typeof result.education === 'object' && result.education !== null
            ? result.education as Row
            : {};
        for (const key of Object.keys(educationForm.value)) {
            const value = education[key];
            if (key === 'is_complementary' && value !== null && value !== undefined) {
                educationForm.value[key] = value === true || value === 1 || value === '1' ? '1' : '0';
            } else if (value !== null && value !== undefined && String(value).trim() !== '') {
                educationForm.value[key] = String(value);
            }
        }
        const disability = typeof result.disability === 'object' && result.disability !== null
            ? result.disability as Row
            : {};
        for (const key of Object.keys(disabilityForm.value)) {
            const value = disability[key];
            if (key === 'can_study') {
                disabilityForm.value[key] = value === null || value === undefined
                    ? ''
                    : String(value === true || value === 1 || value === '1');
            } else if (memberDisabilityOptions.some(option => option.key === key)) {
                disabilityForm.value[key] = value === true || value === 1;
            } else if (value !== null && value !== undefined) {
                disabilityForm.value[key] = String(value);
            }
        }
        if (!educationForm.value.school_year) {
            educationForm.value.school_year = String(householdDetail.value?.school_year ?? '');
        }
        ethnicities.value = asRows(result.ethnicities);
        personSchools.value = asRows(result.schools);
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function saveMember(): Promise<void> {
    const householdIdValue = householdDetail.value?.id ?? createdHouseholdId.value ?? selected.value?.id;
    const householdId = householdIdValue === undefined || householdIdValue === null ? '' : String(householdIdValue);
    if (!householdId || memberBusy.value) return;
    memberFormErrors.value = {};
    const booleanDisability = Object.fromEntries(memberDisabilityOptions.map(({ key }) => [
        key,
        disabilityForm.value[key] === true,
    ]));
    const selectedSchool = personSchools.value.find(school =>
        String(school.code) === educationForm.value.school_code
    );
    const body: Row = {
        ...memberForm.value,
        is_head: memberIsHead.value,
        education: educationForm.value,
        school_province_code: selectedSchool?.province_code ?? '',
        school_commune_code: selectedSchool?.commune_code ?? '',
        disability: {
            ...booleanDisability,
            can_study: disabilityForm.value.can_study === ''
                ? null
                : disabilityForm.value.can_study === 'true',
            special_circumstance: disabilityForm.value.special_circumstance,
            special_circumstance_detail: disabilityForm.value.special_circumstance_detail,
        },
    };
    memberBusy.value = true;
    error.value = '';
    try {
        if (memberId.value === null) {
            await api.post(`/households/${householdId}/persons`, body);
            memberEditorOpen.value = false;
        } else {
            await api.put(`/households/${householdId}/persons/${memberId.value}`, body);
            memberEditorOpen.value = false;
        }
        await refreshHouseholdEditPersons(householdId);
        notice.value = memberId.value === null ? 'Đã thêm nhân khẩu.' : 'Đã cập nhật nhân khẩu.';
        if (!editing.value && props.moduleKey === 'households' && memberIsHead.value) {
            createHouseholdWizardStep.value = 3;
        }
    } catch (cause) {
        memberFormErrors.value = validationErrors(cause);
        managementResult.value = {
            success: false,
            title: 'Không thể lưu nhân khẩu',
            message: errorMessage(cause),
        };
    } finally {
        memberBusy.value = false;
    }
}

async function refreshHouseholdEditPersons(householdId: number | string): Promise<void> {
    const response = await api.get<unknown>(`/households/${householdId}/persons`, { params: { per_page: 100 } });
    const page = apiPage<Row>(response.data);
    householdEditPersons.value = normalizeInlineHouseholdPersons(page.data);
    saveInlineHouseholdPersonBaseline(householdEditPersons.value);
    if (householdDetail.value?.id === householdId) {
        householdDetail.value.persons = page.data;
        householdDetail.value.members_count = page.data.length;
    }
}

function normalizeInlineHouseholdPersons(persons: Row[]): Row[] {
        return persons.map(person => {
            const educations = Array.isArray(person.educations) ? person.educations as Row[] : [];
            const education = educations.find(item => String(item.school_year ?? '') === String(form.value.school_year ?? ''))
                ?? educations[0]
                ?? {};

            return {
                ...person,
                    dob: normalizeInlineDate(person.dob),
                    religion: person.religion ?? '',
                priority_type: person.priority_type ?? '',
                relationship_with_head: person.relationship_with_head ?? '',
                parent_name: person.parent_name ?? '',
                phone: person.phone ?? '',
                note: person.note ?? '',
                education: {
                    school_year: education.school_year ?? form.value.school_year ?? '',
                    academic_block: education.academic_block ?? '',
                    current_class: education.current_class ?? '',
                    school_code: education.school_code ?? '',
                    graduation_level: education.graduation_level ?? '',
                    is_complementary: education.is_complementary ?? false,
                    graduation_year: education.graduation_year ?? '',
                    vocational_grad_level: education.vocational_grad_level ?? '',
                    vocational_grad_year: education.vocational_grad_year ?? '',
                    finished_class: education.finished_class ?? '',
                    finished_year: education.finished_year ?? '',
                    dropped_class: education.dropped_class ?? '',
                    dropped_year: education.dropped_year ?? '',
                    literacy_current_class: education.literacy_current_class ?? '',
                    literacy_completed_class: education.literacy_completed_class ?? '',
                    literacy_relapse_level: education.literacy_relapse_level ?? '',
                    learning_capacity: education.learning_capacity ?? '',
                },
                disability: {
                    ...((typeof person.disability === 'object' && person.disability !== null ? person.disability : {}) as Row),
                    mobility_disability: (person.disability as Row)?.mobility_disability ?? false,
                    hearing_speech_disability: (person.disability as Row)?.hearing_speech_disability ?? false,
                    visual_disability: (person.disability as Row)?.visual_disability ?? false,
                    mental_disability: (person.disability as Row)?.mental_disability ?? false,
                    intellectual_disability: (person.disability as Row)?.intellectual_disability ?? false,
                    learning_disability: (person.disability as Row)?.learning_disability ?? false,
                    autism: (person.disability as Row)?.autism ?? false,
                    other_disability: (person.disability as Row)?.other_disability ?? false,
                    has_disability_cert: (person.disability as Row)?.has_disability_cert ?? false,
                    can_study: (person.disability as Row)?.can_study ?? false,
                    special_circumstance: (person.disability as Row)?.special_circumstance ?? '',
                    special_circumstance_detail: (person.disability as Row)?.special_circumstance_detail ?? '',
                },
            };
        });
}

function ensureLegacyHouseholdHead(persons: Row[], household: Row, schoolYear: string): Row[] {
    if (persons.some(person => String(person.relationship_with_head ?? '') === 'Chủ hộ')) {
        return persons;
    }

    const lastName = String(household.head_last_name ?? '').trim();
    const firstName = String(household.head_first_name ?? '').trim();
    if (!lastName && !firstName) {
        return persons;
    }

    return [{
        _inline_new: true,
        last_name: lastName,
        first_name: firstName,
        relationship_with_head: 'Chủ hộ',
        dob: '',
        gender: '',
        ethnicity_code: '',
        religion: '',
        priority_type: '',
        parent_name: '',
        phone: '',
        note: '',
        education: {
            school_year: schoolYear,
            academic_block: '',
            current_class: '',
            school_code: '',
            graduation_level: '',
            is_complementary: false,
            graduation_year: '',
            vocational_grad_level: '',
            vocational_grad_year: '',
            finished_class: '',
            finished_year: '',
            dropped_class: '',
            dropped_year: '',
            literacy_current_class: '',
            literacy_completed_class: '',
            literacy_relapse_level: '',
            learning_capacity: '',
        },
        disability: {},
    }, ...persons];
}

function inlineHouseholdPersonKey(person: Row, index?: number): string {
    return person.id === undefined || person.id === null
        ? `new:${index ?? 0}`
        : String(person.id);
}

function inlineHouseholdPersonSnapshot(person: Row): string {
    return JSON.stringify({
        first_name: person.first_name ?? '',
        last_name: person.last_name ?? '',
        dob: person.dob ?? '',
        gender: person.gender ?? '',
        ethnicity_code: person.ethnicity_code ?? '',
        relationship_with_head: person.relationship_with_head ?? '',
        religion: person.religion ?? '',
        priority_type: person.priority_type ?? '',
        parent_name: person.parent_name ?? '',
        phone: person.phone ?? '',
        note: person.note ?? '',
        education: person.education ?? {},
        disability: person.disability ?? {},
    });
}

function inlineHouseholdPersonChanged(person: Row, index: number): boolean {
    if (person.id === undefined || person.id === null || person._inline_new === true) return true;
    const key = inlineHouseholdPersonKey(person, index);
    return inlineHouseholdPersonBaseline.value[key] !== inlineHouseholdPersonSnapshot(person);
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
    const date = String(value ?? '').trim();
    return /^\d{4}-\d{2}-\d{2}/.test(date) ? date.slice(0, 10) : '';
}

function inlineEducation(person: Row): Row {
    if (typeof person.education !== 'object' || person.education === null) {
        person.education = {};
    }
    return person.education as Row;
}

function inlineDisability(person: Row): Row {
    if (typeof person.disability !== 'object' || person.disability === null) {
        person.disability = {};
    }
    return person.disability as Row;
}

async function addInlineHouseholdMember(): Promise<void> {
    householdEditPersons.value.push({
        _inline_new: true,
        last_name: '',
        first_name: '',
        relationship_with_head: 'Con',
        dob: '',
        gender: 'NAM',
        ethnicity_code: '',
        religion: '',
        priority_type: '',
        parent_name: '',
        phone: '',
        note: '',
        education: {
            school_year: String(form.value.school_year ?? householdDetail.value?.school_year ?? ''),
            academic_block: '',
            current_class: '',
            school_code: '',
            graduation_level: '',
            is_complementary: false,
            graduation_year: '',
            vocational_grad_level: '',
            vocational_grad_year: '',
            finished_class: '',
            finished_year: '',
            dropped_class: '',
            dropped_year: '',
            literacy_current_class: '',
            literacy_completed_class: '',
            literacy_relapse_level: '',
            learning_capacity: '',
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
            special_circumstance: '',
            special_circumstance_detail: '',
        },
    });

    await nextTick();
    const newRowIndex = householdEditPersons.value.length - 1;
    const input = document.querySelector<HTMLInputElement>(
        `.household-edit-member-last-name[data-member-index="${newRowIndex}"]`,
    );
    if (input) {
        input.focus({ preventScroll: true });
        const tableWrap = input.closest('.household-edit-members-table-wrap');
        const row = input.closest('tr');
        if (tableWrap && row) {
            tableWrap.scrollTop = Math.max(0, row.offsetTop - tableWrap.clientHeight / 2);
            tableWrap.scrollLeft = 0;
        }
    }
}

async function saveInlineHouseholdMember(person: Row, index: number, showResult = true): Promise<boolean> {
    const householdIdValue = householdDetail.value?.id ?? selected.value?.id;
    const householdId = householdIdValue === undefined || householdIdValue === null ? '' : String(householdIdValue);
    if (!householdId || memberBusy.value) return false;

    const requiredFields = [
        ['Họ và tên đệm', person.last_name],
        ['Tên', person.first_name],
        ['Ngày sinh', person.dob],
        ['Giới tính', person.gender],
        ['Dân tộc', person.ethnicity_code],
        ['Quan hệ', person.relationship_with_head],
    ];
    const missingField = requiredFields.find(([, value]) => String(value ?? '').trim() === '');
    if (missingField) {
        error.value = `Vui lòng nhập ${String(missingField[0]).toLocaleLowerCase('vi-VN')}.`;
        managementResult.value = {
            success: false,
            title: 'Không thể lưu nhân khẩu',
            message: error.value,
        };
        return false;
    }

    memberBusy.value = true;
    error.value = '';
    try {
        let education: Row = {
            school_year: String(form.value.school_year ?? householdDetail.value?.school_year ?? ''),
            is_complementary: '0',
            ...(typeof person.education === 'object' && person.education !== null ? person.education as Row : {}),
        };
        let disability: Row = typeof person.disability === 'object' && person.disability !== null
            ? person.disability as Row
            : {};
        let ethnicityCode = String(person.ethnicity_code ?? '');

        education = {
            ...education,
            ...(typeof person.education === 'object' && person.education !== null ? person.education as Row : {}),
        };
        disability = {
            ...disability,
            ...(typeof person.disability === 'object' && person.disability !== null ? person.disability as Row : {}),
        };

        const body = {
            first_name: String(person.first_name ?? ''),
            last_name: String(person.last_name ?? ''),
            dob: String(person.dob ?? ''),
            gender: String(person.gender ?? ''),
            ethnicity_code: ethnicityCode,
            relationship_with_head: String(person.relationship_with_head ?? ''),
            religion: String(person.religion ?? ''),
            priority_type: String(person.priority_type ?? ''),
            parent_name: String(person.parent_name ?? ''),
            phone: String(person.phone ?? ''),
            note: String(person.note ?? ''),
            is_head: person.relationship_with_head === 'Chủ hộ',
            education,
            school_province_code: '',
            school_commune_code: '',
            disability,
        };

        if (person.id === undefined) {
            await api.post(`/households/${householdId}/persons`, body);
        } else {
            await api.put(`/households/${householdId}/persons/${person.id}`, body);
        }
        if (showResult) {
            await refreshHouseholdEditPersons(householdId);
            managementResult.value = {
                success: true,
                title: person.id === undefined ? 'Thêm nhân khẩu thành công' : 'Sửa nhân khẩu thành công',
                message: person.id === undefined ? 'Nhân khẩu mới đã được thêm vào phiếu.' : 'Thông tin nhân khẩu đã được cập nhật.',
            };
        }
        return true;
    } catch (cause) {
        error.value = errorMessage(cause);
        if (showResult) managementResult.value = {
            success: false,
            title: 'Không thể lưu nhân khẩu',
            message: errorMessage(cause),
        };
        return false;
    } finally {
        memberBusy.value = false;
    }
}

async function deleteMember(person: Row): Promise<void> {
    if (person.id === undefined) return;
    if (person.relationship_with_head === 'Chủ hộ') {
        managementResult.value = {
            success: false,
            title: 'Không thể xóa chủ hộ',
            message: 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.',
        };
        return;
    }
    error.value = '';
    notice.value = '';
    pendingHouseholdPersonDelete.value = person;
}

async function confirmDeleteMember(): Promise<void> {
    const householdIdValue = householdDetail.value?.id ?? selected.value?.id;
    const householdId = householdIdValue === undefined || householdIdValue === null ? '' : String(householdIdValue);
    const person = pendingHouseholdPersonDelete.value;
    if (!householdId || person?.id === undefined || memberBusy.value) return;
    memberBusy.value = true;
    error.value = '';
    try {
        const response = await api.delete<unknown>(`/households/${householdId}/persons/${person.id}`);
        const result = apiData<Row>(response.data);
        if (householdDetail.value) await openHousehold({ id: householdId });
        await refreshHouseholdEditPersons(householdId);
        const currentDetail = householdDetail.value;
        if (currentDetail) {
            currentDetail.persons = asRows(currentDetail.persons)
                .filter(member => String(member.id) !== String(person.id));
            currentDetail.members_count = asRows(currentDetail.persons).length;
            if (result.is_household_head === true) {
                currentDetail.head = null;
                currentDetail.has_household_head = false;
            }
        }
        pendingHouseholdPersonDelete.value = null;
        managementResult.value = {
            success: true,
            title: 'Xóa nhân khẩu thành công',
            message: String(result.message ?? 'Nhân khẩu đã được xóa khỏi phiếu.'),
        };
    } catch (cause) {
        error.value = errorMessage(cause);
        managementResult.value = {
            success: false,
            title: 'Không thể xóa nhân khẩu',
            message: errorMessage(cause),
        };
    } finally {
        memberBusy.value = false;
    }
}

function payload(): Row {
    const data: Row = { ...form.value };
    if (props.moduleKey === 'roles') {
        data.permissions = permissionIds.value.map(Number).filter(Number.isInteger);
    }
    if (props.moduleKey === 'users') {
        data.village_codes = form.value.village_codes?.split(',').map(value => value.trim()).filter(Boolean) ?? [];
        data.is_active = form.value.is_active === '1';
        if (editing.value && !form.value.password) {
            delete data.password;
            delete data.password_confirmation;
        }
    }
    if (props.moduleKey === 'merge') {
        data.source_codes = selectedSourceCodes.value;
    }
    if (props.moduleKey === 'villages' || props.moduleKey === 'schools') {
        if (!form.value.commune_code) data.commune_code = null;
    }
    return data;
}

function villageMatchesFilters(village: Row): boolean {
    const filters = props.villageFilters;
    if (!filters) return true;

    const commune = typeof village.commune === 'object' && village.commune !== null
        ? village.commune as Row
        : {};
    const province = typeof commune.province === 'object' && commune.province !== null
        ? commune.province as Row
        : {};
    const provinceCode = String(village.province_code ?? commune.province_code ?? province.code ?? '');
    if (filters.province_code && provinceCode !== filters.province_code) return false;
    if (filters.commune_code && String(village.commune_code ?? commune.code ?? '') !== filters.commune_code) return false;
    const query = filters.q.trim().toLocaleLowerCase('vi');
    return !query || [village.code, village.name]
        .some(value => String(value ?? '').toLocaleLowerCase('vi').includes(query));
}

function updateVillageList(village: Row, wasEditing: boolean): void {
    const code = String(village.code ?? '');
    if (!code) return;

    const wasListed = rows.value.some(row => String(row.code ?? '') === code);
    const matchesFilters = villageMatchesFilters(village);
    if (wasEditing && wasListed && !matchesFilters) {
        rows.value = rows.value.filter(row => String(row.code ?? '') !== code);
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        return;
    }

    if (!matchesFilters) return;
    const updatedRows = rows.value.filter(row => String(row.code ?? '') !== code);
    if (wasListed || currentPage.value === 1) {
        updatedRows.push(village);
        updatedRows.sort((left, right) => String(left.name ?? '').localeCompare(String(right.name ?? ''), 'vi'));
        rows.value = updatedRows.slice(0, villagePageSize.value);
    }
    if (!wasListed) totalRecords.value += 1;
}

function schoolMatchesFilters(school: Row): boolean {
    const filters = appliedSchoolFilters.value;
    const province = typeof school.province === 'object' && school.province !== null
        ? school.province as Row
        : {};
    const commune = typeof school.commune === 'object' && school.commune !== null
        ? school.commune as Row
        : {};
    if (filters.province_code && String(school.province_code ?? province.code ?? '') !== filters.province_code) return false;
    if (filters.commune_code && String(school.commune_code ?? commune.code ?? '') !== filters.commune_code) return false;
    if (filters.level && String(school.level ?? '') !== filters.level) return false;
    const query = filters.q.trim().toLocaleLowerCase('vi');
    return !query || [school.code, school.name]
        .some(value => String(value ?? '').toLocaleLowerCase('vi').includes(query));
}

function updateSchoolList(school: Row, wasEditing: boolean): void {
    const id = String(school.id ?? '');
    if (!id) return;

    const wasListed = rows.value.some(row => String(row.id ?? '') === id);
    const matchesFilters = schoolMatchesFilters(school);
    if (wasEditing && wasListed && !matchesFilters) {
        rows.value = rows.value.filter(row => String(row.id ?? '') !== id);
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        return;
    }

    if (!matchesFilters) return;
    const updatedRows = rows.value.filter(row => String(row.id ?? '') !== id);
    if (wasListed || currentPage.value === 1) {
        updatedRows.push(school);
        updatedRows.sort((left, right) => String(left.name ?? '').localeCompare(String(right.name ?? ''), 'vi'));
        rows.value = updatedRows.slice(0, schoolPageSize.value);
    }
    if (!wasListed) totalRecords.value += 1;
}

function villageFormState(): string {
    return JSON.stringify({
        name: form.value.name?.trim() ?? '',
        province_code: form.value.province_code ?? '',
        commune_code: form.value.commune_code ?? '',
    });
}

function schoolFormState(): string {
    return JSON.stringify({
        name: form.value.name?.trim() ?? '',
        level: form.value.level ?? '',
        province_code: form.value.province_code ?? '',
        commune_code: form.value.commune_code ?? '',
    });
}

function householdFormState(): string {
    return JSON.stringify(Object.fromEntries(Object.keys(newFormValues()).map(field => [
        field,
        form.value[field]?.trim() ?? '',
    ])));
}

async function save(): Promise<void> {
    formErrors.value = {};
    userFormRequestError.value = '';
    roleFormRequestError.value = '';
    managementResult.value = null;
    if (props.moduleKey === 'users') {
        formErrors.value = { ...userFormValidationErrors.value };
        if (!userFormReady.value) {
            error.value = 'Bạn chưa thể lưu tài khoản. Hãy hoàn tất các thông tin bắt buộc bên dưới.';
            return;
        }
    }
    if (props.moduleKey === 'roles' && !roleFormReady.value) return;
    if (props.moduleKey === 'villages' && !villageFormReady.value) return;
    if (props.moduleKey === 'schools' && !schoolFormReady.value) return;
    if (props.moduleKey === 'households' && !editing.value && createHouseholdWizardStep.value === 2) {
        beginMember(true);
        return;
    }
    if (props.moduleKey === 'households' && !editing.value && createHouseholdWizardStep.value === 3) {
        configurationFormOpen.value = false;
        if (createdHouseholdId.value !== null) void openHousehold({ id: createdHouseholdId.value });
        return;
    }
    if (props.moduleKey === 'villages' && editing.value && initialVillageFormState.value === villageFormState()) {
        closeConfigurationForm();
        deleteResult.value = {
            success: true,
            title: 'Không có thay đổi',
            message: 'Thông tin thôn/xóm không thay đổi; không gửi yêu cầu cập nhật.',
        };
        return;
    }
    if (props.moduleKey === 'schools' && editing.value && initialSchoolFormState.value === schoolFormState()) {
        closeConfigurationForm();
        deleteResult.value = {
            success: true,
            title: 'Không có thay đổi',
            message: 'Thông tin trường học không thay đổi; không gửi yêu cầu cập nhật.',
        };
        return;
    }
    if (props.moduleKey === 'households' && editing.value
        && initialHouseholdFormState.value === householdFormState()) {
        closeConfigurationForm();
        managementResult.value = {
            success: true,
            title: 'Không có thay đổi',
            message: 'Thông tin phiếu điều tra không thay đổi; không gửi yêu cầu cập nhật.',
        };
        return;
    }
    if (props.moduleKey === 'roles' && editing.value && initialRoleFormState.value === roleFormState()) {
        configurationFormOpen.value = false;
        selected.value = null;
        form.value = {};
        permissionIds.value = [];
        managementResult.value = {
            success: true,
            title: 'Không có thay đổi',
            message: 'Thông tin nhóm người dùng vẫn giữ nguyên; không gửi yêu cầu cập nhật.',
        };
        return;
    }
    const accountDetails = props.moduleKey === 'users' && !editing.value
        ? { name: form.value.name ?? '', username: form.value.username ?? '' }
        : null;
    const roleDetails = props.moduleKey === 'roles' && !editing.value
        ? { name: form.value.name ?? '' }
        : null;

    const wasEditing = editing.value;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const id = selected.value?.id ?? selected.value?.code;
        const endpoint = info.value.endpoint;
        const body = payload();
        let createdHouseholdIdValue: unknown;
        let createdRole: Row | null = null;
        let savedVillage: Row | null = null;
        let savedSchool: Row | null = null;
        const householdFormChanged = props.moduleKey === 'households'
            && editing.value
            && initialHouseholdFormState.value !== householdFormState();
        if (editing.value && !(props.moduleKey === 'households' && !householdFormChanged)) {
            const response = await api.put<unknown>(`${endpoint}/${id}`, body);
            if (props.moduleKey === 'villages') {
                const result = apiData<Row>(response.data);
                savedVillage = typeof result.village === 'object' && result.village !== null
                    ? result.village as Row
                    : null;
            }
            if (props.moduleKey === 'schools') {
                const result = apiData<Row>(response.data);
                savedSchool = typeof result.school === 'object' && result.school !== null
                    ? result.school as Row
                    : null;
            }
        } else {
            const response = await api.post<unknown>(endpoint, body);
            const created = apiData<Row>(response.data);
            createdHouseholdIdValue = created.id;
            if (props.moduleKey === 'roles') {
                createdRole = { ...created, can_manage: true };
            } else if (props.moduleKey === 'villages') {
                savedVillage = typeof created.village === 'object' && created.village !== null
                    ? created.village as Row
                    : null;
            } else if (props.moduleKey === 'schools') {
                savedSchool = typeof created.school === 'object' && created.school !== null
                    ? created.school as Row
                    : null;
            }
        }
        notice.value = props.moduleKey === 'households'
            ? ''
            : ['roles', 'villages', 'schools'].includes(props.moduleKey) || (props.moduleKey === 'users' && !wasEditing)
                ? ''
                : wasEditing ? 'Đã cập nhật.' : 'Đã tạo mới.';
        if (props.moduleKey === 'households' && typeof createdHouseholdIdValue === 'number') {
            if (!editing.value) {
                createdHouseholdId.value = createdHouseholdIdValue;
                createHouseholdWizardStep.value = 2;
                void beginMember(true);
                return;
            }
            await openHousehold({ id: createdHouseholdIdValue });
        }
        configurationFormOpen.value = false;
        selected.value = null;
        form.value = {};
        initialSchoolFormState.value = null;
        if (props.moduleKey === 'villages' && savedVillage) {
            updateVillageList(savedVillage, wasEditing);
            deleteResult.value = {
                success: true,
                title: wasEditing ? 'Cập nhật thành công' : 'Thêm thành công',
                message: wasEditing ? 'Thông tin thôn/xóm đã được cập nhật.' : 'Thôn/xóm đã được thêm.',
            };
        } else if (props.moduleKey === 'schools' && savedSchool) {
            updateSchoolList(savedSchool, wasEditing);
            deleteResult.value = {
                success: true,
                title: wasEditing ? 'Cập nhật thành công' : 'Thêm thành công',
                message: wasEditing ? 'Thông tin trường học đã được cập nhật.' : 'Trường học đã được thêm.',
            };
        } else if (props.moduleKey === 'roles' && wasEditing) {
            const updatedPermissions = permissionIds.value
                .map(id => permissionOptions.value.find(option => String(option.id) === id))
                .filter((permission): permission is Row => permission !== undefined)
                .map(permission => String(permission.name));
            rows.value = rows.value.map(row => String(row.id) === String(id)
                ? {
                    ...row,
                    name: body.name,
                    description: body.description,
                    scope_level: body.scope_level,
                    permissions: updatedPermissions,
                }
                : row);
        } else if (props.moduleKey === 'roles' && createdRole) {
            rows.value = [...rows.value, createdRole];
            totalRecords.value += 1;
        } else if (!['villages', 'schools', 'households'].includes(props.moduleKey)) {
            await load();
        }
        if (props.moduleKey === 'households' && wasEditing) {
            managementResult.value = {
                success: true,
                title: 'Cập nhật phiếu điều tra thành công',
                message: 'Thông tin phiếu điều tra đã được cập nhật.',
            };
        } else if (accountDetails) {
            managementResult.value = {
                success: true,
                title: 'Tạo tài khoản thành công',
                message: `Tài khoản ${accountDetails.name} đã được thêm vào danh sách.`,
                username: accountDetails.username,
            };
        } else if (roleDetails) {
            managementResult.value = {
                success: true,
                title: 'Tạo nhóm người dùng thành công',
                message: `Nhóm “${roleDetails.name}” đã được thêm vào danh sách.`,
            };
        } else if (props.moduleKey === 'roles' && wasEditing) {
            managementResult.value = {
                success: true,
                title: 'Cập nhật nhóm người dùng thành công',
                message: `Nhóm “${String(body.name ?? '')}” đã được cập nhật.`,
            };
        }
    } catch (cause) {
        formErrors.value = validationErrors(cause);
        error.value = errorMessage(cause);
        if (props.moduleKey === 'households') {
            managementResult.value = {
                success: false,
                title: 'Cập nhật phiếu điều tra thất bại',
                message: errorMessage(cause),
            };
        }
        if (props.moduleKey === 'villages') {
            deleteResult.value = {
                success: false,
                title: wasEditing ? 'Cập nhật thất bại' : 'Thêm thất bại',
                message: error.value,
            };
        }
        if (props.moduleKey === 'schools') {
            deleteResult.value = {
                success: false,
                title: wasEditing ? 'Cập nhật thất bại' : 'Thêm thất bại',
                message: error.value,
            };
        }
        if (accountDetails) {
            if (Object.keys(formErrors.value).length === 0) {
                userFormRequestError.value = `Chưa thể tạo tài khoản. ${error.value} Vui lòng thử lại.`;
            }
        } else if (props.moduleKey === 'roles') {
            roleFormRequestError.value = Object.keys(formErrors.value).length === 0
                ? `Chưa thể lưu nhóm người dùng. ${error.value} Vui lòng thử lại.`
                : '';
            managementResult.value = {
                success: false,
                title: 'Lưu nhóm người dùng thất bại',
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
    error.value = '';
    try {
        await api.delete(`${info.value.endpoint}/${id}`);
        pendingDelete.value = null;
        if (props.moduleKey === 'households') {
            selectedHouseholdIds.value = selectedHouseholdIds.value.filter(selectedId => String(selectedId) !== String(id));
        }
        deleteResult.value = {
            success: true,
            message: `Đã xóa “${String(row.name ?? row.code ?? 'mục đã chọn')}” thành công.`,
        };
        if (props.moduleKey === 'roles') {
            rows.value = rows.value.filter(item => String(item.id ?? item.code) !== String(id));
            totalRecords.value = Math.max(0, totalRecords.value - 1);
        } else if (props.moduleKey === 'villages') {
            const wasListed = rows.value.some(item => String(item.code ?? '') === String(id));
            rows.value = rows.value.filter(item => String(item.code ?? '') !== String(id));
            if (wasListed) totalRecords.value = Math.max(0, totalRecords.value - 1);
            deleteResult.value = {
                success: true,
                title: 'Xóa thành công',
                message: `Thôn/xóm “${String(row.name ?? row.code ?? '')}” đã được xóa.`,
            };
        } else if (props.moduleKey === 'schools') {
            const wasListed = rows.value.some(item => String(item.id ?? '') === String(id));
            rows.value = rows.value.filter(item => String(item.id ?? '') !== String(id));
            if (wasListed) totalRecords.value = Math.max(0, totalRecords.value - 1);
            deleteResult.value = {
                success: true,
                title: 'Xóa thành công',
                message: `Trường học “${String(row.name ?? row.code ?? '')}” đã được xóa.`,
            };
        } else if (props.moduleKey === 'households') {
            const wasListed = rows.value.some(item => String(item.id ?? '') === String(id));
            rows.value = rows.value.filter(item => String(item.id ?? '') !== String(id));
            if (wasListed) totalRecords.value = Math.max(0, totalRecords.value - 1);
            deleteResult.value = {
                success: true,
                title: 'Xóa phiếu thành công',
                message: `Phiếu điều tra “${String(row.household_code ?? '')}” đã được xóa.`,
            };
            await updateHouseholdPageAfterDelete();
        } else {
            await load();
        }
    } catch (cause) {
        pendingDelete.value = null;
        deleteResult.value = {
            success: false,
            title: ['villages', 'schools'].includes(props.moduleKey) ? 'Xóa thất bại' : undefined,
            message: `Không thể xóa mục đã chọn. ${errorMessage(cause)}`,
        };
    } finally {
        busy.value = false;
    }
}

async function changePassword(): Promise<void> {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await api.patch('/account/password', { password: password.value, password_confirmation: passwordConfirmation.value });
        password.value = '';
        passwordConfirmation.value = '';
        notice.value = 'Đổi mật khẩu thành công.';
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

function setImportFile(event: Event): void {
    const input = event.target;
    importFile.value = input instanceof HTMLInputElement ? input.files?.[0] ?? null : null;
}

async function submitImport(): Promise<void> {
    if (!importFile.value) {
        error.value = 'Vui lòng chọn file Excel.';
        return;
    }
    const data = new FormData();
    data.append('file', importFile.value);
    for (const key of ['province_code', 'commune_code', 'village_code']) {
        const value = form.value[key];
        if (value) data.append(key, value);
    }
    busy.value = true;
    error.value = '';
    try {
        await api.post('/household-imports', data);
        notice.value = 'Đã tải file lên hàng đợi xử lý.';
        importFile.value = null;
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

async function restoreTrash(row: Row): Promise<void> {
    const type = row.item_type === 'household' ? 'households' : 'persons';
    try {
        await api.patch(`/trash/${type}/${row.id}`);
        const key = trashKey(row);
        selectedTrashKeys.value = selectedTrashKeys.value.filter(selectedKey => selectedKey !== key);
        rows.value = rows.value.filter(item => trashKey(item) !== key);
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        lastPage.value = Math.max(1, Math.ceil(totalRecords.value / trashPageSize.value));
        if (currentPage.value > lastPage.value) currentPage.value = lastPage.value;
        notice.value = 'Đã khôi phục mục đã chọn.';
        deleteResult.value = {
            success: true,
            title: 'Khôi phục thành công',
            message: 'Dữ liệu đã được đưa trở lại danh sách quản lý.',
        };
    } catch (cause) {
        error.value = errorMessage(cause);
        deleteResult.value = {
            success: false,
            title: 'Khôi phục thất bại',
            message: errorMessage(cause),
        };
    }
}

async function permanentlyDeleteTrash(row: Row): Promise<void> {
    const type = row.item_type === 'household' ? 'households' : 'persons';
    try {
        await api.delete(`/trash/${type}/${row.id}`);
        const key = trashKey(row);
        selectedTrashKeys.value = selectedTrashKeys.value.filter(selectedKey => selectedKey !== key);
        rows.value = rows.value.filter(item => trashKey(item) !== key);
        totalRecords.value = Math.max(0, totalRecords.value - 1);
        lastPage.value = Math.max(1, Math.ceil(totalRecords.value / trashPageSize.value));
        if (currentPage.value > lastPage.value) currentPage.value = lastPage.value;
        notice.value = 'Đã xóa vĩnh viễn mục đã chọn.';
        deleteResult.value = {
            success: true,
            title: 'Xóa vĩnh viễn thành công',
            message: 'Dữ liệu đã được xóa khỏi thùng rác.',
        };
    } catch (cause) {
        error.value = errorMessage(cause);
        deleteResult.value = {
            success: false,
            title: 'Không thể xóa dữ liệu',
            message: errorMessage(cause),
        };
    }
}

function requestTrashRestore(row: Row): void {
    trashActionDialog.value = { operation: 'restore', row };
}

function requestTrashDelete(row: Row): void {
    trashActionDialog.value = { operation: 'force_delete', row };
}

async function confirmTrashAction(): Promise<void> {
    const action = trashActionDialog.value;
    if (!action || busy.value) return;
    trashActionDialog.value = null;
    if (action.row) {
        if (action.operation === 'restore') await restoreTrash(action.row);
        else await permanentlyDeleteTrash(action.row);
        return;
    }
    await bulkTrash(action.operation);
}

function trashKey(row: Row): string {
        return `${row.item_type ?? 'household'}-${row.id}`;
}

    function toggleTrashSelection(row: Row, checked: boolean): void {
        const key = trashKey(row);
        selectedTrashKeys.value = checked
            ? [...new Set([...selectedTrashKeys.value, key])]
            : selectedTrashKeys.value.filter(selectedKey => selectedKey !== key);
    }

    const allTrashRowsSelected = computed(() => rows.value.length > 0
        && rows.value.every(row => selectedTrashKeys.value.includes(trashKey(row))));

    function toggleAllTrashRows(checked: boolean): void {
        const pageKeys = rows.value.map(trashKey);
        selectedTrashKeys.value = checked
            ? [...new Set([...selectedTrashKeys.value, ...pageKeys])]
            : selectedTrashKeys.value.filter(key => !pageKeys.includes(key));
    }

    async function bulkTrash(operation: 'restore' | 'force_delete'): Promise<void> {
        if (!selectedTrashKeys.value.length || busy.value) return;
        const selectedKeys = [...selectedTrashKeys.value];
        const householdIds = selectedTrashKeys.value.filter(key => key.startsWith('household-')).map(key => Number(key.split('-').at(-1)));
        const personIds = selectedTrashKeys.value.filter(key => key.startsWith('person-')).map(key => Number(key.split('-').at(-1)));
        busy.value = true;
        try {
            await api.post('/trash/batch-operations', { operation, household_ids: householdIds, person_ids: personIds });
            selectedTrashKeys.value = [];
            notice.value = operation === 'restore' ? 'Đã khôi phục các mục đã chọn.' : 'Đã xóa vĩnh viễn các mục đã chọn.';
            rows.value = rows.value.filter(row => !selectedKeys.includes(trashKey(row)));
            totalRecords.value = Math.max(0, totalRecords.value - selectedKeys.length);
            lastPage.value = Math.max(1, Math.ceil(totalRecords.value / trashPageSize.value));
            if (currentPage.value > lastPage.value) currentPage.value = lastPage.value;
            deleteResult.value = {
                success: true,
                title: operation === 'restore' ? 'Khôi phục thành công' : 'Xóa vĩnh viễn thành công',
                message: operation === 'restore'
                    ? 'Các mục đã chọn đã được đưa trở lại danh sách quản lý.'
                    : 'Các mục đã chọn đã được xóa khỏi thùng rác.',
            };
        } catch (cause) {
            error.value = errorMessage(cause);
            deleteResult.value = {
                success: false,
                title: 'Không thể xử lý dữ liệu',
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
    const accountName = String(row.name ?? row.username ?? 'tài khoản này');
    accountActionDialog.value = { mode: 'confirm', action, row, accountName };
}

async function confirmAccountAction(): Promise<void> {
    const dialog = accountActionDialog.value;
    if (!dialog || dialog.mode !== 'confirm') return;

    error.value = '';
    notice.value = '';
    busy.value = true;
    try {
        const result = dialog.action === 'status'
            ? await api.patch<{ message: string }>(`/users/${dialog.row.id}/status`, {
                is_active: !dialog.row.is_active,
            })
            : await api.post<{ message: string }>(`/users/${dialog.row.id}/password-resets`);
        if (dialog.action === 'status') {
            dialog.row.is_active = !dialog.row.is_active;
        }
        accountActionDialog.value = {
            ...dialog,
            mode: 'result',
            success: true,
            message: result.data.message,
        };
    } catch (cause) {
        accountActionDialog.value = {
            ...dialog,
            mode: 'result',
            success: false,
            message: errorMessage(cause),
        };
    } finally {
        busy.value = false;
    }
}

function accountActionTitle(dialog: AccountActionDialog): string {
    if (dialog.action === 'password') {
        return dialog.mode === 'confirm' ? 'Xác nhận đặt lại mật khẩu' : 'Kết quả đặt lại mật khẩu';
    }

    return dialog.mode === 'confirm'
        ? (dialog.row.is_active ? 'Xác nhận vô hiệu hóa tài khoản' : 'Xác nhận kích hoạt tài khoản')
        : 'Kết quả cập nhật tài khoản';
}

function accountActionMessage(dialog: AccountActionDialog): string {
    if (dialog.mode === 'result') {
        return dialog.success ? dialog.message ?? '' : `Thao tác thất bại: ${dialog.message ?? 'Vui lòng thử lại.'}`;
    }

    return dialog.action === 'password'
        ? `Bạn có chắc muốn đặt lại mật khẩu cho tài khoản “${dialog.accountName}”?`
        : `Bạn có chắc muốn ${dialog.row.is_active ? 'vô hiệu hóa' : 'kích hoạt'} tài khoản “${dialog.accountName}”?`;
}

async function changeUserPageSize(): Promise<void> {
    if (busy.value) return;
    currentPage.value = 1;
    if (rows.value.length >= totalRecords.value && lastPage.value === 1) return;
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
    currentPage.value = Math.min(lastPage.value, Math.max(1, currentPage.value + direction));
    await load();
}

watch(() => props.moduleKey, () => {
    selectedHouseholdIds.value = [];
    if (props.moduleKey !== 'households') {
        householdDetail.value = null;
        householdListRow.value = null;
        selectedHouseholdPerson.value = null;
    }
    configurationFormOpen.value = false;
    selected.value = null;
    form.value = {};
    notice.value = '';
    pendingDelete.value = null;
    if (props.moduleKey === 'users') canEditAccountConfigurations.value = false;
    if (props.moduleKey !== 'users') {
        userFiltersInitialized.value = false;
        userFilterProvince.value = '';
        userFilterCommune.value = '';
        userFilterVillage.value = '';
        userFilterRole.value = '';
        userFilterQuery.value = '';
    }
    void load();
});

watch(() => props.householdFilters, () => {
    if (props.moduleKey === 'households') {
        selectedHouseholdIds.value = [];
        currentPage.value = 1;
        void load();
    }
}, { deep: true });

watch(() => props.villageFilters, () => {
    if (props.moduleKey === 'villages') {
        currentPage.value = 1;
        void load();
    }
}, { deep: true });

const previousBodyOverflow = document.body.style.overflow;
watch(configurationFormOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : previousBodyOverflow;
});

onUnmounted(() => {
    document.body.style.overflow = previousBodyOverflow;
});

onMounted(() => void load());
</script>

<template>
    <section
        class="workspace"
        :class="{
            'compact-management-workspace': isCompactManagement,
            'user-management-workspace': moduleKey === 'users',
            'workspace-imports': moduleKey === 'imports',
        }"
        :aria-busy="busy"
        :aria-label="['users', 'villages', 'schools'].includes(moduleKey) ? info.title : undefined"
    >
        <header v-if="!['users', 'roles', 'villages', 'schools', 'households', 'trash', 'account', 'imports'].includes(moduleKey)" class="section-heading" :class="{ 'compact-section-heading': isCompactManagement }">
            <div v-if="moduleKey !== 'villages'" class="workspace-title">
                <span v-if="moduleKey !== 'roles'" class="workspace-title-mark" aria-hidden="true">{{ info.title.slice(0, 1) }}</span>
                <div>
                    <p v-if="!isCompactManagement" class="workspace-eyebrow">QUẢN LÝ DANH MỤC</p>
                    <h2>{{ info.title }}</h2>
                    <p v-if="!isCompactManagement" class="muted">{{ sectionDescription }}</p>
                </div>
            </div>
            <div v-if="!isCompactManagement" class="pagination-actions">
                <span v-if="rows.length && moduleKey !== 'trash'" class="record-count">{{ rows.length }} bản ghi</span>
                <span v-else-if="moduleKey === 'trash'" class="record-count">{{ rows.length }} / {{ totalRecords }} mục</span>
                <button class="secondary-button" type="button" @click="emit('close')">Quay lại</button>
                <button
                    v-if="canCreate && ['households', 'schools', 'villages', 'roles', 'users'].includes(moduleKey)"
                    class="primary-button"
                    type="button"
                    @click="beginEdit()"
                >Thêm mới</button>
            </div>
        </header>

        <form v-if="moduleKey === 'schools'" class="village-unified-filter school-unified-filter" role="search" @submit.prevent="applySchoolFilters">
            <label>
                <span>Tỉnh / thành phố</span>
                <select v-model="schoolFilterProvince" @change="changeSchoolFilterProvince">
                    <option value="">Tất cả địa bàn</option>
                    <option v-for="province in provinces" :key="String(province.code)" :value="String(province.code)">{{ province.name }}</option>
                </select>
            </label>
            <label>
                <span>Xã / phường</span>
                <select v-model="schoolFilterCommune" :disabled="!schoolFilterProvince" @change="applySchoolFilters">
                    <option value="">Tất cả xã / phường</option>
                    <option v-for="commune in schoolFilterCommunes" :key="String(commune.code)" :value="String(commune.code)">{{ commune.name }}</option>
                </select>
            </label>
            <label>
                <span>Cấp học</span>
                <select v-model="schoolFilterLevel" @change="applySchoolFilters">
                    <option value="">Tất cả cấp học</option>
                    <option value="MN">Mầm non</option>
                    <option value="TH">Tiểu học</option>
                    <option value="THCS">Trung học cơ sở</option>
                    <option value="THPT">Trung học phổ thông</option>
                </select>
            </label>
            <label class="village-unified-search">
                <span>Tìm theo mã hoặc tên</span>
                <span class="village-search-control">
                    <input v-model="schoolFilterQuery" type="search" placeholder="Nhập mã hoặc tên trường">
                    <button class="secondary-button management-tool-button user-search-button" type="submit" :disabled="busy" aria-label="Tìm trường học" title="Tìm trường học">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
                    </button>
                </span>
            </label>
        </form>
        <div v-if="moduleKey === 'schools'" class="village-management-toolbar-bottom">
            <span class="record-count">{{ rows.length }} / {{ totalRecords }} bản ghi</span>
            <button
                v-if="canCreate"
                class="primary-button management-tool-button"
                type="button"
                :disabled="busy"
                @click="beginEdit()"
            >Thêm mới</button>
        </div>

        <form
            v-if="moduleKey === 'households'"
            class="village-unified-filter household-unified-filter"
            role="search"
            @submit.prevent="applyHouseholdFilters"
        >
            <label>
                <span>Tìm theo mã phiếu hoặc tên chủ hộ</span>
                <span class="village-search-control">
                    <input v-model.trim="householdQuery" type="search" placeholder="Nhập mã phiếu hoặc tên chủ hộ">
                    <button class="secondary-button management-tool-button user-search-button" type="submit" :disabled="busy" aria-label="Tìm phiếu điều tra" title="Tìm phiếu điều tra">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
                    </button>
                </span>
            </label>
            <label>
                <span>Năm học</span>
                <select v-model="householdSchoolYear" aria-label="Lọc theo năm học" @change="applyHouseholdFilters">
                    <option value="">Năm học hiện tại</option>
                    <option value="all">Tất cả năm học</option>
                    <option v-for="schoolYear in householdSchoolYearOptions" :key="schoolYear" :value="schoolYear">{{ schoolYear }}</option>
                </select>
            </label>
            <button
                v-if="householdQuery || householdSchoolYear"
                class="secondary-button management-tool-button household-clear-filter"
                type="button"
                :disabled="busy"
                @click="householdQuery = ''; householdSchoolYear = ''; applyHouseholdFilters()"
            >Xóa lọc</button>
        </form>
        <div v-if="moduleKey === 'households'" class="village-management-toolbar-bottom household-management-toolbar-bottom">
            <span class="record-count">{{ rows.length }} / {{ totalRecords }} bản ghi</span>
            <div class="household-list-actions">
                <button
                    v-if="canDelete && selectedHouseholdIds.length"
                    class="secondary-button danger-button management-tool-button household-bulk-delete-button"
                    type="button"
                    :disabled="busy"
                    @click="pendingHouseholdBulkDelete = true"
                >
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"/></svg>
                    Xóa đã chọn ({{ selectedHouseholdIds.length }})
                </button>
                <button
                    v-if="permissions.includes('import-households')"
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="emit('navigate', 'imports')"
                >Import Excel</button>
                <button
                    v-if="permissions.includes('export-households')"
                    class="secondary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="emit('navigate', 'exports')"
                >Xuất Excel</button>
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    :disabled="busy"
                    @click="beginEdit()"
                >Thêm mới</button>
            </div>
        </div>

        <slot v-if="moduleKey === 'villages'" name="toolbar" />
        <div v-if="moduleKey === 'villages'" class="village-management-toolbar-bottom">
            <span class="record-count">{{ rows.length }} / {{ totalRecords }} bản ghi</span>
            <button
                v-if="canCreate"
                class="primary-button management-tool-button"
                type="button"
                :disabled="busy"
                @click="beginEdit()"
            >Thêm mới</button>
        </div>

        <div
            v-if="isCompactManagement"
            class="management-toolbar"
            :class="{
                'user-management-toolbar': moduleKey === 'users',
                'role-management-toolbar': moduleKey === 'roles',
                'trash-management-toolbar-wrapper': moduleKey === 'trash',
            }"
        >
            <div v-if="moduleKey === 'users'" class="user-location-filters">
                <label v-if="showUserProvinceFilter">
                    <span>Tỉnh</span>
                    <select v-model="userFilterProvince" aria-label="Lọc theo tỉnh / thành phố" @change="changeUserProvince">
                        <option value="">Tất cả địa bàn</option>
                        <option v-for="province in provinces" :key="String(province.code)" :value="String(province.code)">{{ province.name }}</option>
                    </select>
                </label>
                <label>
                    <span>Nhóm quyền</span>
                    <select v-model="userFilterRole" aria-label="Lọc theo nhóm người dùng" @change="changeUserRole">
                        <option value="">Tất cả nhóm người dùng</option>
                        <option v-for="role in userFilterRoles" :key="String(role.id)" :value="String(role.id)">{{ role.name }}</option>
                    </select>
                </label>
                <label v-if="showUserCommuneFilter">
                    <span>Xã</span>
                    <select v-model="userFilterCommune" aria-label="Lọc theo xã / phường" :disabled="!userFilterProvince" @change="changeUserCommune">
                        <option value="">Tất cả xã / phường</option>
                        <option v-for="commune in userFilterCommunes" :key="String(commune.code)" :value="String(commune.code)">{{ commune.name }}</option>
                    </select>
                </label>
                <label v-if="showUserVillageFilter">
                    <span>Thôn</span>
                    <select v-model="userFilterVillage" aria-label="Lọc theo thôn / xóm" :disabled="!userFilterCommune" @change="changeUserVillage">
                        <option value="">Tất cả thôn / xóm</option>
                        <option v-for="village in userFilterVillages" :key="String(village.code)" :value="String(village.code)">{{ village.name }}</option>
                    </select>
                </label>
                <form class="user-search-control" role="search" @submit.prevent="searchUsers">
                    <input
                        v-model.trim="userFilterQuery"
                        type="search"
                        aria-label="Tìm theo tên cán bộ"
                        placeholder="Tìm tên cán bộ…"
                    >
                    <button class="secondary-button management-tool-button user-search-button" type="submit" :disabled="busy" aria-label="Tìm cán bộ" title="Tìm cán bộ">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
                    </button>
                </form>
            </div>
            <div v-if="moduleKey === 'users'" class="user-management-toolbar-bottom">
                <div class="user-management-toolbar-summary">
                    <span class="record-count">{{ rows.length }} / {{ totalRecords }} bản ghi</span>
                </div>
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    @click="beginEdit()"
                >Thêm mới</button>
            </div>
            <template v-else-if="moduleKey === 'roles'">
                <div class="role-management-toolbar-bottom">
                    <span class="record-count">{{ totalRecords }} nhóm quyền</span>
                    <button
                        v-if="canCreate"
                        class="primary-button management-tool-button"
                        type="button"
                        :disabled="busy"
                        @click="beginEdit()"
                    >Thêm mới</button>
                </div>
            </template>
            <template v-else-if="moduleKey === 'trash'">
                <div class="trash-management-toolbar">
                    <label>
                        <span>Loại dữ liệu</span>
                        <select v-model="trashFilterType" @change="applyTrashFilters">
                            <option value="household">Phiếu điều tra</option>
                            <option value="person">Nhân khẩu</option>
                        </select>
                    </label>
                    <form class="trash-search-control" role="search" @submit.prevent="applyTrashFilters">
                        <span>Tìm kiếm</span>
                        <input v-model.trim="trashFilterQuery" type="search" placeholder="Mã phiếu hoặc tên nhân khẩu…" @keyup.enter="applyTrashFilters">
                        <button class="secondary-button management-tool-button trash-search-button" type="submit" :disabled="busy" aria-label="Tìm dữ liệu đã xóa" title="Tìm dữ liệu đã xóa">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
                        </button>
                    </form>
                </div>
                <div class="trash-management-toolbar-bottom">
                    <div class="trash-management-summary">
                        <span class="record-count">{{ rows.length }} / {{ totalRecords }} mục</span>
                        <button v-if="trashFilterType !== 'household' || trashFilterQuery" class="secondary-button management-tool-button" type="button" :disabled="busy" @click="trashFilterType = 'household'; trashFilterQuery = ''; applyTrashFilters()">
                            Xóa bộ lọc
                        </button>
                    </div>
                    <div v-if="selectedTrashKeys.length" class="trash-bulk-actions">
                        <span class="trash-selected-count">Đã chọn {{ selectedTrashKeys.length }}</span>
                        <button class="secondary-button management-tool-button trash-bulk-button" type="button" :disabled="busy" title="Khôi phục mục đã chọn" aria-label="Khôi phục mục đã chọn" @click="trashActionDialog = { operation: 'restore' }">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.6"/><path d="M4 4v4.6h4.6"/></svg>
                        </button>
                        <button class="secondary-button management-tool-button trash-bulk-button danger-button" type="button" :disabled="busy" title="Xóa vĩnh viễn mục đã chọn" aria-label="Xóa vĩnh viễn mục đã chọn" @click="trashActionDialog = { operation: 'force_delete' }">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg>
                        </button>
                    </div>
                </div>
            </template>
            <div v-else class="management-toolbar-actions">
                <span class="record-count">{{ rows.length }} / {{ totalRecords }} bản ghi</span>
                <div v-if="lastPage > 1" class="pagination-actions compact-pagination" aria-label="Phân trang danh sách">
                    <button class="secondary-button management-tool-button" type="button" :disabled="currentPage <= 1 || busy" @click="changePage(-1)">Trước</button>
                    <span class="muted">Trang {{ currentPage }} / {{ lastPage }}</span>
                    <button class="secondary-button management-tool-button" type="button" :disabled="currentPage >= lastPage || busy" @click="changePage(1)">Sau</button>
                </div>
                <button
                    v-if="canCreate"
                    class="primary-button management-tool-button"
                    type="button"
                    @click="beginEdit()"
                >Thêm mới</button>
            </div>
        </div>

        <p v-if="error && !hasConfigurationForm" class="notice" role="alert">{{ error }}</p>
        <p v-if="notice && moduleKey !== 'trash'" class="success-notice" role="status">{{ notice }}</p>

        <div
            v-if="hasConfigurationForm"
            :class="{ 'workspace-modal-backdrop': isDialogForm }"
            :role="isDialogForm ? 'presentation' : undefined"
            @keydown.esc="closeConfigurationForm"
        >
        <form
            class="workspace-form"
            :class="{
                'workspace-modal-card': isDialogForm,
                'role-configuration-form': moduleKey === 'roles',
                'village-configuration-form': ['villages', 'schools'].includes(moduleKey),
                'household-configuration-form': moduleKey === 'households',
            }"
            :novalidate="moduleKey === 'users'"
            :role="isDialogForm ? 'dialog' : undefined"
            :aria-modal="isDialogForm ? 'true' : undefined"
            :aria-labelledby="isDialogForm ? 'configuration-form-title' : undefined"
            @submit.prevent="save"
        >
            <div class="workspace-form-heading">
                <h3 :id="isDialogForm ? 'configuration-form-title' : undefined">{{ editing ? 'Cập nhật' : 'Tạo mới' }} {{ info.title.toLocaleLowerCase('vi') }}</h3>
                <button
                    v-if="isDialogForm"
                    class="modal-icon-button"
                    type="button"
                    aria-label="Đóng biểu mẫu"
                    @click="closeConfigurationForm"
                >×</button>
            </div>
            <ol v-if="moduleKey === 'households' && !editing" class="household-create-wizard" aria-label="Tiến trình nhập phiếu điều tra">
                <li :class="{ active: createHouseholdWizardStep === 1, complete: createHouseholdWizardStep > 1 }">
                    <button type="button" @click="selectCreateHouseholdStep(1)"><span>1</span><b>Thông tin hộ gia đình</b></button>
                </li>
                <li :class="{ active: createHouseholdWizardStep === 2, complete: createHouseholdWizardStep > 2 }">
                    <button type="button" @click="selectCreateHouseholdStep(2)"><span>2</span><b>Thông tin chủ hộ</b></button>
                </li>
                <li :class="{ active: createHouseholdWizardStep === 3 }">
                    <button type="button" @click="selectCreateHouseholdStep(3)"><span>3</span><b>Thành viên</b></button>
                </li>
            </ol>
            <p v-if="error && hasConfigurationForm && !['users', 'roles', 'households'].includes(moduleKey)" class="notice" role="alert">{{ error }}</p>
            <p v-if="moduleKey === 'roles' && roleFormRequestError" class="field-error role-form-request-error" role="alert">{{ roleFormRequestError }}</p>
            <template
                v-for="field in fieldsForForm.filter(field => !(moduleKey === 'merge' && field === 'source_codes') && !(moduleKey === 'households' && !editing && (field === 'household_code' || createHouseholdWizardStep !== 1)) && shouldShowFormField(field))"
                :key="field"
            >
            <h4 v-if="moduleKey === 'households' && (['household_code', 'province_code'].includes(field) || !editing && field === 'school_year')" class="household-form-section-heading">
                {{ field === 'province_code' ? 'Thông tin nơi đang ở' : 'Thông tin đối tượng và chủ hộ' }}
            </h4>
            <label class="workspace-field" :class="{ 'household-wide-field': moduleKey === 'households' && ['address', 'residence_status'].includes(field) }">
                <span>{{ fieldLabel(field) }}</span>
                <select v-if="field === 'level'" v-model="form[field]">
                    <option value="">Chọn cấp học</option>
                    <option value="MN">Mầm non</option>
                    <option value="TH">Tiểu học</option>
                    <option value="THCS">Trung học cơ sở</option>
                    <option value="THPT">Trung học phổ thông</option>
                </select>
                <select v-else-if="field === 'scope_level'" v-model="form[field]" :required="moduleKey === 'users' || moduleKey === 'roles'" :aria-invalid="Boolean(formErrors[field] || userFormValidationErrors[field])">
                    <option value="">Chọn cấp quản lý</option>
                    <option v-for="level in moduleKey === 'users' ? accountLevels : roleScopeLevels" :key="level" :value="level">{{ scopeLabels[level] }}</option>
                </select>
                <select v-else-if="field === 'role_id'" v-model="form[field]" :required="moduleKey === 'users'" :disabled="formOptionsBusy" :aria-invalid="Boolean(formErrors[field] || userFormValidationErrors[field])">
                    <option value="">Chọn nhóm quyền</option>
                    <option
                        v-for="role in moduleKey === 'users' ? assignableUserRoles : roleOptions"
                        :key="String(role.id)"
                        :value="String(role.id)"
                        :disabled="role.is_primary_admin === true && availablePrimaryAdminScopes[form.scope_level] === false"
                    >{{ role.name }}{{ role.is_primary_admin === true && availablePrimaryAdminScopes[form.scope_level] === false ? ' (đã đủ quản trị viên)' : '' }}</option>
                </select>
                <select
                    v-else-if="field === 'province_code' && (provinces.length || moduleKey === 'users') && moduleKey !== 'households'"
                    v-model="form[field]"
                    :required="moduleKey === 'villages' || moduleKey === 'users' && form.scope_level !== 'global'"
                    @change="(moduleKey === 'villages' || moduleKey === 'users') && changeFormProvince()"
                >
                    <option value="">Chọn tỉnh / thành phố</option>
                    <option v-for="province in provinces" :key="String(province.code)" :value="String(province.code)">{{ province.name }}</option>
                </select>
                <select
                    v-else-if="field === 'commune_code' && (moduleKey === 'villages' || moduleKey === 'users')"
                    v-model="form[field]"
                    :required="moduleKey === 'villages' || moduleKey === 'users' && ['commune', 'village'].includes(form.scope_level)"
                    :disabled="moduleKey === 'villages' && !form.province_code || moduleKey === 'users' && (!form.province_code || formOptionsBusy)"
                >
                    <option value="">Chọn xã / phường</option>
                    <option v-for="commune in formCommunes" :key="String(commune.code)" :value="String(commune.code)">{{ commune.name }}</option>
                </select>
                <select v-else-if="field === 'residence_type'" v-model="form[field]" :required="moduleKey === 'households'">
                    <option value="THUONG_TRU">Thường trú</option>
                    <option value="TAM_TRU">Tạm trú</option>
                    <option value="KHAC">Khác</option>
                </select>
                <select v-else-if="moduleKey === 'households' && field === 'school_year'" v-model="form[field]" required>
                    <option value="">Chọn năm học</option>
                    <option v-for="year in householdFormSchoolYears" :key="year" :value="year">{{ year }}</option>
                </select>
                <select
                    v-else-if="moduleKey === 'households' && field === 'province_code'"
                    v-model="form[field]"
                    required
                    @change="changeFormProvince"
                >
                    <option value="">Chọn tỉnh / thành phố</option>
                    <option v-for="province in provinces" :key="String(province.code)" :value="String(province.code)">{{ province.name }}</option>
                </select>
                <select
                    v-else-if="moduleKey === 'households' && field === 'commune_code'"
                    v-model="form[field]"
                    required
                    :disabled="!form.province_code"
                    @change="changeHouseholdFormCommune"
                >
                    <option value="">Chọn xã / phường</option>
                    <option v-for="commune in formCommunes" :key="String(commune.code)" :value="String(commune.code)">{{ commune.name }}</option>
                </select>
                <select
                    v-else-if="moduleKey === 'households' && field === 'village_code'"
                    v-model="form[field]"
                    required
                    :disabled="!form.commune_code || formOptionsBusy"
                >
                    <option value="">Chọn thôn / xóm</option>
                    <option v-for="village in formVillages" :key="String(village.code)" :value="String(village.code)">{{ village.name }}</option>
                </select>
                <input
                    v-else
                    v-model="form[field]"
                    :type="field.includes('password') ? 'password' : 'text'"
                    :aria-invalid="Boolean(formErrors[field] || userFormValidationErrors[field] || field === 'username' && userFormRequestError)"
                    :required="moduleKey === 'households' && ['household_code', 'head_first_name', 'head_last_name'].includes(field)
                        || moduleKey === 'villages' && field === 'name'
                        || ['name', 'username', 'role_id', 'scope_level'].includes(field)
                        || moduleKey === 'users' && field === 'village_codes' && form.scope_level === 'village'"
                >
                <small v-if="formErrors[field]" class="field-error" role="alert">{{ formErrors[field] }}</small>
                <small v-else-if="userFormValidationErrors[field]" class="field-error" role="alert">{{ userFormValidationErrors[field] }}</small>
                <small v-if="field === 'username' && userFormRequestError" class="field-error" role="alert">{{ userFormRequestError }}</small>
            </label>
            </template>
            <p v-if="moduleKey === 'users' && formOptionsBusy" class="muted" role="status">Đang tải nhóm quyền và địa bàn…</p>
            <p v-else-if="moduleKey === 'users' && primaryAdminUnavailableMessage" class="notice" role="status">{{ primaryAdminUnavailableMessage }}</p>
            <p v-else-if="moduleKey === 'users' && form.scope_level && !assignableUserRoles.length" class="muted" role="status">Không có nhóm quyền phù hợp với cấp quản lý đã chọn.</p>
            <label v-if="moduleKey === 'merge'" class="workspace-field">
                <span>Thôn nguồn (chọn ít nhất một thôn khác thôn đích)</span>
                <div class="permission-options">
                    <label v-for="village in rows" :key="String(village.code)">
                        <input v-model="selectedSourceCodes" type="checkbox" :value="String(village.code)" :disabled="form.target_code === String(village.code)">
                        {{ village.name }} · {{ village.code }}
                    </label>
                </div>
            </label>
            <label v-if="moduleKey === 'users'" class="workspace-field">
                <span>Mật khẩu {{ editing ? '(để trống nếu không đổi)' : '' }}</span>
                <div class="password-input-control">
                    <input
                        v-model="form.password"
                        :type="showUserPassword ? 'text' : 'password'"
                        :required="!editing"
                        :aria-invalid="Boolean(formErrors.password || userFormValidationErrors.password)"
                    >
                    <button
                        class="password-visibility-button"
                        type="button"
                        :aria-label="showUserPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                        :aria-pressed="showUserPassword"
                        @click="showUserPassword = !showUserPassword"
                    >{{ showUserPassword ? 'Ẩn' : 'Hiện' }}</button>
                </div>
                <small v-if="formErrors.password || userFormValidationErrors.password" class="field-error" role="alert">{{ formErrors.password || userFormValidationErrors.password }}</small>
            </label>
            <label v-if="moduleKey === 'users' && (!editing || form.password)" class="workspace-field">
                <span>Nhập lại mật khẩu</span>
                <div class="password-input-control">
                    <input
                        v-model="form.password_confirmation"
                        :type="showUserPasswordConfirmation ? 'text' : 'password'"
                        :aria-invalid="Boolean(formErrors.password_confirmation || userFormValidationErrors.password_confirmation)"
                    >
                    <button
                        class="password-visibility-button"
                        type="button"
                        :aria-label="showUserPasswordConfirmation ? 'Ẩn mật khẩu xác nhận' : 'Hiện mật khẩu xác nhận'"
                        :aria-pressed="showUserPasswordConfirmation"
                        @click="showUserPasswordConfirmation = !showUserPasswordConfirmation"
                    >{{ showUserPasswordConfirmation ? 'Ẩn' : 'Hiện' }}</button>
                </div>
                <small v-if="formErrors.password_confirmation || userFormValidationErrors.password_confirmation" class="field-error" role="alert">{{ formErrors.password_confirmation || userFormValidationErrors.password_confirmation }}</small>
            </label>
            <div v-if="moduleKey === 'roles'" class="workspace-field role-permissions-field">
                <span>Ma trận phân quyền</span>
                <div class="permission-category-list">
                    <section v-for="group in scopedPermissionGroups" :key="group.key" class="permission-category">
                        <header class="permission-category-heading">
                            <div>
                                <h4>{{ group.label }}</h4>
                                <span>{{ permissionGroupState(group.key).selected }} / {{ permissionGroupState(group.key).total }} quyền đã chọn</span>
                            </div>
                            <label class="permission-select-all">
                                <input
                                    type="checkbox"
                                    :checked="permissionGroupState(group.key).allSelected"
                                    :aria-label="`Chọn tất cả quyền trong nhóm ${group.label}`"
                                    @change="togglePermissionGroup(group.key, ($event.target as HTMLInputElement).checked)"
                                >
                                Chọn tất cả
                            </label>
                        </header>
                        <div class="permission-options role-permission-options">
                            <label v-for="permission in group.options" :key="String(permission.id)">
                                <input v-model="permissionIds" type="checkbox" :value="String(permission.id)">
                                <span>{{ permission.label ?? permissionLabel(permissionName(permission)) }}</span>
                            </label>
                        </div>
                    </section>
                    <p v-if="scopedPermissionGroups.length === 0" class="permission-matrix-empty">
                        Chọn cấp quản lý để xem các quyền có thể cấp cho nhóm này.
                    </p>
                </div>
                <small v-if="rolePermissionError || roleFormValidationErrors.permissions" class="field-error" role="alert">
                    {{ rolePermissionError || roleFormValidationErrors.permissions }}
                </small>
            </div>
            <p v-if="moduleKey === 'villages'" class="muted">Mã thôn / xóm sẽ tự sinh theo cấu trúc [Mã xã]_[Số thứ tự], ví dụ C01_001. Mã cũ được giữ nguyên.</p>
            <label v-if="moduleKey === 'users'" class="workspace-field">
                <span>Trạng thái</span>
                <select v-model="form.is_active"><option value="1">Đang hoạt động</option><option value="0">Vô hiệu hóa</option></select>
            </label>
            <p v-if="moduleKey === 'roles' && !roleFormReady" class="muted form-validation-hint role-validation-hint" role="status">
                Hoàn tất thông tin nhóm và chọn ít nhất một quyền để bật nút lưu.
            </p>
            <section v-if="moduleKey === 'households' && editing" class="household-edit-members">
                <div class="household-edit-members-heading">
                    <div>
                        <span>THEO MẪU EXCEL</span>
                        <h4>Danh sách nhân khẩu</h4>
                    </div>
                    <button
                        v-if="permissions.includes('create-households')"
                        class="secondary-button"
                        type="button"
                        @click="addInlineHouseholdMember"
                    >+ Thêm nhân khẩu</button>
                </div>
                <div class="household-edit-members-table-wrap">
                    <table class="household-edit-members-table">
                        <colgroup>
                            <col class="member-col-stt">
                            <col class="member-col-name">
                            <col class="member-col-short">
                            <col class="member-col-medium">
                            <col class="member-col-date">
                            <col class="member-col-gender">
                            <col class="member-col-select">
                            <col class="member-col-medium">
                            <col class="member-col-medium">
                            <col class="member-col-medium">
                            <col class="member-col-phone">
                            <col class="member-col-medium">
                            <col class="member-col-small" v-for="column in 29" :key="`member-col-${column}`">
                            <col class="member-col-actions">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>STT</th>
                                <th>Họ và tên đệm</th>
                                <th>Tên</th>
                                <th>Quan hệ</th>
                                <th>Ngày sinh</th>
                                <th>Giới tính</th>
                                <th>Dân tộc</th>
                                <th>Tôn giáo</th>
                                <th>Diện ưu tiên</th>
                                <th>Cha/mẹ hoặc người thân</th>
                                <th>Số điện thoại</th>
                                <th>Ghi chú</th>
                                <th>Năm học</th>
                                <th>Khối học</th>
                                <th>Lớp đang học</th>
                                <th>Trường đang học</th>
                                <th>Tốt nghiệp văn hóa</th>
                                <th>Bổ túc</th>
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
                            <tr v-for="(person, index) in householdEditPersons" :key="person.id === undefined ? `new-${index}` : String(person.id)">
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
                                    >
                                </td>
                                <td>
                                    <input
                                        v-model="person.first_name"
                                        :name="`members[${index}][first_name]`"
                                        type="text"
                                        maxlength="100"
                                        aria-label="Tên"
                                    >
                                </td>
                                <td>
                                    <select v-model="person.relationship_with_head" :name="`members[${index}][relationship_with_head]`">
                                        <option value="Chủ hộ">Chủ hộ</option>
                                        <option v-for="relationship in memberRelationships" :key="relationship" :value="relationship">{{ relationship }}</option>
                                    </select>
                                </td>
                                <td>
                                    <input
                                        v-model="person.dob"
                                        :name="`members[${index}][dob]`"
                                        type="date"
                                        aria-label="Ngày sinh"
                                    >
                                </td>
                                <td>
                                    <select v-model="person.gender" :name="`members[${index}][gender]`">
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
                                        <option value="">Chọn dân tộc</option>
                                        <option v-for="ethnicity in ethnicities" :key="String(ethnicity.code)" :value="String(ethnicity.code)">{{ ethnicity.name }}</option>
                                    </select>
                                </td>
                                <td><input v-model="person.religion" maxlength="50" aria-label="Tôn giáo"></td>
                                <td><input v-model="person.priority_type" maxlength="100" aria-label="Diện ưu tiên"></td>
                                <td><input v-model="person.parent_name" maxlength="150" aria-label="Cha/mẹ hoặc người thân"></td>
                                <td><input v-model="person.phone" maxlength="20" aria-label="Số điện thoại"></td>
                                <td><input v-model="person.note" maxlength="2000" aria-label="Ghi chú"></td>
                                <td><input v-model="inlineEducation(person).school_year" maxlength="20" aria-label="Năm học"></td>
                                <td><input v-model="inlineEducation(person).academic_block" maxlength="50" aria-label="Khối học"></td>
                                <td><input v-model="inlineEducation(person).current_class" maxlength="50" aria-label="Lớp đang học"></td>
                                <td><input v-model="inlineEducation(person).school_code" maxlength="50" aria-label="Trường đang học"></td>
                                <td>
                                    <select v-model="inlineEducation(person).graduation_level" aria-label="Tốt nghiệp văn hóa">
                                        <option value="">Chưa chọn</option>
                                        <option value="MN">MN</option>
                                        <option value="TH">TH</option>
                                        <option value="THCS">THCS</option>
                                        <option value="THPT">THPT</option>
                                    </select>
                                </td>
                                <td class="household-inline-checkbox"><input v-model="inlineEducation(person).is_complementary" type="checkbox" aria-label="Bổ túc"></td>
                                <td><input v-model="inlineEducation(person).graduation_year" maxlength="20" aria-label="Năm tốt nghiệp văn hóa"></td>
                                <td><input v-model="inlineEducation(person).vocational_grad_level" maxlength="50" aria-label="Tốt nghiệp nghề"></td>
                                <td><input v-model="inlineEducation(person).vocational_grad_year" maxlength="20" aria-label="Năm tốt nghiệp nghề"></td>
                                <td><input v-model="inlineEducation(person).finished_class" maxlength="20" aria-label="Lớp dở dang"></td>
                                <td><input v-model="inlineEducation(person).finished_year" maxlength="20" aria-label="Năm dở dang"></td>
                                <td><input v-model="inlineEducation(person).dropped_class" maxlength="20" aria-label="Lớp bỏ học"></td>
                                <td><input v-model="inlineEducation(person).dropped_year" maxlength="20" aria-label="Năm bỏ học"></td>
                                <td><input v-model="inlineEducation(person).literacy_current_class" maxlength="20" aria-label="Lớp mù chữ đang học"></td>
                                <td><input v-model="inlineEducation(person).literacy_completed_class" maxlength="20" aria-label="Lớp đã hoàn thành"></td>
                                <td><input v-model="inlineEducation(person).literacy_relapse_level" maxlength="2" aria-label="Mức tái mù chữ"></td>
                                <td><input v-model="inlineEducation(person).learning_capacity" maxlength="50" aria-label="Năng lực học tập"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).mobility_disability" type="checkbox" aria-label="Khuyết tật vận động"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).hearing_speech_disability" type="checkbox" aria-label="Khuyết tật nghe nói"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).visual_disability" type="checkbox" aria-label="Khuyết tật thị giác"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).mental_disability" type="checkbox" aria-label="Khuyết tật thần kinh"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).intellectual_disability" type="checkbox" aria-label="Khuyết tật trí tuệ"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).learning_disability" type="checkbox" aria-label="Khuyết tật học tập"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).autism" type="checkbox" aria-label="Tự kỷ"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).other_disability" type="checkbox" aria-label="Khuyết tật khác"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).has_disability_cert" type="checkbox" aria-label="Có giấy xác nhận khuyết tật"></td>
                                <td class="household-inline-checkbox"><input v-model="inlineDisability(person).can_study" type="checkbox" aria-label="Có khả năng học"></td>
                                <td>
                                    <select v-model="inlineDisability(person).special_circumstance" aria-label="Hoàn cảnh đặc biệt">
                                        <option value="">Không</option>
                                        <option value="Chuyển đến">Chuyển đến</option>
                                        <option value="Chuyển đi">Chuyển đi</option>
                                    </select>
                                </td>
                                <td><input v-model="inlineDisability(person).special_circumstance_detail" maxlength="2000" aria-label="Chi tiết hoàn cảnh đặc biệt"></td>
                                <td class="household-inline-member-actions">
                                    <button
                                        v-if="!householdDetailReadOnly && permissions.includes('update-households')"
                                        class="household-edit-member-button"
                                        type="button"
                                        :disabled="memberBusy || !inlineHouseholdPersonChanged(person, index)"
                                        @click="saveInlineHouseholdMember(person, index)"
                                    >Lưu</button>
                                    <button
                                        v-if="permissions.includes('delete-households')"
                                        class="household-edit-member-button household-edit-member-delete"
                                        type="button"
                                        :disabled="memberBusy"
                                        @click="person.id === undefined ? householdEditPersons.splice(index, 1) : deleteMember(person)"
                                    >Xóa</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section v-if="moduleKey === 'households' && !editing && createHouseholdWizardStep === 2" class="household-create-wizard-step">
                <span class="household-create-step-icon" aria-hidden="true">2</span>
                <div>
                    <h4>Nhập thông tin chủ hộ</h4>
                    <p>Thông tin hộ đã được lưu. Tiếp tục nhập chủ hộ làm nhân khẩu đầu tiên của phiếu.</p>
                </div>
                <button
                    class="primary-button"
                    type="button"
                    :disabled="busy"
                    @click="beginMember(true)"
                >Nhập thông tin chủ hộ</button>
            </section>
            <section v-if="moduleKey === 'households' && !editing && createHouseholdWizardStep === 3" class="household-create-wizard-step">
                <span class="household-create-step-icon" aria-hidden="true">3</span>
                <div>
                    <h4>Hoàn tất thông tin các thành viên</h4>
                    <p>Chủ hộ đã được lưu. Thêm các thành viên còn lại hoặc hoàn tất phiếu.</p>
                </div>
                <button
                    class="primary-button"
                    type="button"
                    :disabled="busy"
                    @click="beginMember(false)"
                >Thêm thành viên</button>
            </section>
            <div class="pagination-actions" :class="{ 'role-form-actions': moduleKey === 'roles', 'household-form-actions': moduleKey === 'households' }">
                <button
                    class="primary-button"
                    type="submit"
                    :disabled="busy || formOptionsBusy || !userFormReady || !roleFormReady || !villageFormReady"
                >{{ busy ? 'Đang lưu…' : moduleKey === 'households' && !editing ? (createHouseholdWizardStep === 1 ? 'Lưu và tiếp tục' : createHouseholdWizardStep === 3 ? 'Hoàn tất phiếu' : 'Tiếp tục') : moduleKey === 'roles' ? 'Lưu nhóm quyền' : 'Lưu' }}</button>
                <button class="secondary-button" type="button" @click="closeConfigurationForm">Hủy</button>
            </div>
            <p v-if="moduleKey === 'users' && !userFormReady" class="muted form-validation-hint" role="status">
                Còn {{ userFormValidationMessages.length }} mục cần kiểm tra: {{ userFormValidationMessages.join(' · ') }}
            </p>
        </form>
        </div>

        <section v-if="moduleKey === 'account'" class="account-profile-page">
            <div v-if="profile" class="account-profile-grid">
                <section class="account-profile-card account-information-card">
                    <div class="account-card-heading">
                        <span class="account-card-icon" aria-hidden="true">i</span>
                        <div>
                            <h3>Thông tin cá nhân</h3>
                            <p>Thông tin định danh tài khoản đang sử dụng</p>
                        </div>
                    </div>
                    <dl class="account-information-list">
                        <div><dt>Họ và tên</dt><dd>{{ pathValue(profile, 'user.name') || 'Chưa cập nhật' }}</dd></div>
                        <div><dt>Tên đăng nhập</dt><dd>{{ pathValue(profile, 'user.username') || '—' }}</dd></div>
                        <div><dt>Số điện thoại</dt><dd>{{ pathValue(profile, 'user.phone') || 'Chưa cập nhật' }}</dd></div>
                    </dl>
                </section>

                <section class="account-profile-card account-scope-card">
                    <div class="account-card-heading">
                        <span class="account-card-icon account-scope-icon" aria-hidden="true">⌖</span>
                        <div>
                            <h3>Phạm vi quản lý</h3>
                            <p>Địa bàn dữ liệu tài khoản được phép truy cập</p>
                        </div>
                    </div>
                    <div class="account-scope-summary">
                        <span>Cấp quản lý</span>
                        <strong>{{ pathValue(profile, 'scopeLevelLabel') || 'Chưa xác định' }}</strong>
                    </div>
                    <div class="account-scope-areas">
                        <span>Địa bàn được phân công</span>
                        <ul>
                            <li v-for="(area, index) in (Array.isArray(profile.scopeAreas) ? profile.scopeAreas : [])" :key="`${String(area)}-${index}`">{{ String(area) }}</li>
                        </ul>
                    </div>
                </section>

                <section class="account-profile-card account-permissions-card">
                    <div class="account-card-heading">
                        <span class="account-card-icon account-permission-icon" aria-hidden="true">✓</span>
                        <div>
                            <h3>Quyền truy cập</h3>
                            <p>Các nghiệp vụ được cấp cho tài khoản</p>
                        </div>
                        <span class="account-permission-count">{{ asRows(profile.permissions).length }}</span>
                    </div>
                    <div v-if="asRows(profile.permissions).length" class="account-permission-list">
                        <span v-for="(permission, index) in asRows(profile.permissions)" :key="String(permission.name ?? index)">
                            {{ String(permission.label ?? permission.name ?? '') }}
                        </span>
                    </div>
                    <p v-else class="account-no-permissions">Tài khoản chưa được cấp quyền nghiệp vụ.</p>
                </section>

                <form class="account-profile-card account-password-card" @submit.prevent="changePassword">
                    <div class="account-card-heading">
                        <span class="account-card-icon account-password-icon" aria-hidden="true">⌑</span>
                        <div>
                            <h3>Đổi mật khẩu</h3>
                            <p>Đặt mật khẩu mới để bảo vệ tài khoản của bạn</p>
                        </div>
                    </div>
                    <div class="account-password-fields">
                        <label class="workspace-field">
                            <span>Mật khẩu mới</span>
                            <input v-model="password" type="password" autocomplete="new-password" minlength="8" required placeholder="Nhập mật khẩu mới">
                        </label>
                        <label class="workspace-field">
                            <span>Xác nhận mật khẩu</span>
                            <input v-model="passwordConfirmation" type="password" autocomplete="new-password" minlength="8" required placeholder="Nhập lại mật khẩu mới">
                        </label>
                    </div>
                    <p class="account-password-hint">Sử dụng mật khẩu tối thiểu 8 ký tự và không chia sẻ với người khác.</p>
                    <div class="account-password-actions">
                        <button class="primary-button" type="submit" :disabled="busy || !password || !passwordConfirmation">
                            {{ busy ? 'Đang cập nhật…' : 'Cập nhật mật khẩu' }}
                        </button>
                    </div>
                </form>
            </div>
            <div v-else-if="busy" class="account-profile-loading" role="status">Đang tải thông tin tài khoản…</div>
        </section>

        <section v-if="moduleKey === 'imports'" class="excel-import-view">
            <div class="excel-import-grid">
                <form class="excel-import-panel excel-import-form" @submit.prevent="submitImport">
                    <div class="excel-import-heading">
                        <span class="excel-import-heading-icon" aria-hidden="true">X</span>
                        <div>
                            <p class="workspace-eyebrow">NGHIỆP VỤ · NHẬP DỮ LIỆU</p>
                            <h3>Nhập dữ liệu Excel</h3>
                            <p class="muted">Chọn địa bàn và tệp Excel chứa phiếu điều tra cần nhập.</p>
                        </div>
                    </div>

                    <div class="excel-location-grid">
                        <label class="workspace-field">
                            <span>Tỉnh / thành phố</span>
                            <select v-model="form.province_code" required @change="changeFormProvince">
                                <option value="">Chọn tỉnh / thành phố</option>
                                <option v-for="province in provinces" :key="String(province.code)" :value="String(province.code)">{{ province.name }}</option>
                            </select>
                        </label>
                        <label class="workspace-field">
                            <span>Xã / phường</span>
                            <select v-model="form.commune_code" required :disabled="!form.province_code" @change="changeFormCommune">
                                <option value="">Chọn xã / phường</option>
                                <option v-for="commune in formCommunes" :key="String(commune.code)" :value="String(commune.code)">{{ commune.name }}</option>
                            </select>
                        </label>
                        <label class="workspace-field">
                            <span>Thôn / xóm</span>
                            <select v-model="form.village_code" required :disabled="!form.commune_code">
                                <option value="">Chọn thôn / xóm</option>
                                <option v-for="village in formVillages" :key="String(village.code)" :value="String(village.code)">{{ village.name }}</option>
                            </select>
                        </label>
                    </div>

                    <label class="excel-file-drop">
                        <input type="file" accept=".xls,.xlsx,.csv" required @change="setImportFile">
                        <span class="excel-file-icon" aria-hidden="true">↑</span>
                        <strong>{{ importFile ? String(importFile.name) : 'Chọn tệp Excel hoặc kéo thả vào đây' }}</strong>
                        <small>Định dạng hỗ trợ: .xls, .xlsx hoặc .csv</small>
                    </label>

                    <div class="excel-import-actions">
                        <button class="primary-button" type="submit" :disabled="busy || !importFile">
                            {{ busy ? 'Đang tải lên…' : 'Tải dữ liệu lên' }}
                        </button>
                        <a class="secondary-button inline-button" href="/api/v1/household-imports/template">Tải tệp mẫu</a>
                        <a v-if="permissions.includes('export-households')" class="secondary-button inline-button" href="/api/v1/household-exports">Xuất Excel</a>
                    </div>
                </form>

                <aside class="excel-import-panel excel-import-guide">
                    <span class="excel-guide-label">HƯỚNG DẪN NHẬP</span>
                    <ol>
                        <li><strong>Tải tệp mẫu</strong><span>Giữ nguyên cấu trúc cột trong tệp mẫu.</span></li>
                        <li><strong>Điền dữ liệu địa bàn</strong><span>Kiểm tra mã địa bàn trước khi tải lên.</span></li>
                        <li><strong>Tải tệp lên</strong><span>Hệ thống xử lý và lưu lại kết quả nhập.</span></li>
                    </ol>
                </aside>
            </div>

            <section class="excel-import-panel excel-import-history">
                <div class="excel-history-heading">
                    <div>
                        <p class="workspace-eyebrow">LỊCH SỬ</p>
                        <h3>Các tệp đã nhập</h3>
                    </div>
                    <span class="excel-history-count">{{ importHistory.length }} tệp</span>
                </div>
                <p v-if="importHistory.length === 0" class="excel-history-empty">Chưa có tệp nào được nhập.</p>
                <div v-for="item in importHistory" :key="String(item.id)" class="excel-history-row">
                    <span class="excel-file-mini" aria-hidden="true">X</span>
                    <div class="excel-history-name">
                        <strong>{{ item.file_name }}</strong>
                        <span>{{ item.status }}</span>
                    </div>
                    <div class="pagination-actions">
                        <a class="secondary-button inline-button" :href="`/api/v1/household-imports/${item.id}/file`">Tải tệp</a>
                        <a v-if="item.status === 'FAILED'" class="secondary-button inline-button danger-button" :href="`/api/v1/household-imports/${item.id}/error-file`">Tải dòng lỗi</a>
                    </div>
                </div>
                <div class="pagination-actions excel-history-pagination">
                    <button class="secondary-button" type="button" :disabled="currentPage <= 1" @click="changePage(-1)">Trước</button>
                    <span class="muted">Trang {{ currentPage }} / {{ lastPage }}</span>
                    <button class="secondary-button" type="button" :disabled="currentPage >= lastPage" @click="changePage(1)">Sau</button>
                </div>
            </section>
        </section>

        <section v-if="moduleKey === 'trash'" class="workspace-table-wrap trash-management-panel">
            <table v-if="rows.length" class="workspace-table trash-management-table">
                <thead>
                    <tr>
                        <th class="trash-checkbox-heading">
                            <input type="checkbox" aria-label="Chọn tất cả mục trên trang" :checked="allTrashRowsSelected" @change="toggleAllTrashRows(($event.target as HTMLInputElement).checked)">
                        </th>
                        <th>Loại dữ liệu</th>
                        <th>Mã phiếu</th>
                        <th>Chủ hộ</th>
                        <th v-if="trashFilterType === 'person'">Nhân khẩu</th>
                        <th>Ngày xóa</th>
                        <th class="trash-actions-heading">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="`${row.item_type}-${row.id}`">
                        <td class="trash-checkbox-cell">
                            <input type="checkbox" :aria-label="`Chọn ${pathValue(row, 'record.person_name') || pathValue(row, 'record.household_code') || 'mục đã xóa'}`" :checked="selectedTrashKeys.includes(trashKey(row))" @change="toggleTrashSelection(row, ($event.target as HTMLInputElement).checked)">
                        </td>
                        <td>
                            <span class="trash-type-badge" :class="{ 'trash-type-person': row.item_type === 'person' }">
                                {{ row.item_type === 'person' ? 'Nhân khẩu' : 'Phiếu điều tra' }}
                            </span>
                        </td>
                        <td>{{ pathValue(row, 'record.household_code') || '—' }}</td>
                        <td>{{ pathValue(row, 'record.head_name') || '—' }}</td>
                        <td v-if="trashFilterType === 'person'">{{ pathValue(row, 'record.person_name') || '—' }}</td>
                        <td>{{ pathValue(row, 'deleted_at') || '—' }}</td>
                        <td>
                            <div class="trash-row-actions">
                                <button class="trash-icon-button trash-restore-button" type="button" title="Khôi phục" aria-label="Khôi phục" @click="requestTrashRestore(row)">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.6"/><path d="M4 4v4.6h4.6"/></svg>
                                </button>
                                <button class="trash-icon-button trash-delete-button" type="button" title="Xóa vĩnh viễn" aria-label="Xóa vĩnh viễn" @click="requestTrashDelete(row)">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-else class="empty-state trash-empty-state">
                <span aria-hidden="true">R</span>
                <strong>Thùng rác trống</strong>
                <p>Không có dữ liệu đã xóa để hiển thị.</p>
            </div>
        </section>

        <section v-if="moduleKey !== 'trash' && rows.length && ['households', 'schools', 'villages', 'roles', 'users'].includes(moduleKey)" class="workspace-table-wrap">
            <table class="workspace-table" :class="{
                'account-management-table': moduleKey === 'users',
                'role-management-table': moduleKey === 'roles',
                'village-management-table': moduleKey === 'villages',
                'school-management-table': moduleKey === 'schools',
                'household-management-table': moduleKey === 'households',
            }">
                <thead>
                    <tr>
                        <th v-if="moduleKey === 'households' && canDelete" class="household-selection-cell">
                            <input
                                type="checkbox"
                                aria-label="Chọn tất cả phiếu trên trang"
                                :checked="allHouseholdRowsSelected"
                                :indeterminate="someHouseholdRowsSelected"
                                @change="toggleAllHouseholdRows(($event.target as HTMLInputElement).checked)"
                            >
                        </th>
                        <th v-if="['villages', 'schools', 'households'].includes(moduleKey)" class="village-row-number">STT</th>
                        <th v-for="column in info.columns" :key="column">{{ fieldLabel(column) }}</th>
                        <th v-if="moduleKey === 'roles'">Quyền hạn</th>
                        <th v-if="moduleKey === 'users'">Trạng thái</th>
                        <th :class="{ 'account-actions-heading': moduleKey === 'users' }">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, rowIndex) in rows" :key="String(row.id ?? row.code)">
                        <td v-if="moduleKey === 'households' && canDelete" class="household-selection-cell">
                            <input
                                type="checkbox"
                                :aria-label="`Chọn phiếu ${row.household_code}`"
                                :checked="selectedHouseholdIds.includes(Number(row.id))"
                                @change="toggleHouseholdSelection(Number(row.id), ($event.target as HTMLInputElement).checked)"
                            >
                        </td>
                        <td v-if="moduleKey === 'households'" class="village-row-number">
                            {{ ((currentPage - 1) * householdPageSize) + rowIndex + 1 }}
                        </td>
                        <td v-if="moduleKey === 'villages'" class="village-row-number">
                            {{ ((currentPage - 1) * villagePageSize) + rowIndex + 1 }}
                        </td>
                        <td v-if="moduleKey === 'schools'" class="village-row-number">
                            {{ ((currentPage - 1) * schoolPageSize) + rowIndex + 1 }}
                        </td>
                        <td
                            v-for="column in info.columns"
                            :key="column"
                            :class="{
                                'account-numeric-cell': moduleKey === 'users' && column === 'phone',
                                'village-code-cell': ['villages', 'schools'].includes(moduleKey) && column === 'code',
                            }"
                        >{{ pathValue(row, column) }}</td>
                        <td v-if="moduleKey === 'roles'">
                            <span class="role-permission-count">
                                {{ Array.isArray(row.permissions) ? row.permissions.length : 0 }} quyền
                            </span>
                        </td>
                        <td v-if="moduleKey === 'users'" class="account-status-cell">
                            <span class="status-pill" :class="{ inactive: !row.is_active }">
                                {{ row.is_active ? 'Đang hoạt động' : 'Đã vô hiệu hóa' }}
                            </span>
                        </td>
                        <td class="table-actions">
                            <div v-if="moduleKey === 'users'" class="account-status-actions">
                                <div class="account-action-controls">
                                    <button
                                        v-if="row.can_change_account_status"
                                        class="secondary-button account-action-button account-icon-action"
                                        :class="row.is_active ? 'user-deactivate-button' : 'user-activate-button'"
                                        type="button"
                                        :aria-label="row.is_active ? `Vô hiệu hóa ${row.name}` : `Kích hoạt ${row.name}`"
                                        :title="row.is_active ? 'Vô hiệu hóa' : 'Kích hoạt'"
                                        @click="openAccountAction(row, 'status')"
                                    >
                                        <svg v-if="row.is_active" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/></svg>
                                        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2m-3.5 9v3"/></svg>
                                    </button>
                                    <button
                                        v-if="row.can_reset_account_password"
                                        class="secondary-button account-action-button account-icon-action user-password-reset-button"
                                        type="button"
                                        :aria-label="`Đặt lại mật khẩu ${row.name}`"
                                        title="Đặt lại mật khẩu"
                                        @click="openAccountAction(row, 'password')"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 3 3-3 3m-4 1 2 2"/></svg>
                                    </button>
                                </div>
                            </div>
                            <template v-else>
                                <div v-if="moduleKey === 'households'" class="role-action-buttons village-action-buttons household-row-actions">
                                    <button
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Xem chi tiết phiếu ${row.household_code}`"
                                        title="Xem chi tiết"
                                        @click="openHousehold(row)"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg>
                                    </button>
                                    <button
                                        v-if="canUpdate"
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa phiếu ${row.household_code}`"
                                        title="Sửa phiếu"
                                        @click="beginEdit(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"/><path d="m12.5 4.5 3 3"/></svg>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        class="role-icon-button role-delete-button"
                                        type="button"
                                        :aria-label="`Xóa phiếu ${row.household_code}`"
                                        title="Xóa phiếu"
                                        @click="remove(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"/></svg>
                                    </button>
                                </div>
                                <div v-if="moduleKey === 'villages'" class="role-action-buttons village-action-buttons">
                                    <button
                                        v-if="canUpdate"
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa thôn / xóm ${row.name}`"
                                        title="Sửa"
                                        @click="beginEdit(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"/><path d="m12.5 4.5 3 3"/></svg>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        class="role-icon-button role-delete-button"
                                        type="button"
                                        :aria-label="`Xóa thôn / xóm ${row.name}`"
                                        title="Xóa"
                                        @click="remove(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"/></svg>
                                    </button>
                                </div>
                                <div v-if="moduleKey === 'schools'" class="role-action-buttons village-action-buttons">
                                    <button
                                        v-if="canUpdate"
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa trường ${row.name}`"
                                        title="Sửa"
                                        @click="beginEdit(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"/><path d="m12.5 4.5 3 3"/></svg>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        class="role-icon-button role-delete-button"
                                        type="button"
                                        :aria-label="`Xóa trường ${row.name}`"
                                        title="Xóa"
                                        @click="remove(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"/></svg>
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
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg>
                                </button>
                                <div v-if="moduleKey === 'roles' && row.can_manage" class="role-action-buttons">
                                    <button
                                        v-if="canUpdate"
                                        class="role-icon-button"
                                        type="button"
                                        :aria-label="`Sửa nhóm ${row.name}`"
                                        title="Sửa nhóm"
                                        @click="beginEdit(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"/><path d="m12.5 4.5 3 3"/></svg>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        class="role-icon-button role-delete-button"
                                        type="button"
                                        :aria-label="`Xóa nhóm ${row.name}`"
                                        title="Xóa nhóm"
                                        @click="remove(row)"
                                    >
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2m3 0-.7 10H5.7L5 6m3 3v4m4-4v4"/></svg>
                                    </button>
                                </div>
                                <button v-if="!['roles', 'villages', 'schools', 'households'].includes(moduleKey) && canUpdate" class="secondary-button" type="button" @click="beginEdit(row)">Sửa cấu hình</button>
                                <button v-if="!['roles', 'villages', 'schools', 'households'].includes(moduleKey) && canDelete" class="secondary-button danger-button" type="button" @click="remove(row)">Xóa</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p v-if="['villages', 'schools'].includes(moduleKey) && !busy && rows.length === 0" class="village-empty-state">
            {{ moduleKey === 'villages'
                ? totalRecords ? 'Không tìm thấy thôn / xóm phù hợp với bộ lọc.' : 'Chưa có dữ liệu thôn / xóm tại địa bàn này.'
                : totalRecords ? 'Không tìm thấy trường học phù hợp với bộ lọc.' : 'Chưa có dữ liệu trường học tại địa bàn này.' }}
        </p>
        <p v-if="moduleKey === 'households' && !householdDetail && !busy && rows.length === 0" class="village-empty-state">
            {{ totalRecords ? 'Không tìm thấy phiếu điều tra phù hợp với bộ lọc.' : 'Chưa có phiếu điều tra tại địa bàn này.' }}
        </p>
        <div v-if="moduleKey === 'users'" class="user-pagination-footer">
            <label class="page-size-control">
                <span>Mỗi trang</span>
                <select v-model.number="userPageSize" :disabled="busy" @change="changeUserPageSize">
                    <option :value="10">10</option>
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </label>
            <div class="pagination-actions compact-pagination" aria-label="Phân trang danh sách">
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage <= 1 || busy" @click="changePage(-1)">Trước</button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select
                        v-model.number="currentPage"
                        aria-label="Chọn trang tài khoản"
                        :disabled="busy || lastPage <= 1"
                        @change="load"
                    >
                        <option v-for="pageNumber in lastPage" :key="pageNumber" :value="pageNumber">{{ pageNumber }}</option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage >= lastPage || busy" @click="changePage(1)">Sau</button>
            </div>
        </div>
        <div v-if="moduleKey === 'trash'" class="user-pagination-footer trash-pagination-footer">
            <label class="page-size-control">
                <span>Mỗi trang</span>
                <select v-model.number="trashPageSize" :disabled="busy" @change="currentPage = 1; load()">
                    <option :value="10">10</option>
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </label>
            <div class="pagination-actions compact-pagination" aria-label="Phân trang thùng rác">
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage <= 1 || busy" @click="changePage(-1)">Trước</button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select v-model.number="currentPage" aria-label="Chọn trang thùng rác" :disabled="busy || lastPage <= 1" @change="load">
                        <option v-for="pageNumber in lastPage" :key="pageNumber" :value="pageNumber">{{ pageNumber }}</option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage >= lastPage || busy" @click="changePage(1)">Sau</button>
            </div>
        </div>
        <div v-if="['villages', 'schools'].includes(moduleKey)" class="user-pagination-footer village-pagination-footer">
            <label class="page-size-control">
                <span>Mỗi trang</span>
                <select v-if="moduleKey === 'villages'" v-model.number="villagePageSize" :disabled="busy" @change="changeVillagePageSize">
                    <option :value="10">10</option>
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                </select>
                <select v-else v-model.number="schoolPageSize" :disabled="busy" @change="changeSchoolPageSize">
                    <option :value="10">10</option>
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                </select>
            </label>
            <div class="pagination-actions compact-pagination" :aria-label="moduleKey === 'villages' ? 'Phân trang danh sách thôn / xóm' : 'Phân trang danh sách trường học'">
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage <= 1 || busy" @click="changePage(-1)">Trước</button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select v-model.number="currentPage" :aria-label="moduleKey === 'villages' ? 'Chọn trang thôn / xóm' : 'Chọn trang trường học'" :disabled="busy || lastPage <= 1" @change="load">
                        <option v-for="pageNumber in lastPage" :key="pageNumber" :value="pageNumber">{{ pageNumber }}</option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage >= lastPage || busy" @click="changePage(1)">Sau</button>
            </div>
        </div>
        <div v-if="moduleKey === 'households'" class="user-pagination-footer village-pagination-footer household-pagination-footer">
            <label class="page-size-control">
                <span>Mỗi trang</span>
                <select v-model.number="householdPageSize" :disabled="busy" @change="changeHouseholdPageSize">
                    <option :value="10">10</option>
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </label>
            <div class="pagination-actions compact-pagination" aria-label="Phân trang danh sách phiếu điều tra">
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage <= 1 || busy" @click="changePage(-1)">Trước</button>
                <label class="page-number-control">
                    <span>Trang</span>
                    <select v-model.number="currentPage" aria-label="Chọn trang phiếu điều tra" :disabled="busy || lastPage <= 1" @change="load">
                        <option v-for="pageNumber in lastPage" :key="pageNumber" :value="pageNumber">{{ pageNumber }}</option>
                    </select>
                    <span>/ {{ lastPage }}</span>
                </label>
                <button class="secondary-button management-tool-button" type="button" :disabled="currentPage >= lastPage || busy" @click="changePage(1)">Sau</button>
            </div>
        </div>
        <div v-if="lastPage > 1 && !isCompactManagement && !['households', 'villages', 'schools'].includes(moduleKey) && ['roles', 'users', 'trash'].includes(moduleKey)" class="pagination-actions workspace-pagination">
            <button class="secondary-button" type="button" :disabled="currentPage <= 1" @click="changePage(-1)">Trước</button>
            <span class="muted">Trang {{ currentPage }} / {{ lastPage }}</span>
            <button class="secondary-button" type="button" :disabled="currentPage >= lastPage" @click="changePage(1)">Sau</button>
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
                        <span class="role-permissions-dialog-eyebrow">CHI TIẾT PHÂN QUYỀN</span>
                        <h3 id="role-permissions-title">{{ selectedRolePermissions.name }}</h3>
                        <p>{{ scopeLabels[String(selectedRolePermissions.scope_level)] ?? 'Chưa xác định cấp quản lý' }}</p>
                    </div>
                    <button
                        class="modal-icon-button"
                        type="button"
                        aria-label="Đóng chi tiết quyền hạn"
                        autofocus
                        @click="selectedRolePermissions = null"
                    >×</button>
                </header>
                <div class="role-permissions-dialog-content">
                    <section
                        v-for="group in permissionGroupsForDisplay(selectedRolePermissions.permissions)"
                        :key="group.key"
                        class="role-permissions-category"
                    >
                        <div class="role-permissions-category-heading">
                            <h4>{{ group.label }}</h4>
                            <span>{{ group.options.length }}</span>
                        </div>
                        <ul>
                            <li v-for="permission in group.options" :key="permissionName(permission)">
                                <span class="role-permission-check" aria-hidden="true">✓</span>
                                {{ typeof permission === 'object' && permission !== null && 'label' in permission ? String(permission.label) : permissionLabel(permissionName(permission)) }}
                            </li>
                        </ul>
                    </section>
                    <p
                        v-if="permissionGroupsForDisplay(selectedRolePermissions.permissions).length === 0"
                        class="role-permissions-empty"
                    >Nhóm này chưa được cấp quyền.</p>
                </div>
            </section>
        </div>
        <div
            v-if="moduleKey === 'households' && householdDetail"
            class="workspace-modal-backdrop household-detail-backdrop"
            role="presentation"
            @click.self="closeHouseholdDetail"
            @keydown.esc="closeHouseholdDetail"
        >
        <section class="household-detail" role="dialog" aria-modal="true" aria-labelledby="household-detail-title">
            <header class="household-detail-header">
                <div>
                    <h2 id="household-detail-title">Chi tiết Phiếu điều tra - {{ householdDetail.household_code }}</h2>
                </div>
                <div class="household-detail-actions">
                    <button class="household-detail-icon-button" type="button" aria-label="Đóng chi tiết phiếu" title="Đóng" @click="closeHouseholdDetail">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                    </button>
                    <button class="household-detail-icon-button" type="button" aria-label="In phiếu điều tra" title="In phiếu điều tra (PDF)" @click="printHousehold">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5h-2"/><path d="M7 14h10v7H7zM17 11h.01"/></svg>
                    </button>
                    <button
                        v-if="permissions.includes('update-households')"
                        class="household-detail-icon-button"
                        type="button"
                        aria-label="Chỉnh sửa phiếu"
                        title="Chỉnh sửa phiếu"
                        @click="editHousehold"
                    >
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13.8 3.2a2 2 0 0 1 2.8 2.8L8 14.6l-3.5.9.9-3.5 8.4-8.8Z"/><path d="m12.5 4.5 3 3"/></svg>
                    </button>
                    <button
                        v-if="!householdDetailReadOnly && permissions.includes('create-households')"
                        class="household-detail-icon-button"
                        type="button"
                        aria-label="Thêm nhân khẩu"
                        title="Thêm nhân khẩu"
                        @click="beginMember(householdDetail.has_household_head !== true)"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
            </header>

            <p v-if="householdLoading" class="household-detail-loading" role="status">Đang tải thông tin nhân khẩu…</p>
            <p v-if="notice" class="success-notice household-detail-notice" role="status">{{ notice }}</p>
            <p v-if="error && !memberEditorOpen" class="notice household-detail-notice" role="alert">{{ error }}</p>

            <section class="household-members-section" aria-labelledby="household-members-title">
                <div class="household-section-title">
                    <span class="household-section-icon household-education-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m2 9 10-5 10 5-10 5L2 9Z"/><path d="M6 11v5c3.5 3 8.5 3 12 0v-5M22 9v6"/></svg></span>
                    <div><h3 id="household-members-title">Danh sách Nhân khẩu</h3><p>{{ householdPersons.length }} thành viên trong hộ gia đình</p></div>
                </div>
                <div class="household-grid-wrap">
                    <table class="household-grid household-detail-table">
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
                                v-for="(person, index) in householdPersons"
                                :key="String(person.id)"
                                :class="{ 'household-head-row': person.relationship_with_head === 'Chủ hộ' }"
                            >
                                <td class="household-grid-index">{{ index + 1 }}</td>
                                <td>
                                    <span v-if="person.relationship_with_head === 'Chủ hộ'" class="household-head-badge">Chủ hộ</span>
                                    <span v-else>{{ person.relationship_with_head || '—' }}</span>
                                </td>
                                <td class="household-grid-name">{{ [person.last_name, person.first_name].filter(Boolean).join(' ') || 'Chưa có họ tên' }}</td>
                                <td><span>{{ displayDate(person.dob) }}</span><small>{{ personGender(person) }}</small></td>
                                <td>
                                    <div v-if="personEducations(person).length" class="household-detail-record-list">
                                        <article v-for="education in personEducations(person)" :key="String(education.id ?? education.school_year)" class="household-detail-record">
                                            <strong>{{ education.school_year || 'Chưa rõ năm học' }}</strong>
                                            <span v-if="educationGraduationLabel(education)">{{ educationGraduationLabel(education) }}</span>
                                            <span v-for="line in educationLiteracyLabels(education)" :key="line">{{ line }}</span>
                                            <span v-if="!educationGraduationLabel(education) && !educationLiteracyLabels(education).length">Chưa cập nhật kết quả học tập</span>
                                        </article>
                                    </div>
                                    <span v-else>Chưa cập nhật</span>
                                </td>
                                <td>
                                    <div v-if="personEducations(person).length" class="household-detail-record-list">
                                        <article v-for="education in personEducations(person)" :key="`school-${String(education.id ?? education.school_year)}`" class="household-detail-record">
                                            <strong>{{ education.school_year || 'Chưa rõ năm học' }}</strong>
                                            <span>{{ pathValue(education, 'school.name') }}</span>
                                            <span>Lớp: {{ education.current_class || '—' }}<template v-if="education.academic_block"> · Khối: {{ education.academic_block }}</template></span>
                                        </article>
                                    </div>
                                    <span v-else>Chưa cập nhật</span>
                                </td>
                                <td>
                                    <div class="household-detail-record-list">
                                        <article class="household-detail-record">
                                            <strong>Ưu tiên</strong>
                                            <span>{{ person.priority_type || 'Không ghi nhận' }}</span>
                                        </article>
                                        <article class="household-detail-record">
                                            <strong>Khuyết tật / hoàn cảnh</strong>
                                            <span v-for="line in personDisabilityDetails(person)" :key="line">{{ line }}</span>
                                        </article>
                                    </div>
                                </td>
                                <td>
                                    <div class="household-member-actions">
                                        <button class="household-icon-action" type="button" :aria-label="`Xem thông tin ${person.first_name ?? 'nhân khẩu'}`" title="Xem chi tiết" @click="selectedHouseholdPerson = person">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg>
                                        </button>
                                        <button v-if="!householdDetailReadOnly && permissions.includes('update-households')" class="household-icon-action" type="button" :aria-label="`Sửa thông tin ${person.first_name ?? 'nhân khẩu'}`" title="Sửa nhân khẩu" @click="beginMember(false, person)">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.8 4.2a2 2 0 0 1 2.8 2.8L8 15.6l-4 1 1-4 8.8-8.4Z"/><path d="m12.5 5.5 3 3M13 20h8"/></svg>
                                        </button>
                                        <button v-if="!householdDetailReadOnly && permissions.includes('delete-households')" class="household-icon-action household-icon-danger" type="button" :aria-label="`Xóa ${person.first_name ?? 'nhân khẩu'}`" title="Xóa nhân khẩu" @click="deleteMember(person)">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!householdPersons.length">
                                <td colspan="8" class="household-grid-empty">Chưa có nhân khẩu trong phiếu này.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </section>
        </div>
            <div
                v-if="memberEditorOpen && (householdDetail || createdHouseholdId !== null || !editing)"
                class="workspace-modal-backdrop household-member-editor-backdrop"
                role="presentation"
                @click.self="memberEditorOpen = false"
                @keydown.esc="memberEditorOpen = false"
            >
                <form
                    class="workspace-form household-member-editor household-member-dialog"
                    :class="{ 'household-head-education-step': !editing && memberId === null }"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="household-member-form-title"
                    @submit.prevent="saveMember"
                >
                    <header class="household-member-dialog-header">
                        <div>
                            <span>THEO MẪU PHIẾU EXCEL</span>
                            <h3 id="household-member-form-title">{{ memberId === null ? 'Thêm' : 'Cập nhật' }} {{ memberIsHead ? 'chủ hộ' : 'thành viên' }}</h3>
                        </div>
                        <button class="modal-icon-button" type="button" aria-label="Đóng biểu mẫu" :disabled="memberBusy" @click="memberEditorOpen = false">×</button>
                    </header>
                    <p v-if="error && false" class="notice household-member-form-error" role="alert">{{ error }}</p>
                    <section v-if="memberIsHead && !editing && memberId === null || !memberIsHead || headMemberEducationStep" class="household-member-form-section household-member-person-section">
                        <h4>Thông tin đối tượng</h4>
                        <label class="workspace-field">
                            <span>Họ và tên đệm <b>*</b></span>
                            <input v-model.trim="memberForm.last_name" type="text" required maxlength="50">
                            <small v-if="memberFormErrors.last_name" class="field-error" role="alert">{{ memberFormErrors.last_name }}</small>
                        </label>
                        <label class="workspace-field">
                            <span>Tên <b>*</b></span>
                            <input v-model.trim="memberForm.first_name" type="text" required maxlength="100">
                            <small v-if="memberFormErrors.first_name" class="field-error" role="alert">{{ memberFormErrors.first_name }}</small>
                        </label>
                        <label class="workspace-field">
                            <span>Ngày sinh <b>*</b></span>
                            <input v-model="memberForm.dob" type="date" required>
                            <small v-if="memberFormErrors.dob" class="field-error" role="alert">{{ memberFormErrors.dob }}</small>
                        </label>
                        <label class="workspace-field">
                            <span>Giới tính <b>*</b></span>
                            <select v-model="memberForm.gender" required>
                                <option value="NAM">Nam</option>
                                <option value="NU">Nữ</option>
                            </select>
                            <small v-if="memberFormErrors.gender" class="field-error" role="alert">{{ memberFormErrors.gender }}</small>
                        </label>
                        <label class="workspace-field">
                            <span>Dân tộc <b>*</b></span>
                            <select v-model="memberForm.ethnicity_code" required>
                                <option value="">Chọn dân tộc</option>
                                <option v-for="ethnicity in ethnicities" :key="String(ethnicity.code)" :value="String(ethnicity.code)">{{ ethnicity.name }}</option>
                            </select>
                            <small v-if="memberFormErrors.ethnicity_code" class="field-error" role="alert">{{ memberFormErrors.ethnicity_code }}</small>
                        </label>
                        <label class="workspace-field"><span>Tôn giáo</span><input v-model.trim="memberForm.religion" type="text" maxlength="50"></label>
                        <label class="workspace-field"><span>Diện ưu tiên</span><input v-model.trim="memberForm.priority_type" type="text" maxlength="100"></label>
                        <label class="workspace-field"><span>Họ tên cha / mẹ</span><input v-model.trim="memberForm.parent_name" type="text" maxlength="150"></label>
                    </section>
                    <section v-if="memberIsHead && !editing && memberId === null || !memberIsHead || headMemberEducationStep" class="household-member-form-section household-member-contact-section">
                        <h4>Thông tin liên lạc</h4>
                        <label v-if="!memberIsHead" class="workspace-field">
                            <span>Quan hệ với chủ hộ <b>*</b></span>
                            <select v-model="memberForm.relationship_with_head" required>
                                <option value="">Chọn quan hệ</option>
                                <option v-for="relationship in [...new Set([...memberRelationships, memberForm.relationship_with_head].filter(Boolean))]" :key="relationship" :value="relationship">{{ relationship }}</option>
                            </select>
                            <small v-if="memberFormErrors.relationship_with_head" class="field-error" role="alert">{{ memberFormErrors.relationship_with_head }}</small>
                        </label>
                        <label class="workspace-field"><span>Số điện thoại</span><input v-model.trim="memberForm.phone" type="tel" maxlength="20"></label>
                        <label class="workspace-field household-member-wide-field"><span>Ghi chú</span><textarea v-model.trim="memberForm.note" rows="2" maxlength="2000"></textarea></label>
                    </section>
                    <section v-if="memberIsHead && !editing && memberId === null || !memberIsHead || headMemberEducationStep" class="household-member-form-section household-member-education-section">
                        <h4>Thông tin học tập</h4>
                        <label class="workspace-field">
                            <span>Năm học <b>*</b></span>
                            <select v-model="educationForm.school_year" required>
                                <option value="">Chọn năm học</option>
                                <option v-for="year in [...new Set([...memberSchoolYearOptions, educationForm.school_year].filter(Boolean))]" :key="year" :value="year">{{ year }}</option>
                            </select>
                            <small v-if="memberFormErrors['education.school_year']" class="field-error" role="alert">{{ memberFormErrors['education.school_year'] }}</small>
                        </label>
                        <label class="workspace-field">
                            <span>Trường học</span>
                            <select v-model="educationForm.school_code">
                                <option value="">Chưa chọn trường</option>
                                <option v-for="school in personSchools" :key="String(school.code)" :value="String(school.code)">{{ school.name }} · {{ school.code }}</option>
                            </select>
                            <small v-if="memberFormErrors['education.school_code']" class="field-error" role="alert">{{ memberFormErrors['education.school_code'] }}</small>
                        </label>
                        <label class="workspace-field"><span>Lớp hiện tại</span><input v-model.trim="educationForm.current_class" type="text" maxlength="50"></label>
                        <label class="workspace-field">
                            <span>Trình độ phổ cập</span>
                            <select v-model="educationForm.graduation_level">
                                <option value="">Chưa xác định</option>
                                <option value="MN">Mầm non</option>
                                <option value="TH">Tiểu học</option>
                                <option value="THCS">Trung học cơ sở</option>
                                <option value="THPT">Trung học phổ thông</option>
                            </select>
                        </label>
                        <label class="workspace-field"><span>Năm tốt nghiệp</span><input v-model.trim="educationForm.graduation_year" type="text" maxlength="20"></label>
                        <label class="workspace-field">
                            <span>Học bổ túc</span>
                            <select v-model="educationForm.is_complementary">
                                <option value="0">Không</option>
                                <option value="1">Có</option>
                            </select>
                        </label>
                        <label class="workspace-field"><span>Trình độ tốt nghiệp nghề</span><input v-model.trim="educationForm.vocational_grad_level" type="text" maxlength="50"></label>
                        <label class="workspace-field"><span>Năm tốt nghiệp nghề</span><input v-model.trim="educationForm.vocational_grad_year" type="text" maxlength="20"></label>
                        <label class="workspace-field"><span>Lớp đã hoàn thành</span><input v-model.trim="educationForm.finished_class" type="text" maxlength="20"></label>
                        <label class="workspace-field"><span>Năm hoàn thành lớp</span><input v-model.trim="educationForm.finished_year" type="text" maxlength="20"></label>
                        <label class="workspace-field"><span>Lớp đã bỏ học</span><input v-model.trim="educationForm.dropped_class" type="text" maxlength="20"></label>
                        <label class="workspace-field"><span>Năm bỏ học</span><input v-model.trim="educationForm.dropped_year" type="text" maxlength="20"></label>
                    </section>
                    <section v-if="memberIsHead && !editing && memberId === null || !memberIsHead || headMemberEducationStep" class="household-member-form-section household-member-literacy-section">
                        <h4>Xóa mù chữ</h4>
                        <label class="workspace-field"><span>Đang học lớp</span><input v-model.trim="educationForm.literacy_current_class" type="text" maxlength="20"></label>
                        <label class="workspace-field"><span>Lớp XMC đã hoàn thành</span><input v-model.trim="educationForm.literacy_completed_class" type="text" maxlength="20"></label>
                        <label class="workspace-field">
                            <span>Mức độ tái mù chữ</span>
                            <select v-model="educationForm.literacy_relapse_level">
                                <option value="">Không ghi nhận</option>
                                <option value="1">Mức độ 1</option>
                                <option value="2">Mức độ 2</option>
                            </select>
                        </label>
                        <label class="workspace-field"><span>Khả năng học tập</span><input v-model.trim="educationForm.learning_capacity" type="text" maxlength="50"></label>
                    </section>
                    <section v-if="memberIsHead && !editing && memberId === null || !memberIsHead || headMemberEducationStep" class="household-member-form-section household-member-disability-section">
                        <h4>Khuyết tật</h4>
                        <div class="household-member-checkbox-grid">
                            <label v-for="option in memberDisabilityOptions" :key="option.key" class="household-member-checkbox">
                                <input v-model="disabilityForm[option.key]" type="checkbox">
                                <span>{{ option.label }}</span>
                            </label>
                        </div>
                        <label class="workspace-field">
                            <span>Khả năng học tập</span>
                            <select v-model="disabilityForm.can_study">
                                <option value="">Chưa xác định</option>
                                <option value="true">Có khả năng học tập</option>
                                <option value="false">Không có khả năng học tập</option>
                            </select>
                        </label>
                    </section>
                    <section v-if="memberIsHead && !editing && memberId === null || !memberIsHead || headMemberEducationStep" class="household-member-form-section household-member-circumstance-section">
                        <h4>Hoàn cảnh</h4>
                        <label class="workspace-field">
                            <span>Hoàn cảnh đặc biệt</span>
                            <select v-model="disabilityForm.special_circumstance">
                                <option value="">Không ghi nhận</option>
                                <option value="Chuyển đến">Chuyển đến</option>
                                <option value="Chuyển đi">Chuyển đi</option>
                            </select>
                        </label>
                        <label class="workspace-field household-member-wide-field">
                            <span>Chi tiết hoàn cảnh</span>
                            <textarea
                                :value="String(disabilityForm.special_circumstance_detail ?? '')"
                                rows="2"
                                maxlength="2000"
                                @input="disabilityForm.special_circumstance_detail = ($event.target as HTMLTextAreaElement).value"
                            ></textarea>
                        </label>
                    </section>
                    <div class="pagination-actions household-member-form-actions">
                        <button class="primary-button" type="submit" :disabled="memberBusy">{{ memberBusy ? 'Đang lưu…' : 'Lưu thông tin chủ hộ' }}</button>
                        <button class="secondary-button" type="button" :disabled="memberBusy" @click="memberEditorOpen = false">Hủy</button>
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
            <section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="delete-person-title" aria-describedby="delete-person-message">
                <span class="confirm-dialog-icon" aria-hidden="true">!</span>
                <h3 id="delete-person-title">Xóa nhân khẩu</h3>
                <p id="delete-person-message">
                    Bạn có chắc muốn xóa “{{ [pendingHouseholdPersonDelete.last_name, pendingHouseholdPersonDelete.first_name].filter(Boolean).join(' ') }}” khỏi phiếu điều tra?
                </p>
                <p v-if="error" class="notice" role="alert">{{ error }}</p>
                <div class="confirm-dialog-actions">
                    <button class="secondary-button management-tool-button" type="button" :disabled="memberBusy" @click="pendingHouseholdPersonDelete = null">Hủy</button>
                    <button
                        v-if="pendingHouseholdPersonDelete.relationship_with_head !== 'Chủ hộ'"
                        class="confirm-delete-button"
                        type="button"
                        :disabled="memberBusy"
                        @click="confirmDeleteMember">
                        {{ memberBusy ? 'Đang xóa…' : 'Xóa nhân khẩu' }}
                    </button>
                </div>
            </section>
        </div>
        <div
            v-if="selectedHouseholdPerson"
            class="workspace-modal-backdrop household-person-backdrop"
            role="presentation"
            @click.self="selectedHouseholdPerson = null"
            @keydown.esc="selectedHouseholdPerson = null"
        >
            <section class="household-person-dialog" role="dialog" aria-modal="true" aria-labelledby="household-person-title">
                <header>
                    <div><span>CHI TIẾT NHÂN KHẨU</span><h3 id="household-person-title">{{ [selectedHouseholdPerson.last_name, selectedHouseholdPerson.first_name].filter(Boolean).join(' ') }}</h3></div>
                    <button class="modal-icon-button" type="button" aria-label="Đóng" @click="selectedHouseholdPerson = null">×</button>
                </header>
                <dl>
                    <div><dt>Quan hệ với chủ hộ</dt><dd>{{ selectedHouseholdPerson.relationship_with_head || '—' }}</dd></div>
                    <div><dt>Ngày sinh / Giới tính</dt><dd>{{ displayDate(selectedHouseholdPerson.dob) }} · {{ personGender(selectedHouseholdPerson) }}</dd></div>
                    <div><dt>Dân tộc / Tôn giáo</dt><dd>{{ personEthnicity(selectedHouseholdPerson) }} · {{ selectedHouseholdPerson.religion || 'Chưa cập nhật' }}</dd></div>
                    <div><dt>Điện thoại</dt><dd>{{ selectedHouseholdPerson.phone || 'Chưa cập nhật' }}</dd></div>
                    <div class="household-person-education-detail">
                        <dt>Học tập / Phổ cập theo năm học</dt>
                        <dd>
                            <span v-for="education in personEducations(selectedHouseholdPerson)" :key="String(education.id ?? education.school_year)">
                                <strong>{{ education.school_year || 'Chưa rõ năm học' }}</strong> · {{ educationGraduationLabel(education) || 'Chưa rõ trình độ' }} ·
                                {{ [pathValue(education, 'school.name'), education.current_class ? `Lớp ${String(education.current_class)}` : '', education.academic_block ? `Khối ${String(education.academic_block)}` : '', ...educationLiteracyLabels(education)].filter(Boolean).join(' · ') || 'Chưa cập nhật thêm' }}
                            </span>
                            <span v-if="!personEducations(selectedHouseholdPerson).length">Chưa cập nhật</span>
                        </dd>
                    </div>
                    <div><dt>Diện ưu tiên</dt><dd>{{ selectedHouseholdPerson.priority_type || 'Không ghi nhận' }}</dd></div>
                    <div><dt>Khuyết tật / hoàn cảnh</dt><dd>{{ personDisabilityDetails(selectedHouseholdPerson).join(' · ') }}</dd></div>
                    <div><dt>Ghi chú</dt><dd>{{ selectedHouseholdPerson.note || '—' }}</dd></div>
                </dl>
                <footer><button class="secondary-button" type="button" @click="selectedHouseholdPerson = null">Đóng</button></footer>
            </section>
        </div>
        <p v-if="moduleKey !== 'households' && !['schools', 'villages'].includes(moduleKey) && ['roles', 'users', 'trash'].includes(moduleKey) && !rows.length && !busy" class="empty-state">Không có dữ liệu.</p>
        <div v-if="busy" class="workspace-loading-overlay" role="status" aria-live="polite">
            <span class="workspace-loading-indicator" aria-hidden="true"></span>
            <span>Đang xử lý, vui lòng chờ…</span>
        </div>
    </section>

    <div v-if="pendingDelete" class="workspace-modal-backdrop" role="presentation" @click.self="pendingDelete = null" @keydown.esc="pendingDelete = null">
        <section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="delete-confirm-title" aria-describedby="delete-confirm-message" tabindex="-1">
            <span class="confirm-dialog-icon" aria-hidden="true">!</span>
            <h3 id="delete-confirm-title">Xác nhận xóa</h3>
            <p id="delete-confirm-message">Bạn có chắc chắn muốn xóa “{{ pendingDelete.name ?? pendingDelete.code ?? 'mục đã chọn' }}”? Thao tác này không thể hoàn tác.</p>
            <div class="confirm-dialog-actions">
                <button class="secondary-button management-tool-button" type="button" @click="pendingDelete = null">Hủy</button>
                <button class="confirm-delete-button" type="button" :disabled="busy" @click="confirmRemove">{{ busy ? 'Đang xóa…' : 'Xóa dữ liệu' }}</button>
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
        <section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="bulk-household-delete-title" aria-describedby="bulk-household-delete-message" tabindex="-1">
            <span class="confirm-dialog-icon" aria-hidden="true">!</span>
            <h3 id="bulk-household-delete-title">Xóa nhiều phiếu điều tra</h3>
            <p id="bulk-household-delete-message">Bạn đã chọn {{ selectedHouseholdIds.length }} phiếu. Bạn có chắc chắn muốn xóa các phiếu này? Thao tác có thể khôi phục trong thùng rác.</p>
            <div class="confirm-dialog-actions">
                <button class="secondary-button management-tool-button" type="button" :disabled="busy" @click="pendingHouseholdBulkDelete = false">Hủy</button>
                <button class="confirm-delete-button" type="button" :disabled="busy" @click="confirmBulkHouseholdDelete">{{ busy ? 'Đang xóa…' : `Xóa ${selectedHouseholdIds.length} phiếu` }}</button>
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
            <span class="confirm-dialog-icon" :class="{ 'account-action-success-icon': deleteResult.success }" aria-hidden="true">
                {{ deleteResult.success ? '✓' : '!' }}
            </span>
            <h3 id="delete-result-title">{{ deleteResult.title ?? (deleteResult.success ? 'Xóa thành công' : 'Xóa thất bại') }}</h3>
            <p id="delete-result-message">{{ deleteResult.message }}</p>
            <div class="confirm-dialog-actions">
                <button class="primary-button management-tool-button" type="button" autofocus @click="deleteResult = null">Đóng</button>
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
        <section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="trash-action-title" aria-describedby="trash-action-message" tabindex="-1">
            <span class="confirm-dialog-icon" :class="{ 'account-action-failure-icon': trashActionDialog.operation === 'force_delete' }" aria-hidden="true">
                {{ trashActionDialog.operation === 'restore' ? '↻' : '!' }}
            </span>
            <h3 id="trash-action-title">{{ trashActionDialog.operation === 'restore' ? 'Khôi phục dữ liệu' : 'Xóa dữ liệu vĩnh viễn' }}</h3>
            <p id="trash-action-message">
                {{
                    trashActionDialog.row
                        ? trashActionDialog.operation === 'restore'
                            ? 'Bạn có chắc chắn muốn khôi phục mục đã chọn? Dữ liệu sẽ trở lại danh sách quản lý.'
                            : 'Bạn có chắc chắn muốn xóa vĩnh viễn mục đã chọn? Dữ liệu sẽ không thể khôi phục lại.'
                        : trashActionDialog.operation === 'restore'
                            ? `Bạn có chắc chắn muốn khôi phục ${selectedTrashKeys.length} mục đã chọn?`
                            : `Bạn có chắc chắn muốn xóa vĩnh viễn ${selectedTrashKeys.length} mục đã chọn? Dữ liệu sẽ không thể khôi phục lại.`
                }}
            </p>
            <div class="confirm-dialog-actions">
                <button class="secondary-button management-tool-button" type="button" :disabled="busy" @click="trashActionDialog = null">Hủy</button>
                <button
                    :class="trashActionDialog.operation === 'force_delete' ? 'confirm-delete-button' : 'primary-button management-tool-button'"
                    type="button"
                    :disabled="busy"
                    @click="confirmTrashAction"
                >{{ busy ? 'Đang xử lý…' : trashActionDialog.operation === 'restore' ? 'Khôi phục' : 'Xóa vĩnh viễn' }}</button>
            </div>
        </section>
    </div>
    <div
        v-if="accountActionDialog"
        class="workspace-modal-backdrop"
        role="presentation"
        @click.self="!busy && (accountActionDialog = null)"
        @keydown.esc="!busy && (accountActionDialog = null)"
    >
        <section
            class="confirm-dialog account-action-dialog"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="account-action-title"
            aria-describedby="account-action-message"
            tabindex="-1"
        >
            <span class="confirm-dialog-icon" :class="{ 'account-action-success-icon': accountActionDialog.mode === 'result' && accountActionDialog.success }" aria-hidden="true">
                {{ accountActionDialog.mode === 'result' && accountActionDialog.success ? '✓' : '!' }}
            </span>
            <h3 id="account-action-title">{{ accountActionTitle(accountActionDialog) }}</h3>
            <p id="account-action-message">{{ accountActionMessage(accountActionDialog) }}</p>
            <div class="confirm-dialog-actions">
                <template v-if="accountActionDialog.mode === 'confirm'">
                    <button class="secondary-button management-tool-button" type="button" :disabled="busy" @click="accountActionDialog = null">Hủy</button>
                    <button
                        class="confirm-delete-button"
                        :class="{ 'account-action-confirm-button': accountActionDialog.action === 'password' || !accountActionDialog.row.is_active }"
                        type="button"
                        :disabled="busy"
                        @click="confirmAccountAction"
                    >{{ busy ? 'Đang xử lý…' : accountActionDialog.action === 'password' ? 'Đặt lại mật khẩu' : accountActionDialog.row.is_active ? 'Vô hiệu hóa' : 'Kích hoạt' }}</button>
                </template>
                <button v-else class="primary-button management-tool-button" type="button" autofocus @click="accountActionDialog = null">Đóng</button>
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
            :class="managementResult.success ? 'account-creation-success-dialog' : 'account-creation-failure-dialog'"
            role="dialog"
            aria-modal="true"
            aria-labelledby="account-creation-result-title"
            aria-describedby="account-creation-result-message"
            tabindex="-1"
        >
            <span class="confirm-dialog-icon account-creation-result-icon" :class="managementResult.success ? 'account-creation-success-icon' : 'account-action-failure-icon'" aria-hidden="true">{{ managementResult.success ? '✓' : '!' }}</span>
            <p class="account-creation-result-eyebrow">{{ managementResult.success ? 'HOÀN TẤT' : 'CÓ LỖI' }}</p>
            <h3 id="account-creation-result-title">{{ managementResult.title }}</h3>
            <p id="account-creation-result-message">{{ managementResult.message }}</p>
            <span v-if="managementResult.username" class="account-creation-result-username">
                {{ managementResult.username }}
            </span>
            <div class="confirm-dialog-actions">
                <button class="primary-button management-tool-button" type="button" autofocus @click="managementResult = null">Đóng</button>
            </div>
        </section>
    </div>
</template>
