<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import Workspace from '../../Workspace.vue';
import { getLocationOptions, errorMessage } from '../../api';
import type { User } from '../../types';

type Location = { code: string; name: string };

const props = defineProps<{ permissions: string[]; user: User }>();
const emit = defineEmits<{ close: [] }>();

const provinces = ref<Location[]>([]);
const communes = ref<Location[]>([]);
const villages = ref<Location[]>([]);
const provinceCode = ref(props.user.province_code ?? '');
const communeCode = ref(props.user.commune_code ?? '');
const villageCode = ref('');
const locationError = ref('');
const loadingLocations = ref(false);
const importModalOpen = ref(false);
const importModalInitialized = ref(false);
const importModalView = ref<'upload' | 'history'>('upload');

const householdFilters = computed(() => ({
    province_code: provinceCode.value,
    commune_code: communeCode.value,
    village_code: villageCode.value,
}));

const provinceLocked = computed(() => Boolean(props.user.province_code));
const communeLocked = computed(() => Boolean(props.user.commune_code));
const showProvinceFilter = computed(() => !provinceLocked.value && provinces.value.length > 1);
const showCommuneFilter = computed(() => !communeLocked.value && communes.value.length > 1);
const showVillageFilter = computed(() => villages.value.length > 1);

function openImportModal(view: 'upload' | 'history'): void {
    importModalView.value = view;
    importModalInitialized.value = true;
    importModalOpen.value = true;
}

function closeImportModal(): void {
    importModalOpen.value = false;
}

watch(provinceCode, async (value, previous) => {
    if (!value) {
        communes.value = [];
        villages.value = [];
        communeCode.value = '';
        villageCode.value = '';
        return;
    }

    if (previous && value !== previous) {
        communeCode.value = '';
        villageCode.value = '';
    }

    loadingLocations.value = true;
    try {
        communes.value = await getLocationOptions<Location>(
            `/provinces/${encodeURIComponent(value)}/communes`,
        );
    } catch (cause) {
        locationError.value = errorMessage(cause);
    } finally {
        loadingLocations.value = false;
    }
});

watch(communeCode, async (value, previous) => {
    if (!value) {
        villages.value = [];
        villageCode.value = '';
        return;
    }

    if (previous && value !== previous) villageCode.value = '';

    loadingLocations.value = true;
    try {
        villages.value = await getLocationOptions<Location>(
            `/communes/${encodeURIComponent(value)}/villages`,
        );
    } catch (cause) {
        locationError.value = errorMessage(cause);
    } finally {
        loadingLocations.value = false;
    }
});

onMounted(async () => {
    if (props.permissions.includes('create-imports')) {
        importModalInitialized.value = true;
    }

    loadingLocations.value = true;
    try {
        if (!provinceLocked.value) {
            provinces.value = await getLocationOptions<Location>('/provinces');
        }
        if (provinceCode.value && !communeLocked.value) {
            communes.value = await getLocationOptions<Location>(
                `/provinces/${encodeURIComponent(provinceCode.value)}/communes`,
            );
        }

        if (communeCode.value) {
            villages.value = await getLocationOptions<Location>(
                `/communes/${encodeURIComponent(communeCode.value)}/villages`,
            );
        }
    } catch (cause) {
        locationError.value = errorMessage(cause);
    } finally {
        loadingLocations.value = false;
    }
});

const previousBodyOverflow = document.body.style.overflow;
watch(importModalOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : previousBodyOverflow;
});

onUnmounted(() => {
    document.body.style.overflow = previousBodyOverflow;
});
</script>

<template>
    <div class="investigation-view">
        <p v-if="locationError" class="notice" role="alert">{{ locationError }}</p>
        <Workspace
            module-key="households"
            :permissions="permissions"
            :household-filters="householdFilters"
            @close="emit('close')"
            @navigate="openImportModal($event === 'import-history' ? 'history' : 'upload')"
        >
            <template #toolbar>
                <label v-if="showProvinceFilter" class="household-location-filter">
                    <span>Tỉnh / thành phố</span>
                    <select v-model="provinceCode" :disabled="provinceLocked || loadingLocations">
                        <option value="">Tất cả địa bàn</option>
                        <option v-for="province in provinces" :key="province.code" :value="province.code">{{ province.name }}</option>
                    </select>
                </label>
                <label v-if="showCommuneFilter" class="household-location-filter">
                    <span>Xã / phường</span>
                    <select v-model="communeCode" :disabled="communeLocked || !provinceCode || loadingLocations">
                        <option value="">Tất cả xã / phường</option>
                        <option v-for="commune in communes" :key="commune.code" :value="commune.code">{{ commune.name }}</option>
                    </select>
                </label>
                <label v-if="showVillageFilter" class="household-location-filter">
                    <span>Thôn / xóm</span>
                    <select v-model="villageCode" :disabled="!communeCode || loadingLocations">
                        <option value="">Tất cả thôn / xóm</option>
                        <option v-for="village in villages" :key="village.code" :value="village.code">{{ village.name }}</option>
                    </select>
                </label>
            </template>
        </Workspace>
        <div
            v-if="importModalInitialized"
            v-show="importModalOpen"
            class="workspace-modal-backdrop investigation-import-backdrop"
            role="presentation"
            @keydown.esc="closeImportModal"
        >
            <section
                class="investigation-import-dialog"
                :class="{
                    'investigation-import-upload-dialog': importModalView === 'upload',
                    'investigation-import-dialog-history': importModalView === 'history',
                }"
                role="dialog"
                aria-modal="true"
                aria-labelledby="investigation-import-title"
                tabindex="-1"
            >
                <header class="investigation-import-dialog-header">
                    <div>
                        <p class="workspace-eyebrow">
                            {{
                                importModalView === 'upload'
                                    ? 'PHIẾU ĐIỀU TRA'
                                    : 'LỊCH SỬ NHẬP FILE'
                            }}
                        </p>
                        <h2 id="investigation-import-title">
                            {{
                                importModalView === 'upload'
                                    ? 'Nhập dữ liệu Excel'
                                    : 'Các tệp đã gửi'
                            }}
                        </h2>
                    </div>
                    <button class="modal-icon-button" type="button" aria-label="Đóng" @click="closeImportModal">×</button>
                </header>
                <Workspace
                    module-key="imports"
                    :permissions="permissions"
                    :import-view="importModalView"
                />
            </section>
        </div>
    </div>
</template>
