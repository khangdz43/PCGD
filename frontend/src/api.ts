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

export async function getSession(): Promise<User> {
    const { data } = await api.get<{ user: User }>('/session');
    return data.user;
}

export async function login(username: string, password: string, remember: boolean): Promise<User> {
    await api.get('/csrf-cookie');
    const { data } = await api.post<{ user: User }>('/login', { username, password, remember });
    const sessionUser = await getSession();
    return { ...data.user, ...sessionUser };
}

export async function logout(): Promise<void> {
    await api.post('/logout');
}

export async function getHouseholds(page = 1, filters?: HouseholdFilters): Promise<Paginated<HouseholdSummary>> {
    const { data } = await api.get<Paginated<HouseholdSummary>>('/households', { params: { page, ...filters } });
    return data;
}

export async function getHousehold(id: number): Promise<HouseholdDetail> {
    const { data } = await api.get<{ data: HouseholdDetail }>(`/households/${id}`);
    return data.data;
}

export async function getRoles(): Promise<RoleSummary[]> {
    const { data } = await api.get<{ data: RoleSummary[] }>('/roles');
    return data.data;
}

export async function getRoleMembers(roleId: number, page = 1): Promise<{
    role: { id: number; name: string };
    data: RoleMember[];
    current_page: number;
    last_page: number;
    total: number;
}> {
    const { data } = await api.get(`/roles/${roleId}/members`, { params: { page } });
    return data;
}

export function errorMessage(error: unknown): string {
    if (error instanceof AxiosError) {
        const body = error.response?.data as ApiErrorResponse | undefined;
        const validationMessage = body?.errors ? Object.values(body.errors).flat()[0] : undefined;
        return validationMessage ?? body?.message ?? 'Không thể kết nối tới máy chủ. Vui lòng thử lại.';
    }

    return 'Đã xảy ra lỗi không xác định.';
}
