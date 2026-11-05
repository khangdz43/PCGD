<script setup lang="ts">
import { ref } from 'vue';

defineProps<{
    busy: boolean;
    error: string;
}>();
const emit = defineEmits<{
    submit: [credentials: { username: string; password: string; remember: boolean }];
}>();

const username = ref('');
const password = ref('');
const remember = ref(false);
const passwordVisible = ref(false);
const validationError = ref('');

function submit(): void {
    if (!username.value.trim() || !password.value) {
        validationError.value = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
        return;
    }

    validationError.value = '';
    emit('submit', {
        username: username.value.trim(),
        password: password.value,
        remember: remember.value,
    });
}
</script>

<template>
    <main class="login-page">
        <div class="login-decoration login-decoration-one"></div>
        <div class="login-decoration login-decoration-two"></div>
        <section class="login-layout">
            <div class="login-intro">
                <div class="login-brand">
                    <span class="brand-mark">PC</span>
                    <div><strong>PCGD - XMC</strong><span>Hệ thống quản lý giáo dục</span></div>
                </div>
                <div class="login-intro-copy">
                    <span class="login-kicker">NỀN TẢNG QUẢN LÝ DỮ LIỆU GIÁO DỤC</span>
                    <h1>Chung tay vì<br><em>giáo dục toàn dân.</em></h1>
                    <p>Quản lý phổ cập giáo dục và xóa mù chữ chính xác, đồng bộ và hiệu quả trên toàn địa bàn.</p>
                </div>
                <div class="login-intro-footer">
                    <span class="login-seal" aria-hidden="true">✦</span>
                    <span>Hệ thống Phổ cập giáo dục - Xóa mù chữ</span>
                </div>
            </div>

            <div class="login-card">
                <div class="login-card-heading">
                    <span class="login-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 3 3 8l9 5 9-5-9-5Zm-7 8v5l7 4 7-4v-5m3-3v7" /></svg>
                    </span>
                    <span class="login-kicker">CHÀO MỪNG TRỞ LẠI</span>
                    <h2>Đăng nhập</h2>
                    <p>Vui lòng nhập thông tin tài khoản được cấp.</p>
                </div>

                <p v-if="error || validationError" class="notice login-error" role="alert">{{ validationError || error }}</p>

                <form class="login-form" @submit.prevent="submit">
                    <label class="login-field" for="login-username">
                        <span>Tên đăng nhập</span>
                        <span class="login-input-wrap">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M5 21v-2a7 7 0 0 1 14 0v2" /></svg>
                            <input
                                id="login-username"
                                v-model="username"
                                name="username"
                                autocomplete="username"
                                placeholder="Nhập tên đăng nhập"
                                required
                            >
                        </span>
                    </label>
                    <label class="login-field" for="login-password">
                        <span>Mật khẩu</span>
                        <span class="login-input-wrap">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 1 1 8 0v3" /></svg>
                            <input
                                id="login-password"
                                v-model="password"
                                name="password"
                                :type="passwordVisible ? 'text' : 'password'"
                                autocomplete="current-password"
                                placeholder="Nhập mật khẩu"
                                required
                            >
                            <button
                                class="password-toggle"
                                type="button"
                                :aria-label="passwordVisible ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                                @click="passwordVisible = !passwordVisible"
                            >
                                {{ passwordVisible ? 'Ẩn' : 'Hiện' }}
                            </button>
                        </span>
                    </label>
                    <label class="remember-option">
                        <input v-model="remember" type="checkbox" name="remember">
                        <span>Ghi nhớ đăng nhập</span>
                    </label>
                    <button class="primary-button login-submit" type="submit" :disabled="busy">
                        <span v-if="busy" class="button-spinner" aria-hidden="true"></span>
                        {{ busy ? 'Đang xác thực…' : 'Đăng nhập hệ thống' }}
                        <span v-if="!busy" aria-hidden="true">→</span>
                    </button>
                </form>

                <div class="login-card-footer">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z" /><path d="m9 12 2 2 4-4" /></svg>
                    <span>Phiên làm việc được bảo vệ an toàn</span>
                </div>
            </div>
        </section>
        <footer class="login-page-footer">© {{ new Date().getFullYear() }} PCGD - XMC · Hệ thống quản lý phổ cập giáo dục</footer>
    </main>
</template>
