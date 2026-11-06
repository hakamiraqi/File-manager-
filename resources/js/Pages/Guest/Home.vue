<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GuestShell from '@/Components/GuestShell.vue';
import BookCover from '@/Components/Library/BookCover.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    MagnifyingGlassIcon, BookOpenIcon, AcademicCapIcon, FolderIcon, ChatBubbleLeftRightIcon,
    CheckBadgeIcon, ArrowLeftIcon, PaperAirplaneIcon,
} from '@heroicons/vue/24/outline';

// The visitors' home: search the library and theses, see what is new, and reach «تواصل معنا».
const { t } = useTrans();
const page = usePage();
const props = defineProps({ stats: Object, latestBooks: Array, latestTheses: Array, publicFolders: Array, degreeLabels: Object });
const site = computed(() => page.props.appSettings?.publicSite ?? {});

const scopes = computed(() => [
    site.value.library && { key: 'library', label: t('nav.library', 'المكتبة'), icon: BookOpenIcon, placeholder: t('guest.search_books', 'ابحث عن كتاب بالعنوان أو المؤلف أو ISBN...') },
    site.value.theses && { key: 'theses', label: t('nav.theses', 'الرسائل والأطاريح'), icon: AcademicCapIcon, placeholder: t('guest.search_theses', 'ابحث عن رسالة أو أطروحة بالعنوان أو الباحث أو الموضوع...') },
].filter(Boolean));
const scope = ref(scopes.value[0]?.key);
const q = ref('');
const current = computed(() => scopes.value.find((s) => s.key === scope.value));

function search() {
    if (!scope.value) return;
    router.get(route(scope.value === 'library' ? 'library.index' : 'theses.index'), q.value.trim() ? { q: q.value.trim() } : {});
}

const tiles = computed(() => [
    props.stats.books !== null && { label: t('guest.stat_books', 'كتاب في المكتبة'), value: props.stats.books, icon: BookOpenIcon, tone: 'bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-300' },
    props.stats.copies_available !== null && { label: t('guest.stat_available', 'نسخة متوفرة الآن'), value: props.stats.copies_available, icon: CheckBadgeIcon, tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300' },
    props.stats.theses !== null && { label: t('guest.stat_theses', 'رسالة وأطروحة'), value: props.stats.theses, icon: AcademicCapIcon, tone: 'bg-violet-50 text-violet-600 dark:bg-violet-900/30 dark:text-violet-300' },
    props.stats.folders !== null && { label: t('guest.stat_folders', 'مجلد عام'), value: props.stats.folders, icon: FolderIcon, tone: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300' },
].filter(Boolean));

const cards = computed(() => [
    site.value.library && { title: t('nav.library', 'المكتبة'), text: t('guest.library_text', 'تصفح الكتب حسب الأقسام، وابحث عن أي كتاب واعرف هل هو متوفر الآن أو مُعار.'), href: route('library.index'), icon: BookOpenIcon, tone: 'from-sky-500 to-blue-600' },
    site.value.theses && { title: t('nav.theses', 'الرسائل والأطاريح'), text: t('guest.theses_text', 'ابحث في رسائل الماجستير وأطاريح الدكتوراه حسب السنة والقسم والموضوع.'), href: route('theses.index'), icon: AcademicCapIcon, tone: 'from-violet-500 to-purple-600' },
    site.value.files && { title: t('guest.files', 'الملفات العامة'), text: t('guest.files_text', 'الملفات والمجلدات المتاحة للجميع بدون تسجيل دخول.'), href: route('guest.files'), icon: FolderIcon, tone: 'from-amber-400 to-orange-500' },
    site.value.contact && { title: t('nav.contact', 'تواصل معنا'), text: t('guest.contact_text', 'أرسل استفساراً أو اطلب كتاباً أو رسالة، وسنرد عليك.'), href: route('contact.create'), icon: ChatBubbleLeftRightIcon, tone: 'from-emerald-500 to-teal-600' },
].filter(Boolean));

function availability(book) {
    if (book.reference_only) return { text: t('library.reference_only', 'للقراءة داخل المكتبة'), cls: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' };
    if (!book.copies_count) return { text: t('library.not_in_library', 'غير موجود حالياً'), cls: 'bg-gray-100 text-gray-500 dark:bg-gray-700' };
    return book.available_count > 0
        ? { text: t('library.available', 'متوفر'), cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' }
        : { text: t('library.all_loaned', 'كل النسخ مُعارة'), cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' };
}
</script>

<template>
    <Head :title="t('guest.home', 'الرئيسية')" />

    <GuestShell>
        <!-- Hero + search -->
        <section class="relative overflow-hidden px-4 pb-10 pt-10 sm:px-6 sm:pt-14">
            <div class="pointer-events-none absolute inset-0 -z-0 bg-gradient-to-b from-[var(--accent-50)] to-transparent dark:from-gray-800/60"></div>
            <div class="relative mx-auto max-w-3xl text-center">
                <h1 class="text-2xl font-extrabold leading-tight text-gray-900 sm:text-4xl dark:text-white">{{ $page.props.appSettings.siteName }}</h1>
                <p class="mt-3 text-sm text-gray-600 sm:text-base dark:text-gray-300">
                    {{ t('guest.hero_text', 'ابحث في المكتبة والرسائل والأطاريح، وتصفح المحتوى المتاح بدون تسجيل دخول.') }}
                </p>

                <form v-if="scopes.length" @submit.prevent="search" class="mt-7 rounded-2xl border border-gray-200 bg-white p-2 text-start shadow-lg shadow-gray-200/60 dark:border-gray-700 dark:bg-gray-800 dark:shadow-none">
                    <div v-if="scopes.length > 1" class="mb-2 flex gap-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-900/60">
                        <button
                            v-for="s in scopes"
                            :key="s.key"
                            type="button"
                            @click="scope = s.key"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                            :class="scope === s.key ? 'bg-white text-[var(--accent-700)] shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                        >
                            <component :is="s.icon" class="h-4 w-4" /> {{ s.label }}
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                            <input v-model="q" type="search" :placeholder="current?.placeholder" class="w-full rounded-xl border-0 bg-transparent py-3 ps-10 text-sm focus:ring-0 sm:text-base dark:text-gray-100" />
                        </div>
                        <button class="shrink-0 rounded-xl bg-[var(--accent-600)] px-5 py-3 text-sm font-semibold text-white hover:bg-[var(--accent-700)]">{{ t('action.search', 'بحث') }}</button>
                    </div>
                </form>
            </div>

            <div v-if="tiles.length" class="relative mx-auto mt-8 grid max-w-4xl grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="tile in tiles" :key="tile.label" class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="tile.tone"><component :is="tile.icon" class="h-5 w-5" /></span>
                    <span class="min-w-0">
                        <span class="block text-xl font-bold tabular-nums">{{ tile.value.toLocaleString('en') }}</span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ tile.label }}</span>
                    </span>
                </div>
            </div>
        </section>

        <div class="space-y-10 px-4 pb-12 sm:px-6">
            <!-- Sections -->
            <section v-if="cards.length" class="grid gap-4 sm:grid-cols-2" :class="cards.length >= 4 ? 'lg:grid-cols-4' : cards.length === 3 ? 'lg:grid-cols-3' : ''">
                <Link v-for="c in cards" :key="c.href" :href="c.href" class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-5 transition hover:-translate-y-0.5 hover:shadow-lg dark:border-gray-700 dark:bg-gray-800">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br text-white shadow-md" :class="c.tone"><component :is="c.icon" class="h-6 w-6" /></span>
                    <h2 class="mt-4 font-bold text-gray-900 dark:text-white">{{ c.title }}</h2>
                    <p class="mt-1 flex-1 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ c.text }}</p>
                    <span class="mt-3 flex items-center gap-1 text-sm font-medium text-[var(--accent-600)]">
                        {{ t('guest.open', 'افتح') }} <ArrowLeftIcon class="h-4 w-4 transition group-hover:-translate-x-1 ltr:rotate-180 ltr:group-hover:translate-x-1" />
                    </span>
                </Link>
            </section>
            <p v-else class="rounded-2xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-gray-600">{{ t('guest.nothing_public', 'لا يوجد محتوى عام حالياً.') }}</p>

            <!-- Latest books -->
            <section v-if="latestBooks.length">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-lg font-bold"><BookOpenIcon class="h-5 w-5 text-sky-500" /> {{ t('guest.latest_books', 'أحدث الكتب') }}</h2>
                    <Link :href="route('library.index')" class="text-sm font-medium text-[var(--accent-600)] hover:underline">{{ t('guest.see_all', 'عرض الكل') }}</Link>
                </div>
                <div class="-mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-3 sm:mx-0 sm:grid sm:grid-cols-4 sm:overflow-visible sm:px-0 lg:grid-cols-6">
                    <Link v-for="book in latestBooks" :key="book.id" :href="route('library.books.show', book.id)" class="group w-32 shrink-0 snap-start sm:w-auto">
                        <BookCover :book="book" class="transition group-hover:shadow-lg" />
                        <p class="mt-2 line-clamp-2 text-sm font-medium text-gray-800 dark:text-gray-100" dir="auto">{{ book.title }}</p>
                        <p v-if="book.authors" class="truncate text-xs text-gray-500" dir="auto">{{ book.authors }}</p>
                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium" :class="availability(book).cls">{{ availability(book).text }}</span>
                    </Link>
                </div>
            </section>

            <!-- Latest theses -->
            <section v-if="latestTheses.length">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-lg font-bold"><AcademicCapIcon class="h-5 w-5 text-violet-500" /> {{ t('guest.latest_theses', 'أحدث الرسائل والأطاريح') }}</h2>
                    <Link :href="route('theses.index')" class="text-sm font-medium text-[var(--accent-600)] hover:underline">{{ t('guest.see_all', 'عرض الكل') }}</Link>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <Link v-for="th in latestTheses" :key="th.id" :href="route('guest.show', th.id)" class="flex gap-3 rounded-2xl border border-gray-200 bg-white p-4 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-900/30 dark:text-violet-300"><AcademicCapIcon class="h-5 w-5" /></span>
                        <span class="min-w-0">
                            <span class="line-clamp-2 block text-sm font-semibold text-gray-900 dark:text-gray-100" dir="auto">{{ th.title }}</span>
                            <span class="mt-1 block truncate text-xs text-gray-500" dir="auto">{{ [th.researcher, degreeLabels[th.degree], th.year, th.department].filter(Boolean).join(' · ') }}</span>
                        </span>
                    </Link>
                </div>
            </section>

            <!-- Public folders -->
            <section v-if="publicFolders.length">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-lg font-bold"><FolderIcon class="h-5 w-5 text-amber-500" /> {{ t('guest.files', 'الملفات العامة') }}</h2>
                    <Link :href="route('guest.files')" class="text-sm font-medium text-[var(--accent-600)] hover:underline">{{ t('guest.see_all', 'عرض الكل') }}</Link>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link v-for="f in publicFolders" :key="f.id" :href="route('guest.show', f.id)" class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm hover:border-amber-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <FolderIcon class="h-5 w-5 text-amber-400" /> {{ f.name }}
                    </Link>
                </div>
            </section>

            <!-- Contact -->
            <section v-if="site.contact" class="flex flex-col items-center gap-4 rounded-3xl bg-gradient-to-br from-[var(--accent-600)] to-[var(--accent-700)] p-6 text-center text-white sm:flex-row sm:p-8 sm:text-start">
                <ChatBubbleLeftRightIcon class="h-12 w-12 shrink-0 opacity-80" />
                <div class="flex-1">
                    <h2 class="text-lg font-bold">{{ t('guest.contact_title', 'ما لقيت اللي تدور عليه؟') }}</h2>
                    <p class="mt-1 text-sm text-white/80">{{ t('guest.contact_cta', 'أرسل لنا طلب كتاب أو رسالة، أو أي استفسار، وسنتابعه معك.') }}</p>
                </div>
                <Link :href="route('contact.create')" class="flex items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-[var(--accent-700)] hover:bg-white/90">
                    <PaperAirplaneIcon class="h-4 w-4 rtl:-scale-x-100" /> {{ t('nav.contact', 'تواصل معنا') }}
                </Link>
            </section>
        </div>
    </GuestShell>
</template>
