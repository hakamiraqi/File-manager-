<script setup>
// محرّر الترجمات (redesigned 2026-11-05, by request): languages as cards (add / edit with a flag
// or icon), progress, search and filters, full-width text boxes with the reference language
// under each one, and one sticky save bar for all changes.
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FlagIcon from '@/Components/FlagIcon.vue';
import LanguageModal from '@/Components/LanguageModal.vue';
import StickySaveBar from '@/Components/StickySaveBar.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    LanguageIcon, ArrowUturnLeftIcon, MagnifyingGlassIcon, PlusIcon, Cog6ToothIcon, XMarkIcon, CheckCircleIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ locale: String, refLocale: String, locales: Object, rows: Array, languages: Array });

const current = computed(() => props.locales[props.locale] ?? { label: props.locale, dir: 'ltr' });
const reference = computed(() => props.locales[props.refLocale] ?? { label: props.refLocale, dir: 'ltr' });

// Unsaved edits: key → text.
const drafts = reactive({});
watch(() => props.rows, () => Object.keys(drafts).forEach((k) => delete drafts[k]));
const valueOf = (row) => (row.key in drafts ? drafts[row.key] : row.value);
function edit(row, value) {
    if (value === row.value) delete drafts[row.key];
    else drafts[row.key] = value;
}
const dirtyCount = computed(() => Object.keys(drafts).length);

function switchLocale(code) {
    if (dirtyCount.value && !confirm(t('translations.leave_unsaved', 'عندك تغييرات غير محفوظة. تنتقل بدون حفظ؟'))) return;
    router.get(route('admin.translations.index'), { locale: code }, { preserveScroll: true });
}

// Filters.
const q = ref('');
const status = ref('all');
const group = ref('');
const limit = ref(60);
watch([q, status, group], () => (limit.value = 60));

const stats = computed(() => {
    const total = props.rows.length;
    const missing = props.rows.filter((r) => r.missing).length;
    const edited = props.rows.filter((r) => r.overridden).length;
    return { total, missing, edited, done: total - missing, percent: total ? Math.round(((total - missing) / total) * 100) : 0 };
});

const groups = computed(() => {
    const counts = {};
    props.rows.forEach((r) => (counts[r.group] = (counts[r.group] ?? 0) + 1));
    return Object.entries(counts).sort((a, b) => b[1] - a[1]);
});

const filtered = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return props.rows.filter((r) => {
        if (group.value && r.group !== group.value) return false;
        if (status.value === 'edited' && !r.overridden) return false;
        if (status.value === 'missing' && !r.missing) return false;
        if (status.value === 'unsaved' && !(r.key in drafts)) return false;
        if (!needle) return true;
        return r.key.toLowerCase().includes(needle) || (valueOf(r) ?? '').toLowerCase().includes(needle) || (r.reference ?? '').toLowerCase().includes(needle);
    });
});
const visible = computed(() => filtered.value.slice(0, limit.value));

// Saving.
const bulk = useForm({ locale: props.locale, items: [] });
function saveAll() {
    const items = Object.entries(drafts).filter(([, v]) => (v ?? '').trim() !== '').map(([key, value]) => ({ key, value }));
    if (!items.length) return;
    bulk.locale = props.locale;
    bulk.items = items;
    bulk.post(route('admin.translations.bulk'), { preserveScroll: true });
}
function saveOne(row) {
    if (!(row.key in drafts) || !drafts[row.key].trim()) return;
    useForm({ locale: props.locale, key: row.key, value: drafts[row.key] }).post(route('admin.translations.update'), { preserveScroll: true });
}
function resetRow(row) {
    if (!confirm(t('translations.confirm_reset', 'إرجاع النص الأصلي؟'))) return;
    useForm({ locale: props.locale, key: row.key }).delete(route('admin.translations.reset'), { preserveScroll: true });
}
function discard() {
    Object.keys(drafts).forEach((k) => delete drafts[k]);
}

function autosize(e) {
    e.target.style.height = 'auto';
    e.target.style.height = `${e.target.scrollHeight}px`;
}
const vAutosize = { mounted: (el) => { el.style.height = 'auto'; el.style.height = `${el.scrollHeight}px`; } };

// Language modal.
const modal = reactive({ show: false, language: null });
const openLanguage = (language = null) => Object.assign(modal, { show: true, language });

const STATUS = computed(() => [
    ['all', t('translations.all', 'الكل'), stats.value.total],
    ['missing', t('translations.missing', 'غير مترجمة'), stats.value.missing],
    ['edited', t('translations.edited', 'معدّلة'), stats.value.edited],
    ['unsaved', t('translations.unsaved', 'غير محفوظة'), dirtyCount.value],
]);
</script>

<template>
    <Head :title="t('admin.nav.translations', 'الترجمات')" />

    <AdminLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-2">
                <h2 class="flex min-w-0 items-center gap-2 text-base font-semibold text-gray-800 sm:text-lg dark:text-gray-100">
                    <LanguageIcon class="h-5 w-5 shrink-0 text-gray-500" />
                    <span class="truncate">{{ t('translations.editor_title', 'محرّر الترجمات') }}</span>
                </h2>
                <button type="button" @click="openLanguage()" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-[var(--accent-600)] px-3 text-sm font-semibold text-white">
                    <PlusIcon class="h-4 w-4" /> <span class="hidden sm:inline">{{ t('languages.add', 'إضافة لغة') }}</span>
                </button>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 px-4 pt-4 sm:px-6 sm:pt-6">
            <!-- Languages -->
            <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0 lg:grid-cols-4">
                <div v-for="l in languages" :key="l.code"
                    class="relative flex w-56 shrink-0 cursor-pointer items-center gap-3 rounded-2xl border bg-white p-3 transition sm:w-auto dark:bg-gray-800"
                    :class="l.code === locale ? 'border-[var(--accent-600)] ring-1 ring-[var(--accent-600)]' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'"
                    @click="switchLocale(l.code)">
                    <FlagIcon :flag="l.flag" size="h-8 w-11" />
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1.5 truncate font-semibold text-gray-900 dark:text-gray-100">
                            {{ l.name }}
                            <span v-if="!l.is_active" class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-normal text-gray-500 dark:bg-gray-700">{{ t('languages.off', 'متوقفة') }}</span>
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <span dir="ltr" class="font-mono">{{ l.code }}</span>
                            · {{ l.users }} {{ t('languages.users', 'مستخدم') }}
                            <template v-if="l.is_default"> · {{ t('languages.default', 'الافتراضية') }}</template>
                        </p>
                    </div>
                    <button type="button" class="shrink-0 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" :title="t('languages.edit', 'تعديل اللغة')" @click.stop="openLanguage(l)">
                        <Cog6ToothIcon class="h-5 w-5" />
                    </button>
                </div>
                <button type="button" @click="openLanguage()" class="flex w-44 shrink-0 items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-gray-300 p-3 text-sm font-medium text-gray-500 hover:border-[var(--accent-600)] hover:text-[var(--accent-600)] sm:w-auto dark:border-gray-600">
                    <PlusIcon class="h-5 w-5" /> {{ t('languages.add', 'إضافة لغة') }}
                </button>
            </div>

            <!-- Progress -->
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('translations.progress', 'نسبة الترجمة') }} — {{ current.label }}</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ stats.percent }}%</p>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ stats.done }} / {{ stats.total }} {{ t('translations.texts', 'نص') }}
                        · {{ stats.edited }} {{ t('translations.edited_count', 'معدّل') }}
                    </p>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${stats.percent}%` }" />
                </div>
            </div>

            <!-- Search and filters -->
            <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="relative">
                    <MagnifyingGlassIcon class="pointer-events-none absolute inset-y-0 start-3 my-auto h-5 w-5 text-gray-400" />
                    <input v-model="q" type="search" :placeholder="t('translations.search', 'ابحث بالنص أو المفتاح…')" class="block w-full rounded-xl border-gray-300 ps-10 text-sm shadow-sm focus:border-[var(--accent-600)] focus:ring-[var(--accent-600)] dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    <button v-if="q" type="button" class="absolute inset-y-0 end-2 my-auto h-7 rounded-lg px-1.5 text-gray-400 hover:text-gray-700" @click="q = ''"><XMarkIcon class="h-4 w-4" /></button>
                </div>
                <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    <button v-for="[key, label, n] in STATUS" :key="key" type="button" @click="status = key"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition"
                        :class="status === key ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-200 bg-gray-50 text-gray-700 hover:bg-white dark:border-gray-600 dark:bg-gray-700/50 dark:text-gray-200'">
                        {{ label }} <span class="rounded-full px-1.5 text-xs" :class="status === key ? 'bg-white/20' : 'bg-gray-200 dark:bg-gray-600'">{{ n }}</span>
                    </button>
                </div>
                <div class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
                    <button type="button" @click="group = ''" class="shrink-0 rounded-lg px-2.5 py-1 text-xs" :class="group === '' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ t('translations.all_sections', 'كل الأقسام') }}</button>
                    <button v-for="[g, n] in groups" :key="g" type="button" @click="group = group === g ? '' : g" dir="ltr"
                        class="shrink-0 rounded-lg px-2.5 py-1 font-mono text-xs" :class="group === g ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                        {{ g }} <span class="opacity-60">{{ n }}</span>
                    </button>
                </div>
            </div>

            <!-- Texts -->
            <p class="px-1 text-sm text-gray-500 dark:text-gray-400">{{ filtered.length }} {{ t('translations.results', 'نتيجة') }}</p>
            <ul class="space-y-3">
                <li v-for="row in visible" :key="row.key"
                    class="rounded-2xl border bg-white p-4 shadow-sm transition dark:bg-gray-800"
                    :class="row.key in drafts ? 'border-amber-400 ring-1 ring-amber-300 dark:border-amber-500' : 'border-gray-200 dark:border-gray-700'">
                    <div class="mb-2 flex flex-wrap items-center gap-1.5">
                        <code dir="ltr" class="min-w-0 break-all text-xs text-gray-500 dark:text-gray-400">{{ row.key }}</code>
                        <span class="flex-1" />
                        <span v-if="row.key in drafts" class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ t('translations.unsaved_one', 'غير محفوظ') }}</span>
                        <span v-else-if="row.overridden" class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-[var(--accent-600)] dark:bg-indigo-900/30">{{ t('translations.edited_one', 'معدّل') }}</span>
                        <span v-else-if="row.missing" class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ t('translations.missing_one', 'غير مترجم') }}</span>
                        <CheckCircleIcon v-else class="h-4 w-4 text-emerald-500" />
                    </div>

                    <textarea
                        v-autosize
                        :value="valueOf(row)"
                        :dir="current.dir"
                        rows="1"
                        :placeholder="row.reference || t('translations.type_here', 'اكتب الترجمة هنا')"
                        class="block w-full resize-none rounded-xl border-gray-300 text-[15px] leading-relaxed shadow-sm focus:border-[var(--accent-600)] focus:ring-[var(--accent-600)] dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        @input="(e) => { edit(row, e.target.value); autosize(e); }"
                        @keydown.ctrl.enter.prevent="saveOne(row)"
                        @keydown.meta.enter.prevent="saveOne(row)"
                    />

                    <div class="mt-2 space-y-1 text-xs">
                        <p v-if="row.reference" class="flex gap-1.5 text-gray-500 dark:text-gray-400">
                            <span class="shrink-0 font-medium">{{ reference.label }}:</span>
                            <span :dir="reference.dir" class="min-w-0 break-words">{{ row.reference }}</span>
                        </p>
                        <p v-if="row.overridden && row.base" class="flex gap-1.5 text-gray-400">
                            <span class="shrink-0 font-medium">{{ t('translations.original', 'الأصلي') }}:</span>
                            <span :dir="current.dir" class="min-w-0 break-words">{{ row.base }}</span>
                        </p>
                    </div>

                    <div v-if="row.key in drafts || row.overridden" class="mt-3 flex flex-wrap justify-end gap-2">
                        <button v-if="row.key in drafts" type="button" @click="delete drafts[row.key]" class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ t('action.undo', 'تراجع') }}</button>
                        <button v-else-if="row.overridden" type="button" @click="resetRow(row)" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                            <ArrowUturnLeftIcon class="h-4 w-4" /> {{ t('translations.restore', 'إرجاع الأصلي') }}
                        </button>
                        <button v-if="row.key in drafts" type="button" @click="saveOne(row)" :disabled="!drafts[row.key]?.trim()" class="rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-40">{{ t('action.save', 'حفظ') }}</button>
                    </div>
                </li>
                <li v-if="!filtered.length" class="rounded-2xl border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400 dark:border-gray-700">
                    {{ t('translations.nothing', 'لا توجد نصوص تطابق البحث') }}
                </li>
            </ul>
            <div v-if="filtered.length > limit" class="text-center">
                <button type="button" @click="limit += 60" class="rounded-xl border border-gray-300 bg-white px-5 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                    {{ t('translations.more', 'عرض المزيد') }} ({{ filtered.length - limit }})
                </button>
            </div>

            <StickySaveBar :form="bulk" :dirty="dirtyCount > 0" :label="dirtyCount ? `${t('translations.save_all', 'حفظ الكل')} (${dirtyCount})` : t('translations.save_all', 'حفظ الكل')" @save="saveAll" @reset="discard" />
        </div>

        <LanguageModal :show="modal.show" :language="modal.language" @close="modal.show = false" />
    </AdminLayout>
</template>
