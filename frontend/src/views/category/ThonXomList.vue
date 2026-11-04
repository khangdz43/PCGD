<script setup lang="ts">
import { computed, ref } from 'vue';
import { apiRows, errorMessage, getLocationOptions } from '../../api';
import Workspace from '../../Workspace.vue';

defineProps<{ permissions: string[] }>();
const emit = defineEmits<{
    close: [];
    navigate: [module: 'import-file' | 'import-history'];
}>();

type Location = { code: string; name: string };
type VillageResponse = {
    provinces?: unknown;
    province_code?: string;
    commune_code?: string;
};

const provinces = ref<Location[]>([]);
const communes = ref<Location[]>([]);
const provinceCode = ref('');
const communeCode = ref('');
const appliedProvinceCode = ref('');
const appliedCommuneCode = ref('');
const searchQuery = ref('');
const appliedQuery = ref('');
const error = ref('');
const busy = ref(false);
const communeProvinceCode = ref('');
const communeLocked = ref(false);
const showProvinceFilter = computed(() => provinces.value.length > 1);
const showCommuneFilter = computed(() => !communeLocked.value && communes.value.length > 1);
const villageFilters = computed(() => ({
    province_code: appliedProvinceCode.value,
    commune_code: appliedCommuneCode.value,
    q: appliedQuery.value,
}));

async function loadCommunes(code: string): Promise<void> {
    communes.value = code
        ? await getLocationOptions<Location>(
              `/provinces/${encodeURIComponent(code)}/communes`,
          )
        : [];
}

async function changeProvince(): Promise<void> {
    communeCode.value = '';
    applyFilters();
    error.value = '';
    communes.value = [];
    communeProvinceCode.value = provinceCode.value;
    try {
        await loadCommunes(provinceCode.value);
    } catch (cause) {
        communeProvinceCode.value = '';
        error.value = errorMessage(cause);
    }
}

function applyFilters(): void {
    appliedProvinceCode.value = provinceCode.value;
    appliedCommuneCode.value = communeCode.value;
    appliedQuery.value = searchQuery.value.trim();
}

async function receiveVillageOptions(data: VillageResponse): Promise<void> {
    provinces.value = apiRows<Location>(data.provinces);
    if (!provinceCode.value) {
        provinceCode.value = data.province_code || (provinces.value.length === 1 ? provinces.value[0].code : '');
    }
    communeLocked.value = Boolean(data.commune_code);
    if (communeLocked.value) communeCode.value = data.commune_code ?? '';
    appliedProvinceCode.value = provinceCode.value;
    appliedCommuneCode.value = communeCode.value;
    if (!communeLocked.value && provinceCode.value && communeProvinceCode.value !== provinceCode.value) {
        try {
            await loadCommunes(provinceCode.value);
            communeProvinceCode.value = provinceCode.value;
        } catch (cause) {
            error.value = errorMessage(cause);
        }
    } else if (!provinceCode.value && communeProvinceCode.value) {
        communes.value = [];
        communeProvinceCode.value = '';
    }
}

</script>

<template>
    <div class="village-list">
        <p v-if="error" class="notice" role="alert">{{ error }}</p>
        <p v-if="busy" class="muted">Đang tải địa bàn…</p>
        <Workspace
            v-if="!busy && !error"
            module-key="villages"
            :permissions="permissions"
            :village-filters="villageFilters"
            @close="emit('close')"
            @navigate="emit('navigate', $event)"
            @village-options="receiveVillageOptions"
        >
            <template #toolbar>
                <form class="village-unified-filter" role="search" @submit.prevent="applyFilters">
                    <label v-if="showProvinceFilter">
                        <span>Tỉnh / thành phố</span>
                        <select v-model="provinceCode" @change="changeProvince">
                            <option value="">Tất cả địa bàn</option>
                            <option v-for="province in provinces" :key="province.code" :value="province.code">{{ province.name }}</option>
                        </select>
                    </label>
                    <label v-if="showCommuneFilter">
                        <span>Xã / phường</span>
                        <select v-model="communeCode" :disabled="!provinceCode && provinces.length > 0 || busy" @change="applyFilters">
                            <option value="">Tất cả xã / phường</option>
                            <option v-for="commune in communes" :key="commune.code" :value="commune.code">{{ commune.name }}</option>
                        </select>
                    </label>
                    <label class="village-unified-search">
                        <span>Tìm theo mã hoặc tên</span>
                        <span class="village-search-control">
                            <input v-model="searchQuery" type="search" placeholder="Nhập mã hoặc tên thôn / xóm">
                            <button class="secondary-button management-tool-button user-search-button" type="submit" :disabled="busy" aria-label="Tìm thôn / xóm" title="Tìm thôn / xóm">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
                            </button>
                        </span>
                    </label>
                </form>
            </template>
        </Workspace>
    </div>
</template>
