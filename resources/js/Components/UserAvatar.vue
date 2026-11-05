<script setup>
import { computed } from 'vue';

// Profile picture, or the first letter of the name on the accent colour.
const props = defineProps({
    user: { type: Object, default: null },
    size: { type: String, default: 'h-7 w-7 text-xs' },
});

const initial = computed(() => (props.user?.name || '?').trim().charAt(0));
</script>

<template>
    <img
        v-if="user?.avatar_url"
        :src="user.avatar_url"
        :alt="user.name"
        :class="[size, 'shrink-0 rounded-full object-cover']"
    />
    <span
        v-else
        :class="[size, 'flex shrink-0 items-center justify-center rounded-full bg-[var(--accent-600)] font-semibold text-white']"
    >{{ initial }}</span>
</template>
