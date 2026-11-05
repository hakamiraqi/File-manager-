<script setup>
// Library tabs (redesigned 2026-11-05, by request): each tab has an icon, the open tab is a
// solid accent pill and is scrolled into view on phones, and staff tabs sit in their own group.
import { computed, nextTick, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';
import {
    BookOpenIcon, BookmarkIcon, ArrowsRightLeftIcon, ClipboardDocumentListIcon, UserGroupIcon,
    RectangleStackIcon, ChartBarIcon, SignalIcon, FolderIcon, ScaleIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const page = usePage();
const canManage = computed(() => page.props.permissions?.manage_library);
const unread = computed(() => page.props.permissions?.library_unread ?? 0);

const make = (list) => list.map((tab) => ({ ...tab, href: tab.href ?? route(tab.name), active: tab.match.some((n) => route().current(n)) }));

const readerTabs = computed(() => make([
    { label: t('library.tab.catalog', 'الفهرس'), href: route('library.index'), icon: BookOpenIcon, match: ['library.index', 'library.books.show'] },
    { label: t('library.tab.my_loans', 'استعاراتي'), name: 'library.my-loans', icon: BookmarkIcon, match: ['library.my-loans'], badge: unread.value },
]));

const staffTabs = computed(() => (canManage.value ? make([
    { label: t('library.tab.counter', 'الإعارة والاسترجاع'), name: 'library.manage.counter', icon: ArrowsRightLeftIcon, match: ['library.manage.counter'] },
    { label: t('library.tab.loans', 'الاستعارات'), name: 'library.manage.loans', icon: ClipboardDocumentListIcon, match: ['library.manage.loans'] },
    { label: t('library.tab.members', 'القرّاء'), name: 'library.manage.members', icon: UserGroupIcon, match: ['library.manage.members'] },
    { label: t('library.tab.books', 'إدارة الكتب'), name: 'library.manage.books', icon: RectangleStackIcon, match: ['library.manage.books'] },
    { label: t('library.tab.reports', 'التقارير'), name: 'library.manage.reports', icon: ChartBarIcon, match: ['library.manage.reports'] },
    { label: t('library.tab.rfid', 'RFID والجرد'), name: 'library.manage.rfid', icon: SignalIcon, match: ['library.manage.rfid'] },
    { label: t('library.tab.sections', 'الأقسام'), name: 'library.manage.sections', icon: FolderIcon, match: ['library.manage.sections'] },
    { label: t('library.tab.settings', 'قواعد الإعارة'), name: 'library.manage.settings', icon: ScaleIcon, match: ['library.manage.settings'] },
]) : []));

const strip = ref(null);
onMounted(() => nextTick(() => strip.value?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'center' })));
</script>

<template>
    <nav ref="strip" class="-mx-4 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0 [&::-webkit-scrollbar]:hidden" :aria-label="t('library.tabs', 'أقسام المكتبة')">
        <div class="flex w-max items-center gap-2 rounded-2xl border border-gray-200 bg-white p-1.5 shadow-sm sm:w-full sm:flex-wrap dark:border-gray-700 dark:bg-gray-800">
            <template v-for="(group, gi) in [readerTabs, staffTabs]" :key="gi">
                <span v-if="gi === 1 && group.length" class="mx-0.5 h-7 w-px shrink-0 bg-gray-200 dark:bg-gray-700" aria-hidden="true" />
                <Link
                    v-for="tab in group"
                    :key="tab.href"
                    :href="tab.href"
                    :aria-current="tab.active ? 'page' : undefined"
                    class="relative inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl px-3.5 py-2 text-sm font-medium transition"
                    :class="tab.active
                        ? 'bg-[var(--accent-600)] text-white shadow-sm'
                        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white'"
                >
                    <component :is="tab.icon" class="h-5 w-5 shrink-0" :class="tab.active ? 'text-white' : 'text-gray-400 dark:text-gray-500'" />
                    {{ tab.label }}
                    <span v-if="tab.badge" class="min-w-[1.25rem] rounded-full px-1.5 text-center text-[11px] font-bold leading-5" :class="tab.active ? 'bg-white text-[var(--accent-600)]' : 'bg-red-500 text-white'">{{ tab.badge }}</span>
                </Link>
            </template>
        </div>
    </nav>
</template>
