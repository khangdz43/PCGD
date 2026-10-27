export interface User {
    id: number;
    name: string;
    username: string;
    scope_level: string | null;
    province_code: string | null;
    commune_code: string | null;
    permissions?: string[];
}

export interface ApiErrorResponse {
    message?: string;
    errors?: Record<string, string[]>;
}

export interface HouseholdSummary {
    id: number;
    household_code: string;
    school_year: string;
    head_name: string;
    province_name: string | null;
    commune_name: string | null;
    village_name: string | null;
    members_count: number;
}

export interface HouseholdDetail extends HouseholdSummary {
    head_first_name: string;
    head_last_name: string;
    province: { code: string; name: string } | null;
    commune: { code: string; name: string } | null;
    village: { code: string; name: string } | null;
    address: string | null;
    residence_type: string;
    residence_status: string | null;
    has_household_head: boolean;
    head: {
        id: number;
        first_name: string;
        last_name: string;
        dob: string | null;
        gender: string | null;
        relationship_with_head: string | null;
    } | null;
    persons: {
        id: number;
        name: string;
        dob: string | null;
        gender: string | null;
        relationship_with_head: string | null;
    }[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

export interface HouseholdFilters {
    q: string;
    school_year: string;
    province_code: string;
    commune_code: string;
    village_code: string;
}

export interface RoleSummary {
    id: number;
    name: string;
    description: string | null;
    scope_level: string;
    users_count: number;
    permissions: string[];
    can_manage: boolean;
}

export interface RoleMember {
    id: number;
    name: string;
    username: string | null;
    scope_level: string | null;
    province: string | null;
    commune: string | null;
    villages: string[];
    is_active: boolean;
}
