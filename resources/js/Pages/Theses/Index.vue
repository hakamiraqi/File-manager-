<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestShell from '@/Components/GuestShell.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import { AcademicCapIcon, MagnifyingGlassIcon, ArrowDownTrayIcon, ArrowUpTrayIcon, XMarkIcon, FolderOpenIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    theses: Object,
    filters: Object,
    facets: Object,
    presets: Object,
    degreeLabels: Object,
    languageLabels: Object,
    canImport: Boolean,
    oaiEnabled: Boolean,
});

const { t } = useTrans();
const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
const isAdmin = computed(() => page.props.auth?.user?.role === 'admin');
const Layout = computed(() => (signedIn.value ? AppLayout : GuestShell));
const contactEnabled = computed(() => !!page.props.appSettings?.publicSite?.contact);

const q = ref(props.filters.q ?? '');
const field = ref(props.filters.field ?? 'all');

function go(changes) {
    const params = { ...props.filters, ...changes };
    Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
    router.get(route('theses.index'), params, { preserveState: true, preserveScroll: true });
}
const search = () => go({ q: q.value, field: field.value === 'all' ? null : field.value });
const toggle = (key, value) => go({ [key]: props.filters[key] === String(value) ? null : value });

const facetGroups = computed(() => [
    { key: 'degree', label: t('thesis.degree', 'الدرجة'), items: props.facets.degree, name: (v) => props.degreeLabels[v] ?? v },
    { key: 'year', label: t('thesis.year', 'السنة'), items: props.facets.year, name: (v) => v },
    { key: 'department', label: t('thesis.department', 'القسم'), items: props.facets.department, name: (v) => v },
    { key: 'subject', label: t('thesis.subject', 'الموضوع العام'), items: props.facets.subject, name: (v) => v },
    { key: 'language', label: t('thesis.language', 'اللغة'), items: props.facets.language, name: (v) => props.languageLabels[v] ?? v },
].filter((g) => Object.keys(g.items ?? {}).length));

const active = computed(() => facetGroups.value.flatMap((g) => (props.filters[g.key] ? [{ key: g.key, text: g.name(props.filters[g.key]) }] : [])));
const exportUrl = computed(() => route('theses.export', props.filters));
const keywords = (k) => (k || '').split(/[,،]+/).map((v) => v.trim()).filter(Boolean);
const open = ref({});

// ---- Import ----
const importOpen = ref(false);
const folders = ref([]);
const importForm = useForm({ file: null, parent_id: '' });
async function openImport() {
    importOpen.value = true;
    if (!folders.value.length) {
        const list = (await window.axios.get(route('folders.tree'))).data;
        const byId = Object.fromEntries(list.map((f) => [f.id, f]));
        const path = (f) => { const p = []; for (let n = f, i = 0; n && i < 30; n = byId[n.parent_id], i++) p.unshift(n.name); return p.join(' / '); };
        folders.value = list.map((f) => ({ id: f.id, path: path(f) })).sort((a, b) => a.path.localeCompare(b.path, 'ar'));
    }
}
function submitImport() {
    importForm.post(route('theses.import'), { forceFormData: true, preserveScroll: true, onSuccess: () => { importOpen.value = false; importForm.reset(); } });
}
</script>

<template>
    <Head :title="t('nav.theses', 'الرسائل والأطاريح')" />

    <component :is="Layout">
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                <AcademicCapIcon class="h-5 w-5 text-gray-500" /> {{ t('nav.theses', 'الرسائل والأطاريح') }}
            </h2>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <p v-if="presets.university || presets.college" class="text-sm text-gray-500 dark:text-gray-400">{{ [presets.college, presets.university].filter(Boolean).join(' — ') }}</p>

            <!-- Search -->
            <form @submit.prevent="search" class="flex flex-wrap gap-2">
                <select v-model="field" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <option value="all">{{ t('thesis.field_all', 'جميع الحقول') }}</option>
                    <option value="title">{{ t('thesis.field_title', 'العنوان') }}</option>
                    <option value="researcher">{{ t('thesis.field_researcher', 'اسم الباحث') }}</option>
                    <option value="supervisor">{{ t('thesis.field_supervisor', 'المشرف') }}</option>
                    <option value="subject">{{ t('thesis.field_subject', 'الموضوع والكلمات المفتاحية') }}</option>
                </select>
                <div class="relative min-w-[12rem] flex-1">
                    <MagnifyingGlassIcon class="pointer-events-none absolute top-2.5 h-5 w-5 text-gray-400 ltr:left-3 rtl:right-3" />
                    <input v-model="q" type="search" class="w-full rounded-lg border-gray-300 text-sm ltr:pl-10 rtl:pr-10 dark:border-gray-600 dark:bg-gray-800" :placeholder="t('thesis.search_ph', 'ابحث في الرسائل والأطاريح...')" />
                </div>
                <button class="rounded-lg bg-[var(--accent-600)] px-4 text-sm text-white">{{ t('action.search', 'بحث') }}</button>
                <template v-if="signedIn">
                    <a :href="exportUrl" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><ArrowDownTrayIcon class="h-4 w-4" /> {{ t('thesis.export', 'تصدير Excel') }}</a>
                    <button v-if="canImport" type="button" @click="openImport" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><ArrowUpTrayIcon class="h-4 w-4" /> {{ t('thesis.import', 'استيراد') }}</button>
                </template>
            </form>

            <div v-if="active.length" class="flex flex-wrap gap-2">
                <button v-for="a in active" :key="a.key" @click="go({ [a.key]: null })" class="flex items-center gap-1 rounded-full bg-[var(--accent-100)] px-3 py-1 text-sm text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-100">
                    {{ a.text }} <XMarkIcon class="h-4 w-4" />
                </button>
            </div>

            <div class="grid gap-4 lg:grid-cols-4">
                <!-- Browse by -->
                <aside class="space-y-3 lg:col-span-1">
                    <p class="text-sm text-gray-500">{{ t('thesis.total', 'المجموع') }}: <b class="text-gray-800 dark:text-gray-100">{{ facets.total }}</b></p>
                    <details v-for="g in facetGroups" :key="g.key" open class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <summary class="cursor-pointer px-4 py-2.5 text-sm font-semibold">{{ t('thesis.browse_by', 'التصفح حسب') }} {{ g.label }}</summary>
                        <div class="max-h-60 overflow-y-auto border-t border-gray-100 py-1 dark:border-gray-700">
                            <button v-for="(count, value) in g.items" :key="value" @click="toggle(g.key, value)"
                                class="flex w-full items-center justify-between px-4 py-1.5 text-start text-sm hover:bg-gray-50 dark:hover:bg-gray-700"
                                :class="filters[g.key] === String(value) ? 'font-semibold text-[var(--accent-700)] dark:text-white' : ''">
                                <span class="truncate">{{ g.name(value) }}</span><span class="text-xs text-gray-400">{{ count }}</span>
                            </button>
                        </div>
                    </details>
                </aside>

                <!-- Results -->
                <div class="space-y-3 lg:col-span-3">
                    <p v-if="!theses.data.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400 dark:border-gray-600">
                        {{ t('thesis.none', 'لا توجد رسائل مطابقة. حوّل نوع أي مجلد إلى «أطروحة» من معلومات المجلد لتظهر هنا.') }}
                    </p>
                    <article v-for="th in theses.data" :key="th.id" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <a :href="th.url" class="font-semibold text-gray-900 hover:text-[var(--accent-700)] dark:text-gray-100" dir="auto">{{ th.title }}</a>
                                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                                    <span v-if="th.researcher">{{ th.researcher }}</span>
                                    <span v-if="th.degree" class="rounded bg-[var(--accent-100)] px-2 py-0.5 text-xs text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-100">{{ degreeLabels[th.degree] }}</span>
                                    <span v-if="th.year" class="text-gray-500">{{ th.year }}</span>
                                </p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="th.supervisor">{{ t('thesis.supervisor', 'المشرف') }}: {{ th.supervisor }}</span>
                                    <span v-if="th.department"> · {{ th.department }}</span>
                                    <span v-if="th.subject"> · {{ th.subject }}</span>
                                    <span v-if="th.pages"> · {{ th.pages }} {{ t('thesis.pages_short', 'صفحة') }}</span>
                                    <span v-if="th.language"> · {{ languageLabels[th.language] }}</span>
                                </p>
                                <div v-if="keywords(th.keywords).length" class="mt-2 flex flex-wrap gap-1">
                                    <span v-for="k in keywords(th.keywords)" :key="k" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ k }}</span>
                                </div>
                                <div v-if="th.abstract" class="mt-2">
                                    <p class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-300" :class="open[th.id] ? '' : 'line-clamp-2'" dir="auto">{{ th.abstract }}</p>
                                    <button @click="open[th.id] = !open[th.id]" class="text-xs text-[var(--accent-600)] hover:underline">{{ open[th.id] ? t('thesis.less', 'إخفاء') : t('thesis.abstract_more', 'المستخلص كاملاً') }}</button>
                                </div>
                                <p v-if="false && th.iqdr_receipt" class="mt-2 text-xs text-emerald-700 dark:text-emerald-400">{{ t('thesis.iqdr_deposited', 'مودعة في المستودع الرقمي العراقي') }} · {{ th.iqdr_receipt }}</p>
                            </div>
                            <Link v-if="contactEnabled" :href="route('contact.create', { thesis: th.id })" class="shrink-0 rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" :title="t('thesis.request', 'اطلب هذه الرسالة')"><ChatBubbleLeftRightIcon class="h-5 w-5" /></Link>
                            <a :href="th.url" class="shrink-0 rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" :title="t('thesis.open_folder', 'فتح المجلد')"><FolderOpenIcon class="h-5 w-5" /></a>
                        </div>
                    </article>
                    <Pagination :links="theses.links" />
                </div>
            </div>

            <p v-if="oaiEnabled && isAdmin" class="text-xs text-gray-400">
                {{ t('thesis.oai_note', 'الرسائل العامة متاحة للحصاد عبر OAI-PMH:') }}
                <a :href="route('oai', { verb: 'Identify' })" class="underline" dir="ltr" target="_blank">{{ route('oai') }}</a>
            </p>
        </div>

        <Modal :show="importOpen" @close="importOpen = false" max-width="lg">
            <form @submit.prevent="submitImport" class="space-y-4 p-6">
                <h3 class="text-lg font-semibold">{{ t('thesis.import_title', 'استيراد رسائل من Excel') }}</h3>
                <p class="text-sm text-gray-500">
                    {{ t('thesis.import_hint', 'كل صف يصير مجلد «أطروحة» بمعلوماته. العناوين المكرّرة تُتخطّى.') }}
                    <a :href="route('theses.template')" class="text-[var(--accent-600)] underline">{{ t('thesis.template', 'تنزيل النموذج') }}</a>
                </p>
                <input type="file" accept=".xlsx,.csv" @input="importForm.file = $event.target.files[0]" class="block w-full text-sm" />
                <p v-if="importForm.errors.file" class="text-sm text-red-600">{{ importForm.errors.file }}</p>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-gray-500">{{ t('thesis.import_into', 'داخل المجلد') }}</span>
                    <select v-model="importForm.parent_id" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                        <option value="">{{ t('thesis.import_root', 'الرئيسية') }}</option>
                        <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.path }}</option>
                    </select>
                </label>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="importOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="!importForm.file || importForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('thesis.import', 'استيراد') }}</button>
                </div>
            </form>
        </Modal>
    </component>
</template>
