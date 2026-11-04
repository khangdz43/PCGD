<script setup lang="ts">
import { ref } from 'vue';
import type { User } from '../types';

defineProps<{
    user: User;
    title: string;
    busy: boolean;
}>();
const emit = defineEmits<{
    account: [];
    changePassword: [];
    logout: [];
}>();

const menuOpen = ref(false);
</script>

<template>
    <header class="topbar app-topbar">
        <div class="topbar-heading">
            <p class="topbar-overline">PHỔ CẬP GIÁO DỤC · XÓA MÙ CHỮ</p>
            <h1>{{ title }}</h1>
        </div>
        <div class="topbar-actions">
            <div class="profile-context" aria-label="Nhóm người dùng, phạm vi quản lý, cấp và năm học">
                <span class="profile-context-line" :title="`Nhóm: ${user.role_name || 'Chưa được gán nhóm'}`">
                    <strong>Nhóm:</strong> {{ user.role_name || 'Chưa được gán nhóm' }}
                </span>
                <span class="profile-context-line" :title="`Phạm vi: ${user.scope_area || 'Chưa được gán địa bàn'}`">
                    <strong>Phạm vi:</strong> {{ user.scope_area || 'Chưa được gán địa bàn' }}
                </span>
                <span class="profile-context-line" :title="`${user.scope_level_label || 'Chưa xác định'} · Năm học ${user.school_year || 'Chưa xác định'}`">
                    <strong>Cấp:</strong> {{ user.scope_level_label || 'Chưa xác định' }} ·
                    <strong>Năm học:</strong> {{ user.school_year || 'Chưa xác định' }}
                </span>
            </div>
            <div class="profile-menu-wrap">
                <button
                    class="profile-trigger"
                    type="button"
                    :aria-expanded="menuOpen"
                    @click="menuOpen = !menuOpen"
                >
                    <span class="profile-avatar">{{ user.name.slice(0, 1).toLocaleUpperCase('vi') }}</span>
                    <span class="profile-trigger-copy">
                        <strong>{{ user.name }}</strong>
                        <small>{{ user.username }}</small>
                    </span>
                    <svg class="profile-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
                </button>
                <div v-if="menuOpen" class="profile-menu">
                    <button type="button" @click="menuOpen = false; emit('account')">Thông tin cá nhân</button>
                    <button type="button" @click="menuOpen = false; emit('changePassword')">Đổi mật khẩu</button>
                    <button class="profile-menu-danger" type="button" :disabled="busy" @click="menuOpen = false; emit('logout')">
                        {{ busy ? 'Đang đăng xuất…' : 'Đăng xuất' }}
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>
