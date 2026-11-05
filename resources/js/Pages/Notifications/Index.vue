<script setup>
// All notifications, grouped by day, with filters (2026-11-05, by request).
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useTrans } from '@/Composables/useTrans';
import { kindLook, timeAgo } from '@/notifications';
import { BellIcon, CheckIcon, TrashIcon, ChevronLeftIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ items: Object, filter: String, counts: Object });

const FILTERS = computed(() => [
    ['all', t('notifications.all', 'الكل')],
    ['unread', t('notifications.unread', 'غير مقروءة')],
    ['library', t('notifications.cat_library', 'المكتبة')],
    ['files', t('notifications.cat_files', 'الملفات')],
    ['admin', t('notifications.cat_admin', 'الإدارة')],
    ['account', t('notifications.cat_account', 'الحساب')],
].filter(([k]) => k === 'all' || k === 'unread' || props.counts[k] > 0));

const dayKey = (iso) => new Date(iso).toLocaleDateString('en-CA', { timeZone: 'Asia/Baghdad' });
const today = dayKey(new Date().toISOString());
const yesterday = dayKey(new Date(Date.now() - 86400000).toISOString());
const dayLabel = (k) => (k === today ? t('time.today', 'اليوم') : k === yesterday ? t('time.yesterday', 'أمس')
    : new Date(`${k}T12:00:00`).toLocaleDateString('ar-IQ', { weekday: 'long', day: 'numeric', month: 'long' }));

const groups = computed(() => {
    const out = [];
    for (const n of props.items.data) {
        const k = dayKey(n.created_at);
        if (!out.length || out.at(-1).key !== k) out.push({ key: k, label: dayLabel(k), items: [] });
        out.at(-1).items.push(n);
    }
    return out;
});

const pick = (f) => router.get(route('notifications.index'), f === 'all' ? {} : { filter: f }, { preserveScroll: true });
const open = (n) => router.visit(route('notifications.open', n.id));
const markRead = (n) => router.post(route('notifications.read', n.id), {}, { preserveScroll: true });
const remove = (n) => router.delete(route('notifications.destroy', n.id), { preserveScroll: true });
const readAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });
</script>

<template>
    <Head :title="t('notifications.title', 'الإشعارات')" />

    <AppLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-2">
                <h2 class="flex min-w-0 items-center gap-2 text-base font-semibold text-gray-800 sm:text-lg dark:text-gray-100">
                    <BellIcon class="h-5 w-5 shrink-0 text-gray-500" />
                    <span class="truncate">{{ t('notifications.title', 'الإشعارات') }}</span>
                </h2>
                <button v-if="counts.unread" type="button" @click="readAll" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    <CheckIcon class="h-4 w-4" /> <span class="hidden sm:inline">{{ t('notifications.read_all', 'تحديد الكل كمقروء') }}</span>
                </button>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
                <button v-for="[key, label] in FILTERS" :key="key" type="button" @click="pick(key)"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm transition"
                    :class="filter === key ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white shadow-sm' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200'">
                    {{ label }}
                    <span class="rounded-full px-1.5 text-xs" :class="filter === key ? 'bg-white/20' : key === 'unread' && counts.unread ? 'bg-red-500 text-white' : 'bg-gray-100 dark:bg-gray-700'">{{ counts[key] }}</span>
                </button>
            </div>

            <div v-if="!items.data.length" class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-gray-300 py-16 text-gray-400 dark:border-gray-700">
                <BellIcon class="h-10 w-10" />
                <p class="text-sm">{{ filter === 'unread' ? t('notifications.all_read', 'قرأت كل الإشعارات') : t('notifications.empty', 'لا توجد إشعارات بعد') }}</p>
            </div>

            <section v-for="g in groups" :key="g.key">
                <h3 class="mb-2 px-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ g.label }}</h3>
                <ul class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <li v-for="n in g.items" :key="n.id" class="group relative flex gap-3 border-b border-gray-100 px-4 py-3.5 last:border-0 dark:border-gray-700/60" :class="{ 'bg-indigo-50/40 dark:bg-indigo-900/10': !n.read }">
                        <span v-if="!n.read" class="absolute inset-y-3 start-0 w-1 rounded-e-full bg-[var(--accent-600)]" />
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" :class="kindLook(n.kind).tone">
                            <component :is="kindLook(n.kind).icon" class="h-5 w-5" />
                        </span>
                        <button type="button" class="min-w-0 flex-1 text-start" @click="open(n)">
                            <span class="block text-sm text-gray-900 dark:text-gray-100" :class="n.read ? 'font-normal' : 'font-semibold'">{{ n.title }}</span>
                            <span v-if="n.body" class="mt-0.5 block text-sm text-gray-500 dark:text-gray-400">{{ n.body }}</span>
                            <span class="mt-1 flex items-center gap-1 text-xs text-gray-400">
                                {{ timeAgo(n.created_at) }}
                                <template v-if="n.has_url"> · {{ t('notifications.open', 'فتح') }} <ChevronLeftIcon class="h-3 w-3 ltr:rotate-180" /></template>
                            </span>
                        </button>
                        <div class="flex shrink-0 flex-col gap-1 sm:opacity-0 sm:transition sm:group-hover:opacity-100">
                            <button v-if="!n.read" type="button" @click="markRead(n)" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" :title="t('notifications.mark_read', 'تحديد كمقروء')"><CheckIcon class="h-4 w-4" /></button>
                            <button type="button" @click="remove(n)" class="rounded-lg p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" :title="t('action.delete', 'حذف')"><TrashIcon class="h-4 w-4" /></button>
                        </div>
                    </li>
                </ul>
            </section>

            <Pagination v-if="items.last_page > 1" :links="items.links" />
        </div>
    </AppLayout>
</template>
