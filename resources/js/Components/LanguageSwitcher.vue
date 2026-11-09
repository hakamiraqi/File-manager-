<script setup>
// «اللغة» (2026-11-09, by request): switch the language from the top bar or the sign-in page;
// remembered on the account, or in the session before signing in.
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { LanguageIcon, CheckIcon } from '@heroicons/vue/24/outline';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const page = usePage();
const locales = computed(() => Object.entries(page.props.appSettings?.locales ?? {}).map(([code, l]) => ({ code, name: l.label ?? code })));
const current = computed(() => page.props.appSettings?.locale);
const open = ref(false);
function choose(code) {
    open.value = false;
    if (code === current.value) return;
    router.post(route('locale.switch', code), {}, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <div v-if="locales.length > 1" class="relative">
        <button type="button" @click="open = !open" class="flex items-center gap-1 rounded-lg p-2 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" :aria-label="t('nav.language', 'اللغة')" :title="t('nav.language', 'اللغة')">
            <LanguageIcon class="h-5 w-5" /><span class="hidden text-xs font-semibold uppercase sm:inline">{{ current }}</span>
        </button>
        <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />
        <div v-if="open" class="absolute end-0 z-50 mt-1 w-44 overflow-hidden rounded-xl bg-white py-1 text-sm shadow-lg ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
            <button v-for="l in locales" :key="l.code" type="button" @click="choose(l.code)" class="flex w-full items-center gap-2 px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-700">
                <span class="flex-1">{{ l.name }}</span><CheckIcon v-if="l.code === current" class="h-4 w-4 text-[var(--accent-600)]" />
            </button>
        </div>
    </div>
</template>
