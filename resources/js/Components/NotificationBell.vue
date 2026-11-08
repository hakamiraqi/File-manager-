<script setup>
// Bell in the top bar with an unread counter (2026-11-05, by request). Opening a
// notification marks it read and goes to what it is about.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { BellIcon, CheckIcon } from '@heroicons/vue/24/outline';
import { BellAlertIcon } from '@heroicons/vue/24/solid';
import { useTrans } from '@/Composables/useTrans';
import { kindLook, timeAgo } from '@/notifications';

const { t } = useTrans();
const page = usePage();
const open = ref(false);
const loading = ref(false);
const items = ref([]);
const unread = ref(page.props.notificationsUnread ?? 0);
watch(() => page.props.notificationsUnread, (v) => (unread.value = v ?? 0));

const badge = computed(() => (unread.value > 99 ? '99+' : unread.value));

async function load() {
    loading.value = true;
    try {
        const res = await fetch(route('notifications.recent'), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (res.ok) {
            const data = await res.json();
            items.value = data.items;
            unread.value = data.unread;
        }
    } catch { /* offline: keep what we have */ }
    loading.value = false;
}

function toggle() {
    open.value = !open.value;
    if (open.value) load();
}

function go(n) {
    open.value = false;
    if (!n.read) unread.value = Math.max(0, unread.value - 1);
    router.visit(route('notifications.open', n.id));
}

function readAll() {
    router.post(route('notifications.read-all'), {}, { preserveScroll: true, preserveState: true, onSuccess: () => { items.value = items.value.map((n) => ({ ...n, read: true })); unread.value = 0; } });
}

// Refresh the counter every minute while the page is visible.
let timer;
async function poll() {
    if (document.hidden || open.value) return;
    try {
        const res = await fetch(route('notifications.recent'), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (res.ok) unread.value = (await res.json()).unread;
    } catch { /* ignore */ }
}
const onKey = (e) => e.key === 'Escape' && (open.value = false);
onMounted(() => { timer = setInterval(poll, 60000); document.addEventListener('keydown', onKey); });
onUnmounted(() => { clearInterval(timer); document.removeEventListener('keydown', onKey); });
</script>

<template>
    <div class="relative" :class="{ 'dropdown-open z-50': open }">
        <button type="button" @click="toggle" class="relative flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" :aria-label="t('notifications.title', 'الإشعارات')" :aria-expanded="open">
            <BellAlertIcon v-if="unread" class="h-6 w-6 text-[var(--accent-600)]" />
            <BellIcon v-else class="h-6 w-6" />
            <span v-if="unread" class="absolute -end-0.5 -top-0.5 min-w-[1.15rem] rounded-full bg-red-500 px-1 text-center text-[10px] font-bold leading-[1.15rem] text-white ring-2 ring-white dark:ring-gray-800">{{ badge }}</span>
        </button>

        <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />
        <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 -translate-y-1" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-x-2 top-16 z-50 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl sm:absolute sm:inset-x-auto sm:end-0 sm:top-11 sm:w-96 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                    <p class="font-semibold text-gray-900 dark:text-gray-100">
                        {{ t('notifications.title', 'الإشعارات') }}
                        <span v-if="unread" class="ms-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600 dark:bg-red-900/30 dark:text-red-300">{{ unread }} {{ t('notifications.new', 'جديد') }}</span>
                    </p>
                    <button v-if="unread" type="button" @click="readAll" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-[var(--accent-600)] hover:bg-gray-100 dark:hover:bg-gray-700">
                        <CheckIcon class="h-4 w-4" /> {{ t('notifications.read_all', 'تحديد الكل كمقروء') }}
                    </button>
                </div>

                <div class="max-h-[60vh] overflow-y-auto">
                    <div v-if="loading && !items.length" class="space-y-3 p-4">
                        <div v-for="i in 3" :key="i" class="flex animate-pulse gap-3"><div class="h-10 w-10 rounded-xl bg-gray-100 dark:bg-gray-700" /><div class="flex-1 space-y-2"><div class="h-3 w-2/3 rounded bg-gray-100 dark:bg-gray-700" /><div class="h-3 w-1/2 rounded bg-gray-100 dark:bg-gray-700" /></div></div>
                    </div>
                    <div v-else-if="!items.length" class="flex flex-col items-center gap-2 px-4 py-10 text-sm text-gray-400">
                        <BellIcon class="h-8 w-8" /> {{ t('notifications.empty', 'لا توجد إشعارات بعد') }}
                    </div>
                    <button v-for="n in items" :key="n.id" type="button" @click="go(n)"
                        class="flex w-full gap-3 border-b border-gray-50 px-4 py-3 text-start transition last:border-0 hover:bg-gray-50 dark:border-gray-700/50 dark:hover:bg-gray-700/40"
                        :class="{ 'bg-indigo-50/50 dark:bg-indigo-900/10': !n.read }">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="kindLook(n.kind).tone">
                            <component :is="kindLook(n.kind).icon" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-gray-900 dark:text-gray-100" :class="n.read ? 'font-normal' : 'font-semibold'">{{ n.title }}</span>
                            <span v-if="n.body" class="mt-0.5 line-clamp-2 block text-xs text-gray-500 dark:text-gray-400">{{ n.body }}</span>
                            <span class="mt-1 block text-[11px] text-gray-400">{{ timeAgo(n.created_at) }}</span>
                        </span>
                        <span v-if="!n.read" class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-[var(--accent-600)]" />
                    </button>
                </div>

                <Link :href="route('notifications.index')" @click="open = false" class="block border-t border-gray-100 px-4 py-3 text-center text-sm font-medium text-[var(--accent-600)] hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/40">
                    {{ t('notifications.view_all', 'عرض كل الإشعارات') }}
                </Link>
            </div>
        </Transition>
    </div>
</template>
