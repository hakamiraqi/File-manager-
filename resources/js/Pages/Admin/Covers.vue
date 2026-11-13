<script setup>
// «أغلفة الكتب» (2026-11-12): the automatic cover search — its hours, its sources, and the covers found.
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsSection from '@/Components/SettingsSection.vue';
import SettingToggle from '@/Components/SettingToggle.vue';
import NumberStepper from '@/Components/NumberStepper.vue';
import StickySaveBar from '@/Components/StickySaveBar.vue';
import Pagination from '@/Components/Pagination.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    BookOpenIcon, ClockIcon, GlobeAltIcon, AdjustmentsHorizontalIcon, BoltIcon, ArrowPathIcon, MoonIcon, PlayIcon,
    ExclamationTriangleIcon, TrashIcon, MagnifyingGlassIcon, NoSymbolIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({
    settings: Object, googleKeySet: Boolean, counts: Object, books: Object, state: Object, files: Object, filter: String, q: String,
});
const DAYS = [
    [6, t('ocr.day_sat', 'السبت')], [0, t('ocr.day_sun', 'الأحد')], [1, t('ocr.day_mon', 'الاثنين')], [2, t('ocr.day_tue', 'الثلاثاء')], [3, t('ocr.day_wed', 'الأربعاء')], [4, t('ocr.day_thu', 'الخميس')], [5, t('ocr.day_fri', 'الجمعة')],
];
const KINDS = [['pdf', 'PDF'], ['epub', 'EPUB'], ['djvu', 'DjVu'], ['docx', 'Word']];
const form = useForm({
    covers_enabled: props.settings.covers_enabled === '1',
    covers_days: [...props.settings.covers_days],
    covers_start: props.settings.covers_start,
    covers_end: props.settings.covers_end,
    covers_batch: Number(props.settings.covers_batch),
    covers_min_pages: Number(props.settings.covers_min_pages),
    covers_retry_days: Number(props.settings.covers_retry_days),
    covers_title_search: props.settings.covers_title_search === '1',
    covers_library: props.settings.covers_library === '1',
    covers_openlibrary: props.settings.covers_openlibrary === '1',
    covers_google: props.settings.covers_google === '1',
    covers_kinds: props.settings.covers_kinds.split(',').filter(Boolean),
    covers_google_key: '',
    covers_google_clear: false,
});
function toggle(field, v) {
    const i = form[field].indexOf(v);
    i === -1 ? form[field].push(v) : form[field].splice(i, 1);
}
function submit() {
    form.post(route('admin.covers.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.covers_google_key = '';
            form.covers_google_clear = false;
            form.defaults();
        },
    });
}
const post = (name, data = {}) => router.post(route(name), data, { preserveScroll: true });
const remove = (f) => router.delete(route('admin.covers.remove', f.id), { preserveScroll: true });

const last = computed(() => props.state.last);
const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ar-IQ', { timeZone: 'Asia/Baghdad', weekday: 'long', hour: '2-digit', minute: '2-digit' }) : '');
const ago = (iso) => {
    if (!iso) return '';
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    return m < 1 ? t('ocr.now', 'الآن') : m < 60 ? t('ocr.min_ago', 'قبل :n د', { n: m }) : t('ocr.h_ago', 'قبل :n س', { n: Math.round(m / 60) });
};
const status = computed(() => {
    if (!form.covers_enabled) return { label: t('covers.off', 'متوقف (غير مفعّل)'), icon: NoSymbolIcon, cls: 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600' };
    if (last.value?.error) return { label: last.value.error, icon: ExclamationTriangleIcon, cls: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800' };
    if (props.state.in_window) return { label: t('covers.running', 'داخل وقت البحث'), icon: PlayIcon, cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800' };
    return { label: t('covers.waiting_hours', 'خارج وقت البحث، ينتظر'), icon: MoonIcon, cls: 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-300 dark:ring-indigo-800' };
});
const tiles = computed(() => [
    ['covers', t('covers.with_cover', 'لها غلاف'), props.counts.found + props.counts.manual, 'text-emerald-600'],
    ['waiting', t('covers.not_checked', 'لم يُبحث عنها بعد'), props.counts.waiting, 'text-sky-600'],
    ['none', t('covers.none', 'لم يوجد غلاف'), props.counts.none, 'text-gray-600'],
    ['small', t('covers.small', 'ملفات قصيرة (ليست كتباً)'), props.counts.small, 'text-gray-500'],
    ['removed', t('covers.removed', 'أزيل غلافها يدوياً'), props.counts.removed, 'text-gray-500'],
    ['error', t('covers.errors', 'أخطاء'), props.counts.error, 'text-red-600'],
]);
const search = ref(props.q);
function show(which) {
    if (which === 'waiting') return;
    router.get(route('admin.covers.index'), { show: which === 'covers' ? undefined : which, q: search.value || undefined }, { preserveScroll: true, preserveState: true });
}
const SOURCE = { openlibrary: 'Open Library', google: 'Google Books', upload: t('covers.src_upload', 'صورة مرفوعة'), page: t('covers.src_page', 'صفحة من الملف') };
</script>

<template>
    <Head :title="t('admin.nav.covers', 'أغلفة الكتب')" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                <BookOpenIcon class="h-5 w-5 text-gray-500" />
                {{ t('covers.title', 'أغلفة الكتب') }}
            </h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-6 px-4 pt-4 sm:px-6 sm:pt-6">
            <!-- Status -->
            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 space-y-2">
                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ring-1" :class="status.cls">
                            <component :is="status.icon" class="h-4 w-4" /> {{ status.label }}
                        </span>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('covers.how', 'يبحث تلقائياً عن غلاف كل كتاب برقم ISBN المطبوع داخله، أو باسم الملف إذا تطابق العنوان بوضوح. الغلاف يصبح صورة الملف في الموقع والتطبيق، ويبقى محفوظاً على الخادم فيظهر حتى بدون إنترنت.') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <template v-if="!state.in_window && state.next_start">{{ t('covers.next', 'البحث القادم :when.', { when: fmt(state.next_start) }) }}</template>
                            <template v-if="last"> {{ t('covers.last', 'آخر تشغيل :when: فُحص :n ووُجد :f غلاف.', { when: ago(last.at), n: last.checked, f: last.found }) }}</template>
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('covers.due', 'بانتظار البحث: :n ملف.', { n: counts.due }) }}</p>
                        <p v-if="books" class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('covers.books_line', 'كتب الرفوف: لها غلاف :with، بلا غلاف :without، بانتظار البحث :due.', { with: books.with, without: books.without, due: books.due }) }}
                            <template v-if="last && last.books_checked != null"> {{ t('covers.books_last', 'آخر تشغيل وجد :f غلافاً لكتب الرفوف.', { f: last.books_found }) }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" :disabled="!counts.due && !books?.due" class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--accent-600)] px-3 py-2 text-sm font-semibold text-white hover:bg-[var(--accent-700)] disabled:opacity-40" @click="post('admin.covers.run')">
                            <BoltIcon class="h-4 w-4" /> {{ t('covers.run_now', 'ابحث الآن') }}
                        </button>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" @click="post('admin.covers.retry')">
                            <ArrowPathIcon class="h-4 w-4" /> {{ t('covers.retry', 'أعد البحث عمّا لم يوجد') }}
                        </button>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    <button
                        v-for="tile in tiles"
                        :key="tile[0]"
                        type="button"
                        class="rounded-xl border p-3 text-start"
                        :class="filter === tile[0] ? 'border-[var(--accent-600)] bg-[var(--accent-600)]/5' : 'border-gray-200 dark:border-gray-700'"
                        @click="show(tile[0])"
                    >
                        <span class="block text-xl font-bold tabular-nums" :class="tile[3]">{{ tile[2] }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ tile[1] }}</span>
                    </button>
                </div>
            </section>

            <form class="space-y-6" @submit.prevent="submit">
                <SettingsSection :icon="ClockIcon" :title="t('covers.hours_title', 'وقت البحث التلقائي')" :description="t('covers.hours_desc', 'يعمل خلال هذه الساعات فقط (بتوقيت بغداد)، دفعة كل 10 دقائق وبتمهّل حتى لا يُرهق الخدمات المجانية.')">
                    <SettingToggle v-model="form.covers_enabled" :description="t('covers.enabled_desc', 'عند الإيقاف لا يُبحث تلقائياً؛ يبقى «اختيار غلاف» اليدوي متاحاً.')">{{ t('covers.enabled', 'البحث التلقائي عن الأغلفة') }}</SettingToggle>
                    <div class="mt-4 grid grid-cols-2 gap-4 sm:max-w-sm">
                        <label class="text-sm text-gray-700 dark:text-gray-200">{{ t('ocr.from_hour', 'من الساعة') }}
                            <input v-model="form.covers_start" type="time" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                        <label class="text-sm text-gray-700 dark:text-gray-200">{{ t('ocr.to_hour', 'إلى الساعة') }}
                            <input v-model="form.covers_end" type="time" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ t('ocr.hours_hint', 'إذا كانت النهاية قبل البداية فالوقت يمتد لصباح اليوم التالي. نفس الساعة للبداية والنهاية تعني اليوم كله.') }}</p>
                    <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">{{ t('ocr.days', 'الأيام') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button v-for="d in DAYS" :key="d[0]" type="button" class="rounded-full px-3 py-1.5 text-sm" :class="form.covers_days.includes(d[0]) ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'" @click="toggle('covers_days', d[0])">{{ d[1] }}</button>
                    </div>
                    <p v-if="form.errors.covers_start || form.errors.covers_end" class="mt-2 text-sm text-red-600">{{ form.errors.covers_start || form.errors.covers_end }}</p>
                </SettingsSection>

                <SettingsSection :icon="GlobeAltIcon" :title="t('covers.sources_title', 'مصادر الأغلفة')" :description="t('covers.sources_desc', 'يُرسل رقم ISBN أو عنوان الكتاب فقط، ولا شيء عن المستخدمين. السيرفر بدون إنترنت يبحث متى توفّر الاتصال.')" tone="sky">
                    <SettingToggle v-model="form.covers_openlibrary" :description="t('covers.ol_desc', 'مجانية ولا تحتاج مفتاحاً؛ قوية في الكتب الإنجليزية.')">Open Library</SettingToggle>
                    <SettingToggle v-model="form.covers_google" :description="t('covers.google_desc', 'تغطية أوسع (وأفضل قليلاً للعربية)؛ تحتاج مفتاح Google Books API المجاني.')">Google Books</SettingToggle>
                    <div v-if="form.covers_google" class="mt-2 max-w-md">
                        <input
                            v-model="form.covers_google_key"
                            type="password"
                            dir="ltr"
                            autocomplete="off"
                            class="block w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            :placeholder="googleKeySet ? t('settings.ai_key_kept', 'محفوظ؛ اتركه فارغاً لإبقائه') : 'AIza…'"
                        />
                        <label v-if="googleKeySet" class="mt-1 flex items-center gap-2 text-xs"><input v-model="form.covers_google_clear" type="checkbox" class="rounded" /> {{ t('settings.ai_clear', 'حذف المفتاح') }}</label>
                    </div>
                </SettingsSection>

                <SettingsSection :icon="AdjustmentsHorizontalIcon" :title="t('covers.rules_title', 'ما يُبحث عن غلافه')" tone="amber">
                    <SettingToggle v-model="form.covers_library" :description="t('covers.library_desc', 'الكتب الورقية في الفهرس: تأخذ أولاً صورة ملف PDF المطابق لها في المكتبة الرقمية (برقم ISBN أو العنوان، ويعمل بدون إنترنت)، ثم يُبحث عن غلافها على الإنترنت.')">{{ t('covers.library', 'أغلفة كتب الرفوف أيضاً') }}</SettingToggle>
                    <SettingToggle v-model="form.covers_title_search" :description="t('covers.title_desc', 'عند عدم وجود ISBN: يبحث باسم الملف، ويقبل الغلاف فقط إذا تطابق العنوان تطابقاً واضحاً.')">{{ t('covers.title_search', 'البحث باسم الملف') }}</SettingToggle>
                    <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">{{ t('covers.kinds', 'أنواع الملفات') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button v-for="k in KINDS" :key="k[0]" type="button" class="rounded-full px-3 py-1.5 text-sm" :class="form.covers_kinds.includes(k[0]) ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'" @click="toggle('covers_kinds', k[0])">{{ k[1] }}</button>
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">{{ t('covers.min_pages', 'أقل عدد صفحات للكتاب') }}</p><NumberStepper v-model="form.covers_min_pages" :min="0" :max="5000" :step="10" :unit="t('covers.u_pages', 'صفحة')" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">{{ t('covers.batch', 'ملفات في كل دفعة') }}</p><NumberStepper v-model="form.covers_batch" :min="1" :max="500" :step="10" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">{{ t('covers.retry_days', 'إعادة البحث عمّا لم يوجد بعد') }}</p><NumberStepper v-model="form.covers_retry_days" :min="1" :max="365" :step="5" :unit="t('covers.u_days', 'يوم')" /></div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ t('covers.min_pages_hint', 'الملفات الأقصر (المحاضرات والملخصات) لا يُبحث عن غلافها؛ صفر يعني كل الملفات.') }}</p>
                </SettingsSection>

                <StickySaveBar :form="form" :label="t('covers.save', 'حفظ إعدادات الأغلفة')" @save="submit" @reset="form.reset()" />
            </form>

            <!-- The files -->
            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                <form class="mb-4 flex gap-2" @submit.prevent="show(filter)">
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <input v-model="search" type="search" class="w-full rounded-xl border-gray-300 ps-9 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" :placeholder="t('covers.search', 'ابحث باسم الملف…')" />
                    </div>
                </form>
                <p v-if="!files.data.length" class="py-10 text-center text-sm text-gray-500">{{ t('covers.empty', 'لا ملفات هنا.') }}</p>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                    <div v-for="f in files.data" :key="f.id" class="group">
                        <div class="relative aspect-[2/3] overflow-hidden rounded-lg bg-gray-100 shadow ring-1 ring-black/5 dark:bg-gray-900">
                            <img v-if="f.thumbnail_path" :src="route('files.thumbnail', f.id)" loading="lazy" class="h-full w-full object-cover" alt="" />
                            <BookOpenIcon v-else class="absolute inset-0 m-auto h-10 w-10 text-gray-300" />
                            <button v-if="f.source" type="button" class="absolute end-1 top-1 rounded-full bg-white/90 p-1 text-red-600 opacity-100 shadow sm:opacity-0 sm:group-hover:opacity-100" :title="t('covers.remove', 'إزالة الغلاف')" @click="remove(f)">
                                <TrashIcon class="h-4 w-4" />
                            </button>
                        </div>
                        <Link :href="f.folder_id ? route('dashboard', { folder: f.folder_id }) : route('dashboard')" class="mt-1 block truncate text-xs font-medium text-gray-800 hover:underline dark:text-gray-100"><bdi>{{ f.name }}</bdi></Link>
                        <p class="truncate text-[11px] text-gray-500">{{ SOURCE[f.source] || '' }}<template v-if="f.isbn"> · ISBN {{ f.isbn }}</template></p>
                    </div>
                </div>
                <Pagination v-if="files.links" class="mt-4" :links="files.links" />
            </section>
        </div>
    </AdminLayout>
</template>
