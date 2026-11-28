<script setup>
// «الأرشيف»: documents, scans and drafts sorted by the words in their text (صادر، وارد…),
// with the letter's number and date. Admins edit the categories and the words behind them.
import { ref, reactive, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import TagInput from '@/Components/TagInput.vue';
import InputError from '@/Components/InputError.vue';
import FileIcon from '@/Components/FileIcon.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    ArchiveBoxIcon, MagnifyingGlassIcon, PlusIcon, PencilIcon, TrashIcon, ArrowPathIcon, BeakerIcon, FolderIcon, ClockIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    files: Object,
    filters: Object,
    rules: Array,
    stats: Object,
    enabled: Boolean,
    isAdmin: Boolean,
    suggestions: { type: Array, default: () => [] },
});
const { t } = useTrans();

const tab = ref('files');
const f = reactive({
    rule: props.filters.rule ?? '',
    q: props.filters.q ?? '',
    ref: props.filters.ref ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
function apply(extra = {}) {
    Object.assign(f, extra);
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null));
    router.get(route('archive.index'), params, { preserveState: true, preserveScroll: true });
}
const activeRules = computed(() => props.rules.filter((r) => r.enabled));
const total = computed(() => props.rules.reduce((n, r) => n + r.count, 0));

// ---- Rules (admin) ----
const editing = ref(null); // rule or {} for a new one
const folders = ref([]);
const ruleForm = useForm({ name: '', keywords: '', match_mode: 'any', color: '#2563eb', target_folder_id: null, priority: 0, enabled: true });

async function loadFolders() {
    if (folders.value.length) return;
    try {
        const list = (await window.axios.get(route('folders.tree'))).data;
        const byParent = {};
        list.forEach((x) => (byParent[x.parent_id ?? 0] ??= []).push(x));
        const out = [];
        const walk = (pid, depth) => (byParent[pid] ?? []).forEach((x) => { out.push({ id: x.id, label: '— '.repeat(depth) + x.name }); walk(x.id, depth + 1); });
        walk(0, 0);
        folders.value = out;
    } catch {
        folders.value = [];
    }
}

function openRule(rule) {
    loadFolders();
    editing.value = rule ?? {};
    ruleForm.clearErrors();
    ruleForm.name = rule?.name ?? '';
    ruleForm.keywords = (rule?.keywords ?? '').split('\n').filter(Boolean).join(', ');
    ruleForm.match_mode = rule?.match_mode ?? 'any';
    ruleForm.color = rule?.color ?? '#2563eb';
    ruleForm.target_folder_id = rule?.target_folder_id ?? null;
    ruleForm.priority = rule?.priority ?? 0;
    ruleForm.enabled = rule?.enabled ?? true;
}
function saveRule() {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? ruleForm.put(route('archive.rules.update', editing.value.id), opts) : ruleForm.post(route('archive.rules.store'), opts);
}
function ruleFromWord(s) {
    openRule(null);
    ruleForm.name = s.word;
    ruleForm.keywords = s.word;
}
function deleteRule(rule) {
    if (!confirm(t('archive.delete_confirm', 'حذف التصنيف «:name»؟ الملفات تبقى، لكن تفقد هذا التصنيف.', { name: rule.name }))) return;
    router.delete(route('archive.rules.destroy', rule.id), { preserveScroll: true });
}
function toggleEnabled(on) {
    router.post(route('archive.reclassify'), { enabled: on }, { preserveScroll: true });
}
function reclassify() {
    router.post(route('archive.reclassify'), {}, { preserveScroll: true });
}

// ---- Try a text ----
const sample = ref('');
const trial = ref(null);
const trying = ref(false);
async function tryText() {
    if (!sample.value.trim()) return;
    trying.value = true;
    try {
        trial.value = (await window.axios.post(route('archive.test'), { text: sample.value })).data;
    } finally {
        trying.value = false;
    }
}
</script>

<template>
    <Head :title="t('nav.archive', 'الأرشيف')" />
    <AppLayout>
        <template #header>
            <h1 class="flex items-center gap-2 text-lg font-semibold"><ArchiveBoxIcon class="h-6 w-6 text-[var(--accent-600)]" /> {{ t('nav.archive', 'الأرشيف') }}</h1>
        </template>

        <div class="space-y-5 p-4 sm:p-6">
            <div v-if="!enabled" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {{ t('archive.off', 'الأرشفة التلقائية متوقفة؛ الملفات الجديدة لا تُصنَّف.') }}
                <button v-if="isAdmin" class="ms-2 font-medium underline" @click="toggleEnabled(true)">{{ t('archive.turn_on', 'تشغيل') }}</button>
            </div>

            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ t('archive.intro', 'كل مستند أو صورة ممسوحة تُرفع يُقرأ نصها (الصور والـ PDF الممسوح بالتعرف الضوئي)، ثم تُصنَّف حسب كلماتها مثل «الصادر» و«الوارد»، ويُستخرج رقم الكتاب وتاريخه.') }}
            </p>

            <div class="flex gap-2 border-b border-gray-200 dark:border-gray-700">
                <button class="-mb-px border-b-2 px-3 py-2 text-sm font-medium" :class="tab === 'files' ? 'border-[var(--accent-600)] text-[var(--accent-700)]' : 'border-transparent text-gray-500'" @click="tab = 'files'">
                    {{ t('archive.files', 'الملفات المؤرشفة') }} <span class="text-xs text-gray-400">({{ total }})</span>
                </button>
                <button class="-mb-px border-b-2 px-3 py-2 text-sm font-medium" :class="tab === 'rules' ? 'border-[var(--accent-600)] text-[var(--accent-700)]' : 'border-transparent text-gray-500'" @click="tab = 'rules'">
                    {{ t('archive.rules', 'التصنيفات والكلمات الدالة') }}
                </button>
            </div>

            <!-- Files -->
            <template v-if="tab === 'files'">
                <div class="flex flex-wrap gap-2">
                    <button
                        class="rounded-full border px-3 py-1 text-sm"
                        :class="!f.rule ? 'border-transparent bg-[var(--accent-600)] text-white' : 'border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-200'"
                        @click="apply({ rule: '' })"
                    >{{ t('archive.all', 'الكل') }}</button>
                    <button
                        v-for="r in activeRules"
                        :key="r.id"
                        class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm"
                        :class="String(f.rule) === String(r.id) ? 'border-transparent text-white' : 'border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-200'"
                        :style="String(f.rule) === String(r.id) ? { background: r.color || '#64748b' } : null"
                        @click="apply({ rule: String(r.id) })"
                    >
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: r.color || '#64748b' }" /> {{ r.name }}
                        <span class="text-xs opacity-70">{{ r.count }}</span>
                    </button>
                    <button
                        class="rounded-full border px-3 py-1 text-sm"
                        :class="f.rule === 'none' ? 'border-transparent bg-gray-600 text-white' : 'border-dashed border-gray-300 text-gray-500 dark:border-gray-600'"
                        @click="apply({ rule: 'none' })"
                    >{{ t('archive.unsorted', 'لم يُعرف تصنيفها') }} <span class="text-xs opacity-70">{{ stats.unsorted }}</span></button>
                </div>

                <form class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="apply()">
                    <div class="relative lg:col-span-2">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-2.5 top-2.5 h-4 w-4 text-gray-400" />
                        <input v-model="f.q" type="search" :placeholder="t('archive.search', 'ابحث في الاسم أو النص…')" class="w-full rounded-lg border-gray-300 ps-8 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    </div>
                    <input v-model="f.ref" type="search" :placeholder="t('archive.ref_ph', 'رقم الكتاب (العدد)')" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    <div class="flex items-center gap-1 text-xs text-gray-500">
                        <span>{{ t('archive.from', 'من') }}</span>
                        <input v-model="f.from" type="date" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    </div>
                    <div class="flex items-center gap-1 text-xs text-gray-500">
                        <span>{{ t('archive.to', 'إلى') }}</span>
                        <input v-model="f.to" type="date" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                        <button type="submit" class="rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm text-white">{{ t('action.search', 'بحث') }}</button>
                    </div>
                </form>

                <p v-if="stats.waiting_ocr" class="flex items-center gap-1.5 text-xs text-gray-500">
                    <ClockIcon class="h-4 w-4" />
                    {{ t('archive.waiting_ocr', ':count ملف ممسوح بانتظار قراءة نصه (يعمل التعرف الضوئي في الأوقات الهادئة)، ثم يُصنَّف تلقائياً.', { count: stats.waiting_ocr }) }}
                </p>

                <div v-if="!files.data.length" class="py-16 text-center text-gray-400">
                    <ArchiveBoxIcon class="mx-auto h-14 w-14" />
                    <p class="mt-2">{{ t('archive.empty', 'لا توجد ملفات مؤرشفة بهذا الشرط بعد.') }}</p>
                </div>
                <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 text-start">{{ t('archive.file', 'الملف') }}</th>
                                <th class="px-3 py-2 text-start">{{ t('archive.category', 'التصنيف') }}</th>
                                <th class="px-3 py-2 text-start">{{ t('archive.ref', 'العدد') }}</th>
                                <th class="px-3 py-2 text-start">{{ t('archive.date', 'التاريخ') }}</th>
                                <th class="hidden px-3 py-2 text-start md:table-cell">{{ t('archive.folder', 'المجلد') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="file in files.data" :key="file.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="max-w-xs px-3 py-2">
                                    <a :href="(file.extension || '').toLowerCase() === 'pdf' ? route('reader.show', file.id) : route('files.preview', file.id)" target="_blank" class="flex items-center gap-2 hover:underline">
                                        <FileIcon :extension="file.extension" :mime-type="file.mime_type" class="h-5 w-5 shrink-0" />
                                        <span class="truncate"><bdi>{{ file.name }}</bdi></span>
                                    </a>
                                    <p v-if="file.subject" class="mt-0.5 truncate text-xs text-gray-500" dir="auto">{{ t('archive.subject', 'م/') }} {{ file.subject }}</p>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-if="file.rule" class="rounded-full px-2 py-0.5 text-xs font-medium text-white" :style="{ background: file.rule.color || '#64748b' }">{{ file.rule.name }}</span>
                                        <span v-for="tag in file.tags.filter((x) => x !== file.rule?.name)" :key="tag" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ tag }}</span>
                                        <span v-if="file.manual" class="text-[10px] text-gray-400">{{ t('archive.by_hand', '(يدوي)') }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600 dark:text-gray-300"><bdi>{{ file.ref || '—' }}</bdi></td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600 dark:text-gray-300">{{ file.date || '—' }}</td>
                                <td class="hidden px-3 py-2 md:table-cell">
                                    <Link v-if="file.folder" :href="route('dashboard', { folder: file.folder.id })" class="flex items-center gap-1 text-gray-500 hover:underline">
                                        <FolderIcon class="h-4 w-4" /> <span class="truncate">{{ file.folder.name }}</span>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :links="files.links" />
            </template>

            <!-- Rules -->
            <template v-else>
                <div v-if="isAdmin" class="flex flex-wrap items-center gap-2">
                    <button class="flex items-center gap-1.5 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm text-white" @click="openRule(null)">
                        <PlusIcon class="h-4 w-4" /> {{ t('archive.add_rule', 'تصنيف جديد') }}
                    </button>
                    <button class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:text-gray-200" @click="reclassify">
                        <ArrowPathIcon class="h-4 w-4" /> {{ t('archive.reclassify', 'إعادة تصنيف كل الملفات') }}
                    </button>
                    <label class="ms-auto flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <input type="checkbox" :checked="enabled" class="rounded border-gray-300 text-[var(--accent-600)]" @change="toggleEnabled($event.target.checked)" />
                        {{ t('archive.enabled', 'الأرشفة التلقائية تعمل') }}
                    </label>
                </div>
                <p v-else class="text-sm text-gray-500">{{ t('archive.admin_only', 'يعدّل مدير النظام التصنيفات وكلماتها.') }}</p>

                <div class="grid gap-3 md:grid-cols-2">
                    <div v-for="r in rules" :key="r.id" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800" :class="{ 'opacity-60': !r.enabled }">
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded-full" :style="{ background: r.color || '#64748b' }" />
                            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ r.name }}</h3>
                            <span class="text-xs text-gray-400">{{ r.count }} {{ t('archive.files_count', 'ملف') }}</span>
                            <template v-if="isAdmin">
                                <button class="ms-auto rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.edit', 'تعديل')" @click="openRule(r)"><PencilIcon class="h-4 w-4 text-gray-500" /></button>
                                <button class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.delete', 'حذف')" @click="deleteRule(r)"><TrashIcon class="h-4 w-4 text-red-500" /></button>
                            </template>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <span v-for="k in r.keywords.split('\n').filter(Boolean)" :key="k" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ k }}</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">
                            {{ r.match_mode === 'all' ? t('archive.mode_all', 'يجب أن تظهر كل الكلمات') : t('archive.mode_any', 'تكفي أي كلمة') }}
                            <template v-if="r.target_folder"> · {{ t('archive.moves_to', 'يُنقل إلى') }} «{{ r.target_folder.name }}»</template>
                        </p>
                    </div>
                </div>

                <div v-if="isAdmin && suggestions.length" class="rounded-xl border border-dashed border-[var(--accent-400)] bg-[var(--accent-50)] p-4 dark:bg-gray-800">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ t('archive.suggest', 'كلمات متكررة في ملفات بلا تصنيف') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">{{ t('archive.suggest_hint', 'من مواضيع الكتب (م/) وبداياتها. اضغط كلمة لتجعلها تصنيفاً جديداً، ثم أضف لها كلمات مشابهة.') }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="s in suggestions"
                            :key="s.word"
                            type="button"
                            class="rounded-full border border-gray-300 bg-white px-2.5 py-1 text-xs text-gray-700 hover:border-[var(--accent-500)] dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                            :title="s.example || ''"
                            @click="ruleFromWord(s)"
                        >{{ s.word }} <span class="text-gray-400">{{ s.count }}</span></button>
                    </div>
                </div>

                <div v-if="isAdmin" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200"><BeakerIcon class="h-4 w-4" /> {{ t('archive.try', 'جرّب نصاً') }}</h3>
                    <textarea v-model="sample" rows="4" dir="auto" :placeholder="t('archive.try_ph', 'الصق نص كتاب رسمي لترى كيف سيُصنَّف…')" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700"></textarea>
                    <button class="mt-2 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white disabled:opacity-50" :disabled="trying" @click="tryText">{{ t('archive.try_btn', 'صنّف') }}</button>
                    <div v-if="trial" class="mt-3 space-y-1 text-sm">
                        <p v-if="!trial.matches.length" class="text-gray-500">{{ t('archive.no_match', 'لا يطابق أي تصنيف.') }}</p>
                        <p v-for="(m, i) in trial.matches" :key="m.name" class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs text-white" :style="{ background: m.color || '#64748b' }">{{ m.name }}</span>
                            <span class="text-xs text-gray-500">{{ t('archive.score', 'الدرجة') }} {{ m.score }} · {{ m.hits.join('، ') }}</span>
                            <span v-if="i === 0" class="text-xs font-medium text-emerald-600">{{ t('archive.chosen', '← التصنيف المختار') }}</span>
                        </p>
                        <p class="text-xs text-gray-500">{{ t('archive.ref', 'العدد') }}: <bdi>{{ trial.ref || '—' }}</bdi> · {{ t('archive.date', 'التاريخ') }}: {{ trial.date || '—' }}<template v-if="trial.subject"> · {{ t('archive.subject', 'م/') }} {{ trial.subject }}</template></p>
                    </div>
                </div>
            </template>
        </div>

        <Modal :show="!!editing" max-width="lg" @close="editing = null">
            <form v-if="editing" class="space-y-4 p-5" @submit.prevent="saveRule">
                <h2 class="text-lg font-semibold">{{ editing.id ? t('archive.edit_rule', 'تعديل التصنيف') : t('archive.add_rule', 'تصنيف جديد') }}</h2>
                <div class="grid grid-cols-[1fr_auto] gap-3">
                    <div>
                        <label class="mb-1 block text-xs text-gray-500">{{ t('archive.rule_name', 'اسم التصنيف') }}</label>
                        <input v-model="ruleForm.name" type="text" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <InputError :message="ruleForm.errors.name" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-gray-500">{{ t('folder_look.color', 'اللون') }}</label>
                        <input v-model="ruleForm.color" type="color" class="h-9 w-14 cursor-pointer rounded border-gray-300" />
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-gray-500">{{ t('archive.keywords', 'الكلمات الدالة (كلمة أو عبارة، اضغط Enter بعد كل واحدة)') }}</label>
                    <TagInput v-model="ruleForm.keywords" multiword :placeholder="t('archive.keywords_ph', 'مثلاً: الوارد')" />
                    <InputError :message="ruleForm.errors.keywords" />
                    <p class="mt-1 text-[11px] text-gray-400">{{ t('archive.keywords_hint', 'لا يهم الهمزة والتاء المربوطة والتشكيل، والكلمة الطويلة تطابق ما يشبهها بفرق حرف واحد (أخطاء القراءة الضوئية)؛ «صادر» تطابق «الصادرة» و«والصادر» لكن لا تطابق «مصادر».') }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-gray-500">{{ t('archive.match', 'المطابقة') }}</label>
                        <select v-model="ruleForm.match_mode" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="any">{{ t('archive.mode_any', 'تكفي أي كلمة') }}</option>
                            <option value="all">{{ t('archive.mode_all', 'يجب أن تظهر كل الكلمات') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-gray-500">{{ t('archive.priority', 'الأولوية عند التعادل') }}</label>
                        <input v-model.number="ruleForm.priority" type="number" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-gray-500">{{ t('archive.target', 'نقل الملف تلقائياً إلى مجلد (اختياري)') }}</label>
                    <select v-model="ruleForm.target_folder_id" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                        <option :value="null">{{ t('archive.no_move', '— يبقى في مكانه —') }}</option>
                        <option v-for="x in folders" :key="x.id" :value="x.id">{{ x.label }}</option>
                    </select>
                    <InputError :message="ruleForm.errors.target_folder_id" />
                    <p class="mt-1 text-[11px] text-gray-400">{{ t('archive.target_hint', 'يُنقل الملف فقط إذا كان صاحبه يملك الوصول إلى هذا المجلد.') }}</p>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="ruleForm.enabled" type="checkbox" class="rounded border-gray-300 text-[var(--accent-600)]" /> {{ t('archive.rule_enabled', 'مفعّل') }}
                </label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300" @click="editing = null">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button type="submit" :disabled="ruleForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('action.save', 'حفظ') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
