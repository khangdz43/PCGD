<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api, errorMessage } from './api';

type ModuleKey = 'households' | 'schools' | 'villages' | 'roles' | 'users' | 'account' | 'imports' | 'trash' | 'merge';
type Row = Record<string, unknown>;

const props = defineProps<{ moduleKey: ModuleKey; permissions: string[] }>();
const emit = defineEmits<{ close: [] }>();

const busy = ref(false);
const error = ref('');
const notice = ref('');
const rows = ref<Row[]>([]);
const selected = ref<Row | null>(null);
const form = ref<Record<string, string>>({});
const permissionIds = ref<string[]>([]);
const permissionOptions = ref<Row[]>([]);
const roleOptions = ref<Row[]>([]);
const provinces = ref<Row[]>([]);
const password = ref('');
const passwordConfirmation = ref('');
const importFile = ref<File | null>(null);
const importHistory = ref<Row[]>([]);
const profile = ref<Row | null>(null);
const selectedSourceCodes = ref<string[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const householdDetail = ref<Row | null>(null);
const memberEditorOpen = ref(false);
const memberId = ref<number | null>(null);
const memberIsHead = ref(false);
const memberForm = ref<Record<string, string>>({});
const ethnicities = ref<Row[]>([]);
const educationForm = ref<Record<string, string>>({});

const moduleInfo: Record<ModuleKey, { title: string; endpoint: string; columns: string[]; fields: string[] }> = {
    households: {
        title: 'Phiếu điều tra',
        endpoint: '/households',
        columns: ['household_code', 'school_year', 'head_name', 'province_name', 'commune_name', 'village_name', 'members_count'],
        fields: ['household_code', 'school_year', 'head_first_name', 'head_last_name', 'province_code', 'commune_code', 'village_code', 'address', 'residence_type', 'residence_status'],
    },
    schools: {
        title: 'Trường học',
        endpoint: '/schools',
        columns: ['code', 'name', 'level', 'province.name', 'commune.name'],
        fields: ['name', 'level', 'province_code', 'commune_code'],
    },
    villages: {
        title: 'Thôn / xóm',
        endpoint: '/villages',
        columns: ['code', 'name', 'commune.name', 'commune.province.name'],
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
        columns: ['name', 'username', 'scope_level', 'is_active'],
        fields: ['name', 'username', 'role_id', 'scope_level', 'province_code', 'commune_code', 'village_codes'],
    },
    account: { title: 'Tài khoản của tôi', endpoint: '/account', columns: [], fields: [] },
    imports: { title: 'Nhập / xuất Excel', endpoint: '/households/imports', columns: [], fields: [] },
    trash: { title: 'Thùng rác', endpoint: '/trash', columns: ['item_type', 'deleted_at', 'record.household_code'], fields: [] },
    merge: { title: 'Gộp thôn / xóm', endpoint: '/villages/merge', columns: [], fields: ['target_code', 'source_codes'] },
};

const info = computed(() => moduleInfo[props.moduleKey]);
const editing = computed(() => selected.value !== null);
const fieldsForForm = computed(() => info.value.fields);
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
const roleGroups = computed(() => {
    const order = ['global', 'province', 'commune', 'village'];
    return order
        .map(scope => ({
            scope,
            label: scopeLabels[scope] ?? scope,
            rows: rows.value.filter(row => (row.scope_level || 'global') === scope),
        }))
        .filter(group => group.rows.length > 0);
});
const fieldLabels: Record<string, string> = {
    household_code: 'Mã phiếu',
    school_year: 'Năm học',
    head_name: 'Chủ hộ',
    head_first_name: 'Tên chủ hộ',
    head_last_name: 'Họ chủ hộ',
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
    role_id: 'Nhóm quyền',
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
const canCreate = computed(() => props.permissions.includes(
    props.moduleKey === 'households' ? 'create-households'
        : props.moduleKey === 'schools' ? 'manage-schools'
            : props.moduleKey === 'villages' ? 'manage-villages'
                : props.moduleKey === 'roles' ? 'manage-access-control'
                    : props.moduleKey === 'users' ? 'manage-user-accounts' : '',
));
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

const householdPersons = computed(() => asRows(householdDetail.value?.persons));

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

async function load(): Promise<void> {
    busy.value = true;
    error.value = '';
    try {
        if (props.moduleKey === 'account') {
            profile.value = (await api.get<Row>('/account')).data;
        } else if (props.moduleKey === 'imports') {
            const [history, settings] = await Promise.all([
                api.get<{ imports?: { data?: Row[] } }>('/households/imports', { params: { page: currentPage.value } }),
                api.get<Row>('/households/import/form'),
            ]);
            importHistory.value = history.data.imports?.data ?? [];
            const page = history.data.imports as { last_page?: number } | undefined;
            lastPage.value = page?.last_page ?? 1;
            const settingsData = settings.data;
            provinces.value = asRows(settingsData.provinces);
            form.value = {
                province_code: String(settingsData.province_code ?? ''),
                commune_code: String(settingsData.commune_code ?? ''),
                village_code: String(settingsData.village_code ?? ''),
            };
        } else if (props.moduleKey === 'merge') {
            rows.value = asRows((await api.get<Row>('/villages/merge')).data);
            form.value = { target_code: '', source_codes: '' };
        } else {
            const result = (await api.get<Row>(info.value.endpoint, { params: { page: currentPage.value } })).data;
            if (props.moduleKey === 'schools') rows.value = asRows(result.schools);
            else if (props.moduleKey === 'villages') rows.value = asRows(result.villages);
            else if (props.moduleKey === 'households') rows.value = asRows(result.data);
            else if (props.moduleKey === 'roles') rows.value = asRows(result.data);
            else if (props.moduleKey === 'users') rows.value = asRows(result.allUsers);
            else rows.value = asRows(result.items);
            const collection = props.moduleKey === 'households' ? result
                : props.moduleKey === 'schools' ? result.schools
                : props.moduleKey === 'villages' ? result.villages : result.allUsers;
            if (typeof collection === 'object' && collection !== null && 'last_page' in collection) {
                lastPage.value = Number((collection as { last_page: number }).last_page);
                if ('current_page' in collection) currentPage.value = Number((collection as { current_page: number }).current_page);
            } else if (props.moduleKey === 'trash') {
                const page = result.items;
                if (typeof page === 'object' && page !== null && 'last_page' in page) {
                    lastPage.value = Number((page as { last_page: number }).last_page);
                    if ('current_page' in page) currentPage.value = Number((page as { current_page: number }).current_page);
                }
            }
            if (Array.isArray(result.provinces)) provinces.value = asRows(result.provinces);
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
        name: '', username: '', password: '', password_confirmation: '', role_id: '', scope_level: '',
        province_code: '', commune_code: '', village_codes: '', is_active: '1',
    };
    if (props.moduleKey === 'merge') return { target_code: '', source_codes: '' };
    if (props.moduleKey === 'imports') return { province_code: '', commune_code: '', village_code: '' };
    return {};
}

async function beginEdit(row?: Row): Promise<void> {
    selected.value = row ?? null;
    error.value = '';
    notice.value = '';
    permissionOptions.value = [];
    roleOptions.value = [];
    const id = row?.id ?? row?.code;
    const route = props.moduleKey === 'schools'
        ? id === undefined ? '/schools/form' : `/schools/${id}/form`
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
    if (!route) return;
    busy.value = true;
    try {
        const data = (await api.get<Row>(route)).data;
        if (Array.isArray(data.provinces)) provinces.value = asRows(data.provinces);
        if (props.moduleKey === 'roles') {
            permissionOptions.value = asRows(data.permissions);
            const role = typeof data.role === 'object' && data.role !== null ? data.role as Row : {};
            for (const key of ['name', 'description', 'scope_level']) {
                form.value[key] = role[key] === null || role[key] === undefined ? '' : String(role[key]);
            }
            permissionIds.value = Array.isArray(role.permissions) ? role.permissions.map(String) : [];
        } else if (props.moduleKey === 'users') {
            roleOptions.value = asRows(data.roles);
            const user = typeof data.user === 'object' && data.user !== null ? data.user as Row : {};
            for (const key of Object.keys(form.value)) {
                const value = user[key];
                if (Array.isArray(value)) form.value[key] = value.map(String).join(', ');
                else if (value !== undefined && value !== null) form.value[key] = String(value);
            }
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
            const modelKey = props.moduleKey === 'schools' ? 'school'
                : props.moduleKey === 'villages' ? 'village' : 'household';
            const model = typeof data[modelKey] === 'object' && data[modelKey] !== null ? data[modelKey] as Row : {};
            for (const key of Object.keys(form.value)) {
                const value = model[key];
                if (value !== undefined && value !== null) form.value[key] = String(value);
            }
            if (data.province_code !== undefined) form.value.province_code = String(data.province_code);
            if (data.commune_code !== undefined) form.value.commune_code = String(data.commune_code);
            if (props.moduleKey === 'schools') {
                roleOptions.value = [];
            }
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

async function openHousehold(row: Row): Promise<void> {
    const id = row.id;
    if (id === undefined) return;
    try {
        householdDetail.value = (await api.get<{ data: Row }>(`/households/${id}`)).data.data;
        memberEditorOpen.value = false;
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function beginMember(isHead: boolean, person?: Row): Promise<void> {
    const householdId = householdDetail.value?.id;
    if (householdId === undefined) return;
    memberId.value = person?.id === undefined ? null : Number(person.id);
    memberIsHead.value = person ? person.relationship_with_head === 'Chủ hộ' : isHead;
    memberForm.value = {
        first_name: '', last_name: '', dob: '', gender: 'NAM', ethnicity_code: '',
        relationship_with_head: '', religion: '', priority_type: '', parent_name: '',
        phone: '', note: '',
    };
    educationForm.value = { school_year: '' };
    ethnicities.value = [];
    try {
        const path = memberId.value === null
            ? `/households/${householdId}/members/form`
            : `/households/${householdId}/members/${memberId.value}/form`;
        const result = (await api.get<Row>(path, { params: { is_head: memberIsHead.value ? 1 : 0 } })).data;
        const person = typeof result.person === 'object' && result.person !== null ? result.person as Row : {};
        for (const key of Object.keys(memberForm.value)) {
            const value = person[key];
            if (value !== null && value !== undefined) memberForm.value[key] = String(value);
        }
        const education = typeof result.education === 'object' && result.education !== null
            ? result.education as Row
            : {};
        educationForm.value.school_year = String(education.school_year ?? '');
        ethnicities.value = asRows(result.ethnicities);
        memberEditorOpen.value = true;
        error.value = '';
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function saveMember(): Promise<void> {
    const householdId = householdDetail.value?.id;
    if (householdId === undefined) return;
    const body: Row = {
        ...memberForm.value,
        is_head: memberIsHead.value,
        education: educationForm.value,
        disability: {},
    };
    try {
        if (memberId.value === null) {
            await api.post(`/households/${householdId}/members`, body);
        } else {
            await api.put(`/households/${householdId}/members/${memberId.value}`, body);
        }
        notice.value = memberId.value === null ? 'Đã thêm nhân khẩu.' : 'Đã cập nhật nhân khẩu.';
        memberEditorOpen.value = false;
        await openHousehold({ id: householdId });
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function deleteMember(person: Row): Promise<void> {
    const householdId = householdDetail.value?.id;
    if (householdId === undefined || person.id === undefined
        || !window.confirm('Bạn có chắc muốn xóa nhân khẩu này?')) return;
    try {
        await api.delete(`/households/${householdId}/members/${person.id}`);
        notice.value = 'Đã xóa nhân khẩu.';
        await openHousehold({ id: householdId });
    } catch (cause) {
        error.value = errorMessage(cause);
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

async function save(): Promise<void> {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const id = selected.value?.id ?? selected.value?.code;
        const endpoint = info.value.endpoint;
        const body = payload();
        let createdHouseholdId: unknown;
        if (editing.value) {
            await api.put(`${endpoint}/${id}`, body);
        } else {
            const response = await api.post<{ data?: { id?: unknown } }>(endpoint, body);
            createdHouseholdId = response.data.data?.id;
        }
        notice.value = editing.value ? 'Đã cập nhật.' : 'Đã tạo mới.';
        if (props.moduleKey === 'households' && typeof createdHouseholdId === 'number') {
            await openHousehold({ id: createdHouseholdId });
        }
        selected.value = null;
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

async function remove(row: Row): Promise<void> {
    const id = row.id ?? row.code;
    if (id === undefined || !window.confirm('Bạn có chắc muốn xóa mục này?')) return;
    busy.value = true;
    error.value = '';
    try {
        await api.delete(`${info.value.endpoint}/${id}`);
        notice.value = 'Đã xóa.';
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        busy.value = false;
    }
}

async function changePassword(): Promise<void> {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await api.put('/account/password', { password: password.value, password_confirmation: passwordConfirmation.value });
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
        await api.post('/households/import', data);
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
        await api.patch(`/trash/${type}/${row.id}/restore`);
        notice.value = 'Đã khôi phục mục đã chọn.';
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function permanentlyDeleteTrash(row: Row): Promise<void> {
    const type = row.item_type === 'household' ? 'households' : 'persons';
    if (!window.confirm('Xóa vĩnh viễn mục này? Thao tác này không thể hoàn tác.')) return;
    try {
        await api.delete(`/trash/${type}/${row.id}`);
        notice.value = 'Đã xóa vĩnh viễn mục đã chọn.';
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function changeUserStatus(row: Row): Promise<void> {
    try {
        await api.patch(`/users/${row.id}/status`, { is_active: !row.is_active });
        notice.value = 'Đã cập nhật trạng thái tài khoản.';
        await load();
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function resetUserPassword(row: Row): Promise<void> {
    if (!window.confirm('Đặt lại mật khẩu của tài khoản này về mật khẩu tạm?')) return;
    try {
        const result = await api.post<{ message: string }>(`/users/${row.id}/reset-password`);
        notice.value = result.data.message;
    } catch (cause) {
        error.value = errorMessage(cause);
    }
}

async function changePage(direction: -1 | 1): Promise<void> {
    currentPage.value = Math.min(lastPage.value, Math.max(1, currentPage.value + direction));
    await load();
}

watch(() => props.moduleKey, () => {
    selected.value = null;
    form.value = {};
    notice.value = '';
    void load();
});

onMounted(() => void load());
</script>

<template>
    <section class="workspace">
        <header class="section-heading">
            <div class="workspace-title">
                <span class="workspace-title-mark" aria-hidden="true">{{ info.title.slice(0, 1) }}</span>
                <div>
                    <p class="workspace-eyebrow">QUẢN LÝ DANH MỤC</p>
                    <h2>{{ info.title }}</h2>
                    <p class="muted">{{ sectionDescription }}</p>
                </div>
            </div>
            <div class="pagination-actions">
                <span v-if="rows.length" class="record-count">{{ rows.length }} bản ghi</span>
                <button class="secondary-button" type="button" @click="emit('close')">Quay lại</button>
                <button
                    v-if="canCreate && ['households', 'schools', 'villages', 'roles', 'users'].includes(moduleKey)"
                    class="primary-button"
                    type="button"
                    @click="beginEdit()"
                >Thêm mới</button>
            </div>
        </header>

        <p v-if="error" class="notice" role="alert">{{ error }}</p>
        <p v-if="notice" class="success-notice" role="status">{{ notice }}</p>
        <p v-if="busy" class="muted">Đang xử lý…</p>

        <form v-if="selected !== null || ['households', 'schools', 'villages', 'roles', 'users', 'merge'].includes(moduleKey) && Object.keys(form).length" class="workspace-form" @submit.prevent="save">
            <h3>{{ editing ? 'Cập nhật' : 'Tạo mới' }} {{ info.title.toLocaleLowerCase('vi') }}</h3>
            <label v-for="field in fieldsForForm.filter(field => !(moduleKey === 'merge' && field === 'source_codes'))" :key="field" class="workspace-field">
                <span>{{ fieldLabel(field) }}</span>
                <select v-if="field === 'level'" v-model="form[field]">
                    <option value="">Chọn cấp học</option>
                    <option value="MN">Mầm non</option>
                    <option value="TH">Tiểu học</option>
                    <option value="THCS">Trung học cơ sở</option>
                    <option value="THPT">Trung học phổ thông</option>
                </select>
                <select v-else-if="field === 'scope_level'" v-model="form[field]">
                    <option value="">Chọn cấp quản lý</option>
                    <option v-for="level in ['global', 'province', 'commune', 'village']" :key="level" :value="level">{{ scopeLabels[level] }}</option>
                </select>
                <select v-else-if="field === 'role_id'" v-model="form[field]">
                    <option value="">Chọn nhóm quyền</option>
                    <option v-for="role in roleOptions" :key="String(role.id)" :value="String(role.id)">{{ role.name }}</option>
                </select>
                <select v-else-if="field === 'province_code' && provinces.length" v-model="form[field]">
                    <option value="">Chọn tỉnh / thành phố</option>
                    <option v-for="province in provinces" :key="String(province.code)" :value="String(province.code)">{{ province.name }}</option>
                </select>
                <select v-else-if="field === 'residence_type'" v-model="form[field]">
                    <option value="THUONG_TRU">Thường trú</option>
                    <option value="TAM_TRU">Tạm trú</option>
                    <option value="KHAC">Khác</option>
                </select>
                <input v-else v-model="form[field]" :type="field.includes('password') ? 'password' : 'text'" :required="['name', 'username', 'role_id', 'scope_level'].includes(field)">
            </label>
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
                <input v-model="form.password" type="password" :required="!editing">
            </label>
            <label v-if="moduleKey === 'users' && !editing" class="workspace-field">
                <span>Nhập lại mật khẩu</span>
                <input v-model="form.password_confirmation" type="password" required>
            </label>
            <label v-if="moduleKey === 'roles'" class="workspace-field">
                <span>Quyền nghiệp vụ</span>
                <div class="permission-options">
                    <label v-for="permission in permissionOptions" :key="String(permission.id)">
                        <input v-model="permissionIds" type="checkbox" :value="String(permission.id)">
                        {{ permission.label }}
                    </label>
                </div>
            </label>
            <label v-if="moduleKey === 'users'" class="workspace-field">
                <span>Trạng thái</span>
                <select v-model="form.is_active"><option value="1">Đang hoạt động</option><option value="0">Vô hiệu hóa</option></select>
            </label>
            <div class="pagination-actions">
                <button class="primary-button" type="submit" :disabled="busy">Lưu</button>
                <button class="secondary-button" type="button" @click="selected = null; form = {}">Hủy</button>
            </div>
        </form>

        <form v-if="moduleKey === 'account'" class="workspace-form" @submit.prevent="changePassword">
            <h3>Thông tin tài khoản</h3>
            <pre v-if="profile" class="json-summary">{{ JSON.stringify(profile, null, 2) }}</pre>
            <label class="workspace-field"><span>Mật khẩu mới</span><input v-model="password" type="password" required></label>
            <label class="workspace-field"><span>Xác nhận mật khẩu</span><input v-model="passwordConfirmation" type="password" required></label>
            <button class="primary-button" type="submit" :disabled="busy">Đổi mật khẩu</button>
        </form>

        <section v-if="moduleKey === 'imports'" class="workspace-form">
            <form @submit.prevent="submitImport">
                <h3>Nhập dữ liệu Excel</h3>
                <label class="workspace-field"><span>Mã tỉnh / thành phố</span><select v-model="form.province_code"><option value="">Chọn tỉnh</option><option v-for="province in provinces" :key="String(province.code)" :value="String(province.code)">{{ province.name }}</option></select></label>
                <label class="workspace-field"><span>Mã xã</span><input v-model="form.commune_code" type="text" required></label>
                <label class="workspace-field"><span>Mã thôn / xóm</span><input v-model="form.village_code" type="text" required></label>
                <label class="workspace-field"><span>Chọn file</span><input type="file" accept=".xls,.xlsx,.csv" @change="setImportFile"></label>
                <button class="primary-button" type="submit" :disabled="busy">Tải file lên</button>
                <a class="secondary-button inline-button" href="/api/v1/households/template">Tải file mẫu</a>
                <a class="secondary-button inline-button" href="/api/v1/households/export">Xuất dữ liệu</a>
            </form>
            <h3>Lịch sử nhập</h3>
            <div v-for="item in importHistory" :key="String(item.id)" class="workspace-row">
                <span>{{ item.file_name }} · {{ item.status }}</span>
                <div class="pagination-actions">
                    <a :href="`/api/v1/households/imports/${item.id}/download`">Tải file</a>
                    <a v-if="item.status === 'FAILED'" :href="`/api/v1/households/imports/${item.id}/download-errors`">Tải dòng lỗi</a>
                </div>
            </div>
            <div class="pagination-actions">
                <button class="secondary-button" type="button" :disabled="currentPage <= 1" @click="changePage(-1)">Trước</button>
                <span class="muted">Trang {{ currentPage }} / {{ lastPage }}</span>
                <button class="secondary-button" type="button" :disabled="currentPage >= lastPage" @click="changePage(1)">Sau</button>
            </div>
        </section>

        <section v-if="moduleKey === 'trash'" class="workspace-table-wrap">
            <div v-for="row in rows" :key="`${row.item_type}-${row.id}`" class="workspace-row">
                <span>{{ pathValue(row, 'item_type') }} · {{ pathValue(row, 'record.household_code') }} · {{ pathValue(row, 'deleted_at') }}</span>
                <button class="secondary-button" type="button" @click="restoreTrash(row)">Khôi phục</button>
                <button class="secondary-button danger-button" type="button" @click="permanentlyDeleteTrash(row)">Xóa vĩnh viễn</button>
            </div>
        </section>

        <section v-else-if="rows.length && ['households', 'schools', 'villages', 'roles', 'users'].includes(moduleKey)" class="workspace-table-wrap">
            <table class="workspace-table">
                <thead>
                    <tr>
                        <th v-for="column in info.columns" :key="column">{{ fieldLabel(column) }}</th>
                        <th v-if="moduleKey === 'roles'">Quyền hạn</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody v-for="group in (moduleKey === 'roles' ? roleGroups : [{ scope: 'all', label: '', rows }])" :key="group.scope">
                    <tr v-if="moduleKey === 'roles'" class="scope-group-row">
                        <th :colspan="info.columns.length + 2">{{ group.label }} <span>· {{ group.rows.length }} nhóm</span></th>
                    </tr>
                    <tr v-for="row in group.rows" :key="String(row.id ?? row.code)">
                        <td v-for="column in info.columns" :key="column">{{ pathValue(row, column) }}</td>
                        <td v-if="moduleKey === 'roles'">
                            <div class="role-permissions">
                                <span v-for="permission in (Array.isArray(row.permissions) ? row.permissions : [])" :key="String(permission)" class="perm-tag">
                                    {{ permissionLabel(permission) }}
                                </span>
                                <span v-if="Array.isArray(row.permissions) && row.permissions.length === 0" class="muted">Không có quyền</span>
                            </div>
                        </td>
                        <td class="table-actions">
                            <button v-if="moduleKey === 'households'" class="secondary-button" type="button" @click="openHousehold(row)">Nhân khẩu</button>
                            <button v-if="canUpdate && (moduleKey !== 'roles' || row.can_manage)" class="secondary-button" type="button" @click="beginEdit(row)">Sửa</button>
                            <button v-if="canDelete && (moduleKey !== 'roles' || row.can_manage)" class="secondary-button danger-button" type="button" @click="remove(row)">Xóa</button>
                            <button v-if="moduleKey === 'users' && row.can_change_account_status" class="secondary-button" type="button" @click="changeUserStatus(row)">{{ row.is_active ? 'Vô hiệu hóa' : 'Kích hoạt' }}</button>
                            <button v-if="moduleKey === 'users' && row.can_reset_account_password" class="secondary-button" type="button" @click="resetUserPassword(row)">Đặt lại mật khẩu</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <div v-if="lastPage > 1 && ['households', 'schools', 'villages', 'users', 'trash'].includes(moduleKey)" class="pagination-actions workspace-pagination">
            <button class="secondary-button" type="button" :disabled="currentPage <= 1" @click="changePage(-1)">Trước</button>
            <span class="muted">Trang {{ currentPage }} / {{ lastPage }}</span>
            <button class="secondary-button" type="button" :disabled="currentPage >= lastPage" @click="changePage(1)">Sau</button>
        </div>
        <section v-if="moduleKey === 'households' && householdDetail" class="household-detail">
            <div class="section-heading">
                <div>
                    <h3>Nhân khẩu của phiếu {{ householdDetail.household_code }}</h3>
                    <p class="muted">{{ householdPersons.length }} nhân khẩu</p>
                </div>
                <div class="pagination-actions">
                    <button
                        v-if="!householdDetail.has_household_head"
                        v-show="permissions.includes('create-households')"
                        class="primary-button"
                        type="button"
                        @click="beginMember(true)"
                    >Thêm chủ hộ</button>
                    <button
                        v-else
                        v-show="permissions.includes('create-households')"
                        class="primary-button"
                        type="button"
                        @click="beginMember(false)"
                    >Thêm nhân khẩu</button>
                </div>
            </div>
            <div v-for="person in householdPersons" :key="String(person.id)" class="workspace-row">
                <span>{{ person.name }} · {{ person.relationship_with_head }} · {{ pathValue(person, 'dob') }}</span>
                <div class="pagination-actions">
                    <button v-if="permissions.includes('update-households')" class="secondary-button" type="button" @click="beginMember(false, person)">Sửa</button>
                    <button v-if="permissions.includes('delete-households')" class="secondary-button danger-button" type="button" @click="deleteMember(person)">Xóa</button>
                </div>
            </div>
            <form v-if="memberEditorOpen" class="workspace-form" @submit.prevent="saveMember">
                <h3>{{ memberId === null ? 'Thêm' : 'Cập nhật' }} {{ memberIsHead ? 'chủ hộ' : 'nhân khẩu' }}</h3>
                <label v-for="field in Object.keys(memberForm)" :key="field" class="workspace-field">
                    <span>{{ field }}</span>
                    <select v-if="field === 'gender'" v-model="memberForm[field]">
                        <option value="NAM">Nam</option>
                        <option value="NU">Nữ</option>
                    </select>
                    <select v-else-if="field === 'ethnicity_code'" v-model="memberForm[field]" required>
                        <option value="">Chọn dân tộc</option>
                        <option v-for="ethnicity in ethnicities" :key="String(ethnicity.code)" :value="String(ethnicity.code)">{{ ethnicity.name }}</option>
                    </select>
                    <input v-else v-model="memberForm[field]" :type="field === 'dob' ? 'date' : 'text'" :required="['first_name', 'last_name', 'dob'].includes(field) || field === 'relationship_with_head' && !memberIsHead">
                </label>
                <label class="workspace-field">
                    <span>Năm học đang xét</span>
                    <input v-model="educationForm.school_year" type="text" required>
                </label>
                <div class="pagination-actions">
                    <button class="primary-button" type="submit">Lưu nhân khẩu</button>
                    <button class="secondary-button" type="button" @click="memberEditorOpen = false">Hủy</button>
                </div>
            </form>
        </section>
        <p v-else-if="['households', 'schools', 'villages', 'roles', 'users', 'trash'].includes(moduleKey) && !busy" class="empty-state">Không có dữ liệu.</p>
    </section>
</template>
