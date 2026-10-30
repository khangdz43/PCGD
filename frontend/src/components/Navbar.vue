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
    password: [];
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
                    <button type="button" @click="menuOpen = false; emit('password')">Đổi mật khẩu</button>
                    <button class="profile-menu-danger" type="button" :disabled="busy" @click="menuOpen = false; emit('logout')">
                        {{ busy ? 'Đang đăng xuất…' : 'Đăng xuất' }}
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>
