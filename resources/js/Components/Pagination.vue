<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ links: Array });
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap items-center gap-1">
        <template v-for="(link, i) in links" :key="i">
            <Link
                v-if="link.url"
                :href="link.url"
                v-html="link.label"
                preserve-scroll
                class="rounded-md px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-[var(--accent-600)] text-white' : 'text-gray-600 hover:bg-gray-100'"
            />
            <span v-else v-html="link.label" class="rounded-md px-3 py-1.5 text-sm text-gray-300" />
        </template>
    </nav>
</template>
