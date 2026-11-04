<script setup lang="ts">
import Workspace from '../../Workspace.vue';

defineProps<{ permissions: string[] }>();
const emit = defineEmits<{ close: []; navigate: [module: 'import-file' | 'import-history'] }>();
</script>

<template>
    <Workspace module-key="schools" :permissions="permissions" @close="emit('close')" @navigate="emit('navigate', $event)" />
</template>
