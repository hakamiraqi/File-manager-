<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CheckCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const page = usePage();
const visible = ref(false);
const message = computed(() => page.props.flash?.success);

watch(message, (value) => {
    if (value) {
        visible.value = true;
        setTimeout(() => (visible.value = false), 4000);
    }
});
</script>

<template>
    <Transition
        enter-active-class="transition ease-out duration-200"
        enter-from-class="opacity-0 -translate-y-2"
        leave-active-class="transition ease-in duration-150"
        leave-to-class="opacity-0"
    >
        <div v-if="visible && message" class="flex items-center gap-2 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            <CheckCircleIcon class="h-5 w-5 shrink-0" />
            <span class="flex-1">{{ message }}</span>
            <button @click="visible = false">
                <XMarkIcon class="h-4 w-4" />
            </button>
        </div>
    </Transition>
</template>
