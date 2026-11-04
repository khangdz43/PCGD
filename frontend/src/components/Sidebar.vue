<script setup lang="ts">
import { computed, ref } from 'vue';

export type ModuleKey =
    | ''
    | 'households'
    | 'schools'
    | 'villages'
    | 'roles'
    | 'users'
    | 'account'
    | 'import-file'
    | 'import-history'
    | 'trash'
    | 'merge'
    | 'school-merge';

const props = defineProps<{
    activeModule: ModuleKey;
    permissions: string[];
    scopeLevel: string | null;
}>();
const emit = defineEmits<{ select: [module: ModuleKey] }>();

const collapsed = ref(window.matchMedia('(max-width: 800px)').matches);
const openGroups = ref<Record<string, boolean>>({
    system: true,
    catalog: true,
    business: true,
    special: true,
});

const groups = computed(() => [
    {
        id: 'system',
        label: 'Hệ thống',
        items: [
            { key: 'users' as const, label: 'Tài khoản & phân quyền', icon: 'users', permission: 'view-users' },
            { key: 'roles' as const, label: 'Nhóm người dùng', icon: 'shield', permission: 'view-roles' },
        ].filter(item => props.permissions.includes(item.permission)),
    },
    {
        id: 'catalog',
        label: 'Danh mục',
        items: [
            { key: 'villages' as const, label: 'Thôn / xóm', icon: 'map', permission: 'view-villages' },
            { key: 'schools' as const, label: 'Trường học', icon: 'school', permission: 'view-schools' },
        ].filter(item => props.permissions.includes(item.permission)),
    },
    {
        id: 'business',
        label: 'Nghiệp vụ',
        items: [
            { key: 'households' as const, label: 'Phiếu điều tra', icon: 'clipboard', permission: 'view-households', actionPermission: 'view-households' },
        ].filter(item => props.permissions.includes(item.permission) || props.permissions.includes(item.actionPermission)),
    },
    {
        id: 'special',
        label: 'Tiện ích',
        items: [
            { key: 'merge' as const, label: 'Gộp thôn / xóm', icon: 'merge', permission: 'view-village-merges' },
            { key: 'school-merge' as const, label: 'Gộp trường học', icon: 'merge', permission: 'view-school-merges' },
            { key: 'trash' as const, label: 'Thùng rác', icon: 'trash', permission: 'view-trash' },
        ].filter(item =>
            props.permissions.includes(item.permission) &&
            !(props.scopeLevel === 'global' && ['merge', 'school-merge'].includes(item.key)),
        ),
    },
]);

function toggleGroup(id: string): void {
    openGroups.value[id] = !openGroups.value[id];
}
</script>

<template>
    <aside class="sidebar" :class="{ 'sidebar-collapsed': collapsed }">
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true">PC</span>
            <div v-if="!collapsed" class="sidebar-brand-copy">
                <strong>PCGD - XMC</strong>
                <span>Hệ thống quản lý</span>
            </div>
            <button
                class="sidebar-collapse"
                type="button"
                aria-controls="primary-navigation"
                :aria-label="collapsed ? 'Mở rộng thanh điều hướng' : 'Thu gọn thanh điều hướng'"
                :title="collapsed ? 'Mở rộng menu' : 'Thu gọn menu'"
                @click="collapsed = !collapsed"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
            </button>
        </div>

        <nav id="primary-navigation" class="sidebar-nav" aria-label="Điều hướng chính">
            <button
                class="sidebar-link"
                :class="{ 'is-active': activeModule === '' }"
                type="button"
                :title="collapsed ? 'Tổng quan' : undefined"
                @click="emit('select', '')"
            >
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" /></svg></span>
                <span v-if="!collapsed">Tổng quan</span>
            </button>

            <section v-for="group in groups" :key="group.id" v-show="group.items.length" class="sidebar-group">
                <button
                    v-if="!collapsed"
                    class="sidebar-group-title"
                    type="button"
                    :aria-expanded="openGroups[group.id]"
                    @click="toggleGroup(group.id)"
                >
                    <span>{{ group.label }}</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true" :class="{ 'is-rotated': openGroups[group.id] }"><path d="m7 10 5 5 5-5" /></svg>
                </button>
                <div v-if="collapsed || openGroups[group.id]" class="sidebar-group-items">
                    <button
                        v-for="item in group.items"
                        :key="item.key"
                        class="sidebar-link"
                        :class="{
                            'is-active': activeModule === item.key,
                            'sidebar-account-link': item.key === 'users',
                        }"
                        type="button"
                        :title="collapsed ? item.label : undefined"
                        @click="emit('select', item.key)"
                    >
                        <span class="nav-icon">
                            <svg v-if="item.icon === 'users'" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m17 0v-2a4 4 0 0 0-3-3.87M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-7.87a4 4 0 0 1 0 7.75" /></svg>
                            <svg v-else-if="item.icon === 'shield'" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z" /><path d="m9 12 2 2 4-4" /></svg>
                            <svg v-else-if="item.icon === 'map'" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15m6-12v15" /></svg>
                            <svg v-else-if="item.icon === 'school'" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-6h6v6" /></svg>
                            <svg v-else-if="item.icon === 'clipboard'" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2" /><path d="M9 4.5h6a1.5 1.5 0 0 0-3-1 1.5 1.5 0 0 0-3 1ZM9 11h6m-6 4h6" /></svg>
                            <svg v-else-if="item.icon === 'file'" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6m-12 5h8m-8 4h8" /></svg>
                            <svg v-else-if="item.icon === 'merge'" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3v12a6 6 0 0 0 6 6h6m-4-4 4 4-4 4M6 3l4 4M6 3 2 7m10-4h6m-4-4 4 4-4 4" /></svg>
                            <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18m-2 0-.9 14H5.9L5 6m4 0V4h6v2m-5 4v6m4-6v6" /></svg>
                        </span>
                        <span v-if="!collapsed">{{ item.label }}</span>
                    </button>
                </div>
            </section>
        </nav>

    </aside>
</template>
