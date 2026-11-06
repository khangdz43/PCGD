import axios, { AxiosError } from 'axios';
import type {
    ApiErrorResponse,
    HouseholdDetail,
    HouseholdFilters,
    HouseholdSummary,
    Paginated,
    RoleMember,
    RoleSummary,
    User,
} from './types';

export const api = axios.create({
    baseURL: '/api/v1',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    withXSRFToken: true,
});

export const ACCOUNT_DISABLED_EVENT = 'pcgd:account-disabled';
export const SESSION_EXPIRED_EVENT = 'pcgd:session-expired';
export const HOUSEHOLD_IMPORT_SUBMITTED_EVENT = 'pcgd:household-import-submitted';
export const HOUSEHOLD_IMPORT_COMPLETED_EVENT = 'pcgd:household-import-completed';

let refreshCsrfPromise: Promise<unknown> | null = null;
const locationOptionsCache = new Map<string, {
    expiresAt: number;
    value: Promise<unknown[]>;
}>();
const LOCATION_OPTIONS_CACHE_TTL = 5 * 60 * 1000;

api.interceptors.response.use(
    response => {
        if (response.config.method?.toLowerCase() !== 'get') {
            locationOptionsCache.clear();
        }
        return response;
    },
    async (cause: AxiosError) => {
        const body = asRecord(cause.response?.data);
        const apiError = asRecord(body.error);
        if (cause.response?.status === 401) {
            window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT));
        }
        if (
            cause.response?.status === 403
            && apiError.code === 'FORBIDDEN'
            && body.message === 'Tài khoản đã bị vô hiệu hóa.'
        ) {
            window.dispatchEvent(new Event(ACCOUNT_DISABLED_EVENT));
        }

        const config = cause.config as (typeof cause.config & { _csrfRetried?: boolean }) | undefined;
        if (cause.response?.status !== 419 || !config || config._csrfRetried) {
            throw cause;
        }

        config._csrfRetried = true;
        refreshCsrfPromise ??= api.get('/csrf-cookie').finally(() => {
            refreshCsrfPromise = null;
        });
        await refreshCsrfPromise;
        return api.request(config);
    },
);

interface ApiSuccess<T> {
    data: T;
    meta?: Record<string, unknown>;
}

type RecordValue = Record<string, unknown>;

function asRecord(value: unknown): RecordValue {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? value as RecordValue
        : {};
}

export function apiData<T>(response: unknown): T {
    const body = asRecord(response);

    return (Object.hasOwn(body, 'data') ? body.data : response) as T;
}

export function apiRows<T>(value: unknown): T[] {
    if (Array.isArray(value)) return value as T[];

    const record = asRecord(value);
    if (Array.isArray(record.data)) return record.data as T[];

    return [];
}

export function getLocationOptions<T>(path: string): Promise<T[]> {
    if (
        path !== '/provinces'
        && !/^\/provinces\/[^/]+\/communes$/.test(path)
        && !/^\/communes\/[^/]+\/villages$/.test(path)
    ) {
        return Promise.reject(new Error('Đường dẫn danh mục địa bàn không hợp lệ.'));
    }

    const villageOptionsPath = /^\/communes\/[^/]+\/villages$/.test(path);
    if (!villageOptionsPath) {
        const cached = locationOptionsCache.get(path);
        if (cached && cached.expiresAt > Date.now()) {
            return cached.value as Promise<T[]>;
        }
    }

    const value = api.get<unknown>(path).then(({ data }) =>
        apiRows<T>(apiData<unknown>(data)),
    );
    if (villageOptionsPath) return value;

    const entry = {
        expiresAt: Date.now() + LOCATION_OPTIONS_CACHE_TTL,
        value: value as Promise<unknown[]>,
    };
    locationOptionsCache.set(path, entry);
    void value.catch(() => {
        if (locationOptionsCache.get(path) === entry) {
            locationOptionsCache.delete(path);
        }
    });
    return value;
}

export function apiPage<T>(response: unknown, value?: unknown): Paginated<T> {
    const body = asRecord(response);
    const payload = value === undefined ? apiData<unknown>(response) : value;
    const page = asRecord(payload);
    const meta = asRecord(body.meta);
    const items = Array.isArray(payload)
        ? payload as T[]
        : Array.isArray(page.data) ? page.data as T[] : apiRows<T>(payload);

    return {
        data: items,
        current_page: Number(page.current_page ?? meta.current_page ?? 1),
        last_page: Number(page.last_page ?? meta.last_page ?? 1),
        total: Number(page.total ?? meta.total ?? items.length),
    };
}

export async function getSession(): Promise<User> {
    const { data } = await api.get<ApiSuccess<{ user: User }>>('/session');
    return apiData<{ user: User }>(data).user;
}

export async function login(username: string, password: string, remember: boolean): Promise<User> {
    await api.get('/csrf-cookie');
    const { data } = await api.post<ApiSuccess<{ user: User }>>('/login', { username, password, remember });
    return apiData<{ user: User }>(data).user;
}

export async function logout(): Promise<void> {
    await api.post('/logout');
}

export async function getHouseholds(page = 1, filters?: HouseholdFilters): Promise<Paginated<HouseholdSummary>> {
    const { data } = await api.get<unknown>('/households', { params: { page, ...filters } });
    const payload = apiData<unknown>(data);
    return apiPage<HouseholdSummary>(data, payload);
}

export async function getHousehold(id: number): Promise<HouseholdDetail> {
    const { data } = await api.get<unknown>(`/households/${id}`);
    const payload = apiData<unknown>(data);
    const resource = asRecord(payload);
    return (Object.hasOwn(resource, 'data') ? resource.data : payload) as HouseholdDetail;
}

export async function getRoles(): Promise<RoleSummary[]> {
    const { data } = await api.get<unknown>('/roles');
    return apiRows<RoleSummary>(apiData<unknown>(data));
}

export async function getRoleMembers(roleId: number, page = 1): Promise<{
    role: { id: number; name: string };
    data: RoleMember[];
    current_page: number;
    last_page: number;
    total: number;
}> {
    const { data } = await api.get<unknown>(`/roles/${roleId}/users`, { params: { page } });
    const payload = asRecord(apiData<unknown>(data));
    const pagination = apiPage<RoleMember>(data, payload);
    return {
        role: payload.role as { id: number; name: string },
        ...pagination,
    };
}

export function errorMessage(error: unknown): string {
    if (error instanceof AxiosError) {
        const body = error.response?.data as ApiErrorResponse | undefined;
        const validationMessage = body?.error?.details?.[0]?.issue
            ?? (body?.errors ? Object.values(body.errors).flat()[0] : undefined);
        return validationMessage ?? body?.message ?? 'Không thể kết nối tới máy chủ. Vui lòng thử lại.';
    }

    return 'Đã xảy ra lỗi không xác định.';
}

export function validationErrors(error: unknown): Record<string, string> {
    if (!(error instanceof AxiosError)) return {};

    const body = asRecord(error.response?.data);
    const result: Record<string, string> = {};
    const details = asRecord(body.error).details;
    if (Array.isArray(details)) {
        for (const detail of details) {
            const item = asRecord(detail);
            if (typeof item.field === 'string' && typeof item.issue === 'string' && !result[item.field]) {
                result[item.field] = item.issue;
            }
        }
    }

    const errors = asRecord(body.errors);
    for (const [field, messages] of Object.entries(errors)) {
        if (!result[field] && Array.isArray(messages) && typeof messages[0] === 'string') {
            result[field] = messages[0];
        }
    }

    return result;
}
