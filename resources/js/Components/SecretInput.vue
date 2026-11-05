<script setup>
// Password-style input with a show/hide eye button (for keys and secrets).
import { ref } from 'vue';
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline';

const model = defineModel({ type: [String, null], default: '' });
defineProps({ placeholder: { type: String, default: '' } });
const shown = ref(false);
</script>

<template>
    <div class="relative">
        <input
            v-model="model"
            :type="shown ? 'text' : 'password'"
            :placeholder="placeholder"
            dir="ltr"
            autocomplete="off"
            class="block w-full rounded-xl border-gray-300 pl-3 pr-10 text-sm shadow-sm focus:border-[var(--accent-600)] focus:ring-[var(--accent-600)] dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
        />
        <button type="button" class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" :aria-label="shown ? 'إخفاء' : 'إظهار'" @click="shown = !shown">
            <EyeSlashIcon v-if="shown" class="h-4 w-4" />
            <EyeIcon v-else class="h-4 w-4" />
        </button>
    </div>
</template>
