<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestShell from '@/Components/GuestShell.vue';
import Pagination from '@/Components/Pagination.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import BookCover from '@/Components/Library/BookCover.vue';
import { useTrans } from '@/Composables/useTrans';
import { MagnifyingGlassIcon, FolderIcon, HomeIcon, Squares2X2Icon, ListBulletIcon, ChevronLeftIcon, CheckBadgeIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ books: Object, sections: Array, currentSection: Object, childSections: Array, filters: Object });

const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
const Layout = computed(() => (signedIn.value ? AppLayout : GuestShell));

const q = ref(props.filters.q ?? '');
const view = ref((() => { try { return localStorage.getItem('library.view') || 'grid'; } catch { return 'grid'; } })());
function setView(v) { view.value = v; try { localStorage.setItem('library.view', v); } catch {} }

function go(params) {
    router.get(route('library.index'), { q: q.value || undefined, section: props.filters.section || undefined, available: props.filters.available ? 1 : undefined, ...params }, { preserveState: true, preserveScroll: true });
}

// "الدراسات الأولية / جراحة الفم" → clickable parts.
const trail = computed(() => {
    if (!props.currentSection) return [];
    const byId = Object.fromEntries(props.sections.map((s) => [s.id, s]));
    const out = [];
    for (let s = byId[props.currentSection.id], i = 0; s && i < 20; s = byId[s.parent_id], i++) out.unshift(s);
    return out;
});

function availability(book) {
    if (book.reference_only) return { text: t('library.reference_only', 'للقراءة داخل المكتبة'), cls: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' };
    if (!book.copies_count) return { text: t('library.not_in_library', 'غير موجود حالياً'), cls: 'bg-gray-100 text-gray-500 dark:bg-gray-700' };
    return book.available_count > 0
        ? { text: t('library.available_n', 'متاح :n من :m', { n: book.available_count, m: book.copies_count }), cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' }
        : { text: t('library.all_loaned', 'كل النسخ مُعارة'), cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' };
}
</script>

<template>
    <Head :title="t('nav.library', 'المكتبة')" />

    <component :is="Layout">
        <template #header>
            <div class="flex items-center gap-3">
                <h2 class="hidden text-lg font-semibold text-gray-800 sm:block dark:text-gray-100">{{ t('nav.library', 'المكتبة') }}</h2>
                <div class="relative ms-auto w-full max-w-xs">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-2.5 top-2.5 h-4 w-4 text-gray-400" />
                    <input
                        v-model="q"
                        type="search"
                        @keyup.enter="go({ q: q || undefined, page: undefined })"
                        :placeholder="t('library.search', 'ابحث بالعنوان أو المؤلف أو ISBN أو الباركود...')"
                        class="w-full rounded-full border-gray-300 py-1.5 ps-8 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    />
                </div>
            </div>
        </template>

        <div class="space-y-5 p-4 sm:p-6">
            <LibraryTabs v-if="signedIn" />

            <!-- Path: المكتبة / قسم / قسم فرعي -->
            <nav class="flex flex-wrap items-center gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                <button @click="go({ section: undefined, page: undefined })" class="flex items-center gap-1 rounded px-1" :class="trail.length ? 'text-[var(--accent-600)] hover:underline' : 'font-semibold'">
                    <HomeIcon class="h-4 w-4" /> {{ t('library.all_sections', 'كل الأقسام') }}
                </button>
                <template v-for="(s, i) in trail" :key="s.id">
                    <ChevronLeftIcon class="h-3.5 w-3.5 text-gray-400 ltr:rotate-180" />
                    <button @click="go({ section: s.id, page: undefined })" class="rounded px-1" :class="i < trail.length - 1 ? 'text-[var(--accent-600)] hover:underline' : 'font-semibold'">{{ s.name }}</button>
                </template>
            </nav>

            <p v-if="currentSection?.description" class="text-sm text-gray-600 dark:text-gray-300">{{ currentSection.description }}</p>

            <!-- Sub-sections -->
            <div v-if="childSections.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <button
                    v-for="s in childSections"
                    :key="s.id"
                    @click="go({ section: s.id, page: undefined })"
                    class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 text-start hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
                >
                    <img v-if="s.cover_url" :src="s.cover_url" class="h-10 w-10 shrink-0 rounded-md object-cover" alt="" />
                    <FolderIcon v-else class="h-10 w-10 shrink-0 text-amber-400" />
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ s.name }}</span>
                        <span class="block text-xs text-gray-400">{{ t('library.n_books', ':n كتاب', { n: s.books_count }) }}<template v-if="s.location"> · {{ s.location }}</template></span>
                    </span>
                </button>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-gray-500">{{ t('library.n_results', ':n كتاب', { n: books.total }) }}</p>
                    <button
                        type="button"
                        @click="go({ available: filters.available ? undefined : 1, page: undefined })"
                        class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition"
                        :class="filters.available ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'"
                    >
                        <CheckBadgeIcon class="h-4 w-4" /> {{ t('library.only_available', 'المتوفر الآن فقط') }}
                    </button>
                </div>
                <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                    <button @click="setView('grid')" class="p-2" :class="view === 'grid' ? 'bg-[var(--accent-600)] text-white' : 'text-gray-500'"><Squares2X2Icon class="h-5 w-5" /></button>
                    <button @click="setView('list')" class="p-2" :class="view === 'list' ? 'bg-[var(--accent-600)] text-white' : 'text-gray-500'"><ListBulletIcon class="h-5 w-5" /></button>
                </div>
            </div>

            <div v-if="!books.data.length" class="py-16 text-center text-gray-400">{{ t('library.empty', 'لا توجد كتب هنا بعد.') }}</div>

            <div v-else-if="view === 'grid'" class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                <Link v-for="book in books.data" :key="book.id" :href="route('library.books.show', book.id)" class="group">
                    <BookCover :book="book" class="transition group-hover:shadow-lg" />
                    <p class="mt-2 line-clamp-2 text-sm font-medium text-gray-800 dark:text-gray-100" dir="auto">{{ book.title }}</p>
                    <p v-if="book.authors" class="truncate text-xs text-gray-500" dir="auto">{{ book.authors }}</p>
                    <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium" :class="availability(book).cls">{{ availability(book).text }}</span>
                </Link>
            </div>

            <div v-else class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                <Link v-for="book in books.data" :key="book.id" :href="route('library.books.show', book.id)" class="flex items-center gap-3 px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <BookCover :book="book" size="sm" class="w-10 shrink-0" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100" dir="auto">{{ book.title }}</p>
                        <p class="truncate text-xs text-gray-500" dir="auto">{{ [book.authors, book.year, book.section?.name].filter(Boolean).join(' · ') }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="availability(book).cls">{{ availability(book).text }}</span>
                </Link>
            </div>

            <Pagination :links="books.links" />
        </div>
    </component>
</template>
