<script setup>
// «أدوات PDF» (2026-11-12): every tool on one page — choose a tool, choose the files, set it up,
// and the result is saved next to the original (which never changes).
import { computed, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FilePicker from '@/Components/PdfTools/FilePicker.vue';
import PageOrganizer from '@/Components/PdfTools/PageOrganizer.vue';
import WatermarkDesigner from '@/Components/PdfTools/WatermarkDesigner.vue';
import HeaderFooterDesigner from '@/Components/PdfTools/HeaderFooterDesigner.vue';
import CropDesigner from '@/Components/PdfTools/CropDesigner.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    ArrowDownTrayIcon,
    ArrowPathIcon,
    ArrowRightIcon,
    ArrowsPointingInIcon,
    ArrowsUpDownIcon,
    BookOpenIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    DocumentDuplicateIcon,
    DocumentTextIcon,
    FolderOpenIcon,
    HashtagIcon,
    LockClosedIcon,
    LockOpenIcon,
    PhotoIcon,
    PlusIcon,
    QueueListIcon,
    ScissorsIcon,
    SparklesIcon,
    Squares2X2Icon,
    XMarkIcon,
    DocumentArrowUpIcon,
    PaintBrushIcon,
    WrenchScrewdriverIcon,
    EyeSlashIcon,
    ScissorsIcon as CropIcon,
    WrenchIcon,
    DocumentMinusIcon,
    PhotoIcon as ImagesIcon,
    Bars3BottomLeftIcon,
    TableCellsIcon,
    PresentationChartBarIcon,
    RectangleStackIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    capabilities: { type: Object, required: true },
    tool: { type: String, default: null },
    files: { type: Array, default: () => [] },
    canCreate: Boolean,
    inApp: Boolean,
});
const { t } = useTrans();

const TOOLS = computed(() => [
    { group: t('pdft.g_organize', 'تنظيم'), items: [
        { id: 'merge', icon: DocumentDuplicateIcon, color: 'bg-rose-500', name: t('pdft.merge', 'دمج ملفات'), desc: t('pdft.merge_d', 'اجمع عدة ملفات PDF أو صور أو مستندات في ملف واحد بالترتيب الذي تريده.'), kinds: ['pdf', 'image', 'office'], multiple: true },
        { id: 'split', icon: ScissorsIcon, color: 'bg-orange-500', name: t('pdft.split', 'تقسيم'), desc: t('pdft.split_d', 'قسّم الملف إلى أجزاء بنطاقات صفحات أو كل عدد من الصفحات.'), kinds: ['pdf'] },
        { id: 'organize', icon: Squares2X2Icon, color: 'bg-amber-500', name: t('pdft.organize', 'تنظيم الصفحات'), desc: t('pdft.organize_d', 'رتّب الصفحات بالسحب، دوّرها، احذفها أو استخرج ما تريد منها.'), kinds: ['pdf'] },
        { id: 'rotate', icon: ArrowPathIcon, color: 'bg-yellow-500', name: t('pdft.rotate', 'تدوير'), desc: t('pdft.rotate_d', 'دوّر كل الصفحات أو الفردية أو الزوجية.'), kinds: ['pdf'] },
        { id: 'remove_blank', icon: DocumentMinusIcon, color: 'bg-stone-500', name: t('pdft.remove_blank', 'حذف الصفحات الفارغة'), desc: t('pdft.remove_blank_d', 'يجد الصفحات البيضاء (من المسح الضوئي مثلاً) ويحذفها.'), kinds: ['pdf'] },
        { id: 'crop', icon: CropIcon, color: 'bg-amber-600', name: t('pdft.crop', 'قص الهوامش'), desc: t('pdft.crop_d', 'قص الهوامش البيضاء تلقائياً أو بمقدار تحدده، لقراءة أريح على الهاتف.'), kinds: ['pdf'] },
    ] },
    { group: t('pdft.g_optimize', 'تحسين وحماية'), items: [
        { id: 'compress', icon: ArrowsPointingInIcon, color: 'bg-emerald-500', name: t('pdft.compress', 'ضغط'), desc: t('pdft.compress_d', 'صغّر حجم الملف للإرسال أو الرفع مع الحفاظ على وضوحه.'), kinds: ['pdf'] },
        { id: 'protect', icon: LockClosedIcon, color: 'bg-sky-600', name: t('pdft.protect', 'حماية بكلمة مرور'), desc: t('pdft.protect_d', 'شفّر الملف (AES-256) وامنع الطباعة أو النسخ إن أردت.'), kinds: ['pdf'] },
        { id: 'unlock', icon: LockOpenIcon, color: 'bg-cyan-600', name: t('pdft.unlock', 'إزالة كلمة المرور'), desc: t('pdft.unlock_d', 'احفظ نسخة بدون كلمة مرور أو قيود (تحتاج كلمة المرور إن وُجدت).'), kinds: ['pdf'] },
        { id: 'redact', icon: EyeSlashIcon, color: 'bg-gray-800', name: t('pdft.redact', 'إخفاء نهائي'), desc: t('pdft.redact_d', 'غطِّ أسماء أو أرقاماً أو صوراً بمربعات سوداء تُحذف معها نهائياً من الملف.'), kinds: ['pdf'] },
        { id: 'repair', icon: WrenchIcon, color: 'bg-zinc-600', name: t('pdft.repair', 'إصلاح ملف تالف'), desc: t('pdft.repair_d', 'يعيد بناء ملف PDF لا يفتح أو يفتح ناقصاً.'), kinds: ['pdf'] },
    ] },
    { group: t('pdft.g_edit', 'تحرير'), items: [
        { id: 'watermark', icon: PaintBrushIcon, color: 'bg-violet-500', name: t('pdft.watermark', 'علامة مائية'), desc: t('pdft.watermark_d', 'أضف نصاً (بالعربية أيضاً) أو شعاراً فوق الصفحات أو خلفها.'), kinds: ['pdf'] },
        { id: 'numbers', icon: HashtagIcon, color: 'bg-purple-500', name: t('pdft.numbers', 'ترقيم الصفحات'), desc: t('pdft.numbers_d', 'أضف أرقام الصفحات في المكان والشكل الذي تختاره.'), kinds: ['pdf'] },
        { id: 'header_footer', icon: Bars3BottomLeftIcon, color: 'bg-fuchsia-600', name: t('pdft.header_footer', 'ترويسة وتذييل'), desc: t('pdft.header_footer_d', 'أضف سطراً أعلى كل صفحة وأسفلها (اسم الكلية، المادة، التاريخ) مع الترقيم إن أردت.'), kinds: ['pdf'] },
    ] },
    { group: t('pdft.g_convert', 'تحويل'), items: [
        { id: 'to_word', icon: DocumentTextIcon, color: 'bg-blue-600', name: t('pdft.to_word', 'PDF إلى Word'), desc: t('pdft.to_word_d', 'حوّل الملف إلى مستند Word قابل للتعديل.'), kinds: ['pdf'] },
        { id: 'to_excel', icon: TableCellsIcon, color: 'bg-green-700', name: t('pdft.to_excel', 'PDF إلى Excel'), desc: t('pdft.to_excel_d', 'الجداول والنصوص في ورقة Excel لكل صفحة، والأرقام تبقى أرقاماً.'), kinds: ['pdf'] },
        { id: 'to_pptx', icon: PresentationChartBarIcon, color: 'bg-orange-600', name: t('pdft.to_pptx', 'PDF إلى PowerPoint'), desc: t('pdft.to_pptx_d', 'كل صفحة شريحة واضحة، لعرض المحاضرة مباشرة.'), kinds: ['pdf'] },
        { id: 'to_images', icon: PhotoIcon, color: 'bg-teal-600', name: t('pdft.to_images', 'PDF إلى صور'), desc: t('pdft.to_images_d', 'كل صفحة صورة PNG أو JPG، في ملف مضغوط واحد.'), kinds: ['pdf'] },
        { id: 'extract_images', icon: ImagesIcon, color: 'bg-emerald-700', name: t('pdft.extract_images', 'استخراج الصور'), desc: t('pdft.extract_images_d', 'كل الصور الموجودة داخل الملف بجودتها الأصلية، في ملف مضغوط.'), kinds: ['pdf'] },
        { id: 'to_text', icon: QueueListIcon, color: 'bg-slate-600', name: t('pdft.to_text', 'PDF إلى نص'), desc: t('pdft.to_text_d', 'استخرج النص كله في ملف نصي.'), kinds: ['pdf'] },
        { id: 'images_to_pdf', icon: DocumentArrowUpIcon, color: 'bg-lime-600', name: t('pdft.images_to_pdf', 'صور إلى PDF'), desc: t('pdft.images_to_pdf_d', 'اجمع الصور (صور الهاتف، المسح الضوئي) في ملف PDF واحد.'), kinds: ['image'], multiple: true },
        { id: 'office_to_pdf', icon: SparklesIcon, color: 'bg-indigo-600', name: t('pdft.office_to_pdf', 'Word / Excel / PowerPoint إلى PDF'), desc: t('pdft.office_to_pdf_d', 'حوّل المستندات المكتبية إلى PDF.'), kinds: ['office'], multiple: true },
    ] },
]);
// Tools that run on many files at once (one result each) — batch conversion (2026-11-12).
const BATCH = ['compress', 'rotate', 'protect', 'watermark', 'numbers', 'header_footer', 'crop', 'repair', 'remove_blank', 'extract_images', 'to_images', 'to_word', 'to_text', 'to_excel', 'to_pptx'];
const toolById = computed(() =>
    Object.fromEntries(TOOLS.value.flatMap((g) => g.items).map((i) => [i.id, BATCH.includes(i.id) ? { ...i, multiple: true, batch: true } : i])),
);

const active = ref(props.tool);
const files = ref([...props.files]);
// Opened with files but no tool: suggest the tools that fit them.
const current = computed(() => (active.value ? toolById.value[active.value] : null));
const fits = (tool) => { const tl = toolById.value[tool.id] || tool; return !files.value.length || (files.value.every((f) => tl.kinds.includes(f.kind)) && (tl.multiple || files.value.length === 1)); };

function choose(id) {
    if (!props.capabilities[id]) return;
    active.value = id;
    result.value = null;
    error.value = '';
    const tl = toolById.value[id];
    files.value = files.value.filter((f) => tl.kinds.includes(f.kind)).slice(0, tl.multiple ? 50 : 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function back() {
    active.value = null;
    result.value = null;
    error.value = '';
}

// ---- files
const picking = ref(false);
function picked(list) {
    picking.value = false;
    if (current.value?.multiple) {
        const have = new Set(files.value.map((f) => f.id));
        files.value = [...files.value, ...list.filter((f) => !have.has(f.id))];
    } else files.value = list.slice(0, 1);
    result.value = null;
}
const moveFile = (i, d) => {
    const j = i + d;
    if (j < 0 || j >= files.value.length) return;
    const arr = [...files.value];
    [arr[i], arr[j]] = [arr[j], arr[i]];
    files.value = arr;
};
const removeFile = (i) => (files.value = files.value.filter((_, k) => k !== i));
const size = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
const first = computed(() => files.value[0] || null);
const previewSrc = computed(() => (first.value ? route('files.preview', first.value.id) : null));

// ---- options per tool
const opt = ref({});
const defaults = {
    split: { mode: 'ranges', ranges: '', every: 5 },
    rotate: { degrees: 90, which: 'all' },
    compress: { level: 'medium' },
    protect: { password: '', confirm: '', deny: [] },
    unlock: { password: '' },
    watermark: { under: false, pages: 'all' },
    numbers: { position: 'bottom-center', format: 'n', start: 1, from: 1, size: 11 },
    to_images: { format: 'png', dpi: 150 },
    header_footer: { pages: 'all', numbers: false, position: 'bottom-center', format: 'n' },
    crop: { auto: true, top: 3, right: 3, bottom: 3, left: 3, which: 'all' },
};
watch(active, (id) => (opt.value = JSON.parse(JSON.stringify(defaults[id] || {}))), { immediate: true });
const organized = ref([]);
const organizeExtra = ref([]);
const pageCount = ref(0);
const designer = ref(null);
const hfDesigner = ref(null);

const minFiles = computed(() => (active.value === 'merge' ? 2 : 1));
const problem = computed(() => {
    if (!current.value) return '';
    if (files.value.length < minFiles.value) return active.value === 'merge' ? t('pdft.need_two', 'اختر ملفين على الأقل.') : t('pdft.need_file', 'اختر ملفاً.');
    if (active.value === 'protect' && !opt.value.password) return t('pdft.need_password', 'اكتب كلمة المرور.');
    if (active.value === 'protect' && opt.value.password !== opt.value.confirm) return t('pdft.password_mismatch', 'كلمتا المرور غير متطابقتين.');
    if (active.value === 'split' && opt.value.mode === 'ranges' && !opt.value.ranges.trim()) return t('pdft.need_ranges', 'اكتب الصفحات، مثل: 1-3, 5, 8-z');
    if (active.value === 'organize' && !organized.value.length) return t('pdft.need_pages', 'أبقِ صفحة واحدة على الأقل.');
    return '';
});

// ---- run
const running = ref(false);
const result = ref(null);
const error = ref('');
async function run() {
    if (problem.value || running.value) return;
    if (active.value === 'redact') {
        // Areas are drawn on the page in the reader.
        window.location.href = route('reader.show', { file: first.value.id, tool: 'redact' });
        return;
    }
    running.value = true;
    error.value = '';
    result.value = null;
    try {
        const form = new FormData();
        (active.value === 'organize' ? [files.value[0], ...organizeExtra.value] : files.value).forEach((f) => form.append('files[]', f.id));
        const o = { ...opt.value };
        delete o.confirm;
        if (active.value === 'organize') organized.value.forEach((p, i) => {
            form.append(`pages[${i}][0]`, p[0]);
            form.append(`pages[${i}][1]`, p[1]);
            form.append(`pages[${i}][2]`, p[2] || 0);
        });
        if (active.value === 'header_footer') {
            if (!hfDesigner.value?.ready()) throw new Error(t('pdft.need_header', 'اكتب نص الترويسة أو التذييل.'));
            form.append('mark', await hfDesigner.value.toBlob(), 'mark.png');
            o.numbers = o.numbers ? 1 : 0;
        }
        if (active.value === 'crop') o.auto = o.auto ? 1 : 0;
        if (active.value === 'watermark') {
            if (!designer.value?.ready()) throw new Error(t('pdft.need_mark', 'اكتب نص العلامة أو اختر صورة.'));
            form.append('mark', await designer.value.toBlob(), 'mark.png');
            o.under = o.under ? 1 : 0;
        }
        for (const [k, v] of Object.entries(o)) {
            if (Array.isArray(v)) v.forEach((x) => form.append(`${k}[]`, x));
            else if (v !== '' && v !== null && v !== undefined) form.append(k, v);
        }
        const { data } = await window.axios.post(route('pdf-tools.run', active.value), form, { timeout: 1800000 });
        result.value = data;
    } catch (e) {
        const d = e.response?.data;
        error.value = d?.errors ? Object.values(d.errors).flat()[0] : d?.message || e.message || t('pdft.failed', 'تعذّر تنفيذ العملية.');
    } finally {
        running.value = false;
    }
}
const folderHref = (f) => (f.folder_id ? route('dashboard', { folder: f.folder_id }) : route('dashboard'));
const isPdf = (f) => (f.extension || '').toLowerCase() === 'pdf';
</script>

<template>
    <Head :title="current ? current.name : t('nav.pdf_tools', 'أدوات PDF')" />
    <AppLayout>
        <template #header>
            <h2 class="flex items-center gap-2 truncate text-lg font-semibold text-gray-800 dark:text-gray-100">
                <button v-if="current" type="button" class="rounded-lg p-1 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('common.back', 'رجوع')" @click="back">
                    <ArrowRightIcon class="h-5 w-5 ltr:rotate-180" />
                </button>
                <WrenchScrewdriverIcon v-else class="h-5 w-5 text-[var(--accent-600)]" />
                {{ current ? current.name : t('nav.pdf_tools', 'أدوات PDF') }}
            </h2>
        </template>

        <div class="mx-auto max-w-6xl px-3 py-5 sm:px-6">
            <!-- Catalogue -->
            <template v-if="!current">
                <p v-if="!canCreate" class="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    {{ t('pdft.no_create', 'حسابك لا يملك صلاحية إنشاء ملفات، فلا يمكن حفظ نتائج الأدوات.') }}
                </p>
                <div v-if="files.length" class="mb-5 flex flex-wrap items-center gap-2 rounded-xl bg-[var(--accent-600)]/10 p-3 text-sm">
                    <span class="font-medium">{{ t('pdft.for_files', 'للملف:') }}</span>
                    <bdi v-for="f in files" :key="f.id" class="rounded-full bg-white px-2.5 py-0.5 text-xs shadow-sm dark:bg-gray-800">{{ f.name }}</bdi>
                    <button type="button" class="ms-auto text-xs text-gray-500 hover:underline" @click="files = []">{{ t('pdft.clear', 'إلغاء الاختيار') }}</button>
                </div>
                <section v-for="g in TOOLS" :key="g.group" class="mb-7">
                    <h3 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ g.group }}</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="tl in g.items"
                            :key="tl.id"
                            type="button"
                            class="group flex items-start gap-3 rounded-2xl border border-gray-200 bg-white p-4 text-start shadow-sm transition hover:-translate-y-0.5 hover:border-[var(--accent-500)] hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 dark:border-gray-700 dark:bg-gray-800"
                            :class="{ 'ring-2 ring-[var(--accent-500)]/40': files.length && fits(tl) }"
                            :disabled="!capabilities[tl.id] || !canCreate"
                            @click="choose(tl.id)"
                        >
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white shadow-sm" :class="tl.color"><component :is="tl.icon" class="h-6 w-6" /></span>
                            <span class="min-w-0">
                                <span class="flex items-center gap-1.5 font-semibold text-gray-900 dark:text-gray-100">{{ tl.name }}
                                    <RectangleStackIcon v-if="BATCH.includes(tl.id)" class="h-3.5 w-3.5 text-gray-400" :title="t('pdft.batch', 'يعمل على عدة ملفات دفعة واحدة')" />
                                </span>
                                <span class="mt-0.5 block text-xs leading-5 text-gray-500 dark:text-gray-400">{{ tl.desc }}</span>
                                <span v-if="!capabilities[tl.id]" class="mt-1 block text-[11px] text-amber-600">{{ t('pdft.unavailable', 'غير متوفرة على هذا الخادم') }}</span>
                            </span>
                        </button>
                    </div>
                </section>
                <p class="text-xs text-gray-500">{{ t('pdft.note', 'كل أداة تحفظ النتيجة ملفاً جديداً بجانب الملف الأصلي، ولا تغيّر الأصل.') }}</p>
            </template>

            <!-- One tool -->
            <template v-else>
                <div class="mb-4 flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white" :class="current.color"><component :is="current.icon" class="h-6 w-6" /></span>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ current.desc }}</p>
                </div>

                <!-- Files -->
                <div class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-2 flex items-center gap-2">
                        <h3 class="flex-1 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ current.multiple ? t('pdft.files', 'الملفات') : t('pdft.file', 'الملف') }}</h3>
                        <button type="button" class="inline-flex items-center gap-1 rounded-xl bg-[var(--accent-600)] px-3 py-1.5 text-xs font-semibold text-white" @click="picking = true">
                            <PlusIcon class="h-4 w-4" />{{ files.length && !current.multiple ? t('pdft.change', 'تغيير') : t('pdft.choose', 'اختيار') }}
                        </button>
                    </div>
                    <p v-if="!files.length" class="rounded-xl border-2 border-dashed border-gray-200 p-6 text-center text-sm text-gray-500 dark:border-gray-700">
                        {{ t('pdft.choose_hint', 'اختر من ملفاتك. لرفع ملف جديد ارفعه أولاً في «ملفاتي».') }}
                    </p>
                    <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(f, i) in files" :key="f.id" class="flex items-center gap-2 py-2">
                            <span v-if="current.multiple" class="w-5 text-center text-xs tabular-nums text-gray-400">{{ i + 1 }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm"><bdi>{{ f.name }}</bdi> <span class="text-xs text-gray-400">· {{ size(f.size) }}</span></span>
                            <template v-if="current.multiple && files.length > 1">
                                <button type="button" class="rounded p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :disabled="i === 0" @click="moveFile(i, -1)"><ChevronUpIcon class="h-4 w-4" /></button>
                                <button type="button" class="rounded p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :disabled="i === files.length - 1" @click="moveFile(i, 1)"><ChevronDownIcon class="h-4 w-4" /></button>
                            </template>
                            <button type="button" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700" @click="removeFile(i)"><XMarkIcon class="h-4 w-4" /></button>
                        </li>
                    </ul>
                    <p v-if="current.batch && files.length > 1" class="mt-1 flex items-center gap-1 text-[11px] text-gray-500"><RectangleStackIcon class="h-3.5 w-3.5" />{{ t('pdft.batch_hint', 'دفعة واحدة: تُطبّق الأداة على كل ملف، ولكل ملف نتيجته.') }}</p>
                    <p v-else-if="current.multiple && files.length > 1" class="mt-1 flex items-center gap-1 text-[11px] text-gray-400"><ArrowsUpDownIcon class="h-3.5 w-3.5" />{{ t('pdft.order_hint', 'الترتيب هنا هو ترتيب النتيجة.') }}</p>
                </div>

                <!-- Options -->
                <div v-if="files.length" class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <template v-if="active === 'split'">
                        <div class="mb-3 flex flex-wrap gap-2">
                            <label v-for="m in [['ranges', t('pdft.split_ranges', 'نطاقات أحددها')], ['every', t('pdft.split_every', 'كل عدد من الصفحات')], ['each', t('pdft.split_each', 'كل صفحة ملف')]]" :key="m[0]" class="pdft-radio" :class="{ 'pdft-radio-on': opt.mode === m[0] }">
                                <input v-model="opt.mode" type="radio" :value="m[0]" class="sr-only" />{{ m[1] }}
                            </label>
                        </div>
                        <label v-if="opt.mode === 'ranges'" class="block">
                            {{ t('pdft.ranges', 'الصفحات (كل نطاق ملف):') }}
                            <input v-model="opt.ranges" dir="ltr" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900" placeholder="1-3, 4-10, 11-z" />
                            <span class="text-xs text-gray-500">{{ t('pdft.ranges_hint', 'z = آخر صفحة. مثال: 1-3, 5, 8-z') }}</span>
                        </label>
                        <label v-else-if="opt.mode === 'every'" class="flex items-center gap-2">
                            {{ t('pdft.every', 'ملف لكل') }}
                            <input v-model.number="opt.every" type="number" min="1" class="w-24 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900" />
                            {{ t('pdft.pages_word', 'صفحات') }}
                        </label>
                    </template>

                    <PageOrganizer v-else-if="active === 'organize'" :key="first.id" v-model="organized" :src="previewSrc" @pages="pageCount = $event" @sources="organizeExtra = $event" />

                    <template v-else-if="active === 'header_footer'">
                        <HeaderFooterDesigner ref="hfDesigner" :key="first.id" :src="previewSrc" />
                        <div class="mt-4 flex flex-wrap items-center gap-4 border-t border-gray-100 pt-3 dark:border-gray-700">
                            <select v-model="opt.pages" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900">
                                <option value="all">{{ t('pdft.all_pages', 'كل الصفحات') }}</option>
                                <option value="odd">{{ t('pdft.odd', 'الفردية') }}</option>
                                <option value="even">{{ t('pdft.even', 'الزوجية') }}</option>
                            </select>
                            <label class="inline-flex items-center gap-1"><input v-model="opt.numbers" type="checkbox" class="rounded" />{{ t('pdft.add_numbers', 'مع أرقام الصفحات') }}</label>
                            <template v-if="opt.numbers">
                                <select v-model="opt.position" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900">
                                    <option value="bottom-center">{{ t('pdft.bottom', 'أسفل') }} · {{ t('pdft.pos_center', 'الوسط') }}</option>
                                    <option value="bottom-left">{{ t('pdft.bottom', 'أسفل') }} · {{ t('pdft.left_side', 'يسار') }}</option>
                                    <option value="bottom-right">{{ t('pdft.bottom', 'أسفل') }} · {{ t('pdft.right_side', 'يمين') }}</option>
                                    <option value="top-left">{{ t('pdft.top', 'أعلى') }} · {{ t('pdft.left_side', 'يسار') }}</option>
                                    <option value="top-right">{{ t('pdft.top', 'أعلى') }} · {{ t('pdft.right_side', 'يمين') }}</option>
                                </select>
                                <select v-model="opt.format" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900">
                                    <option value="n">1</option>
                                    <option value="n/N">1 / 20</option>
                                    <option value="-n-">- 1 -</option>
                                </select>
                            </template>
                        </div>
                    </template>

                    <CropDesigner v-else-if="active === 'crop'" :key="first.id" v-model="opt" :src="previewSrc" />

                    <p v-else-if="active === 'redact'" class="text-gray-600 dark:text-gray-300">
                        {{ t('pdft.redact_how', 'يُفتح الملف في القارئ على أداة «إخفاء نهائي»: اسحب مستطيلاً فوق كل ما تريد إخفاءه، ثم اضغط «تطبيق الإخفاء». تُحفظ نسخة جديدة بلا ما تحت المربعات.') }}
                    </p>

                    <template v-else-if="active === 'rotate'">
                        <div class="mb-3 flex flex-wrap gap-2">
                            <label v-for="d in [[90, t('pdft.right', '90° يميناً')], [180, '180°'], [270, t('pdft.left', '90° يساراً')]]" :key="d[0]" class="pdft-radio" :class="{ 'pdft-radio-on': opt.degrees === d[0] }">
                                <input v-model="opt.degrees" type="radio" :value="d[0]" class="sr-only" />{{ d[1] }}
                            </label>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="w in [['all', t('pdft.all_pages', 'كل الصفحات')], ['odd', t('pdft.odd', 'الفردية')], ['even', t('pdft.even', 'الزوجية')]]" :key="w[0]" class="pdft-radio" :class="{ 'pdft-radio-on': opt.which === w[0] }">
                                <input v-model="opt.which" type="radio" :value="w[0]" class="sr-only" />{{ w[1] }}
                            </label>
                        </div>
                    </template>

                    <div v-else-if="active === 'compress'" class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="l in [['low', t('pdft.c_low', 'خفيف'), t('pdft.c_low_d', 'أعلى جودة، تصغير بسيط')], ['medium', t('pdft.c_med', 'موصى به'), t('pdft.c_med_d', 'جودة جيدة وحجم أصغر بكثير')], ['high', t('pdft.c_high', 'أقصى ضغط'), t('pdft.c_high_d', 'أصغر حجم، جودة أقل للصور')]]"
                            :key="l[0]"
                            class="cursor-pointer rounded-xl border-2 p-3"
                            :class="opt.level === l[0] ? 'border-[var(--accent-600)] bg-[var(--accent-600)]/5' : 'border-gray-200 dark:border-gray-700'"
                        >
                            <input v-model="opt.level" type="radio" :value="l[0]" class="sr-only" />
                            <span class="block font-semibold">{{ l[1] }}</span><span class="text-xs text-gray-500">{{ l[2] }}</span>
                        </label>
                    </div>

                    <div v-else-if="active === 'protect'" class="grid max-w-md gap-3">
                        <input v-model="opt.password" type="password" autocomplete="new-password" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900" :placeholder="t('pdft.password', 'كلمة المرور')" />
                        <input v-model="opt.confirm" type="password" autocomplete="new-password" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900" :placeholder="t('pdft.password_again', 'تأكيد كلمة المرور')" />
                        <p class="text-xs text-gray-500">{{ t('pdft.deny', 'وامنع أيضاً (اختياري):') }}</p>
                        <div class="flex flex-wrap gap-3">
                            <label class="inline-flex items-center gap-1"><input v-model="opt.deny" type="checkbox" value="print" class="rounded" />{{ t('pdft.deny_print', 'الطباعة') }}</label>
                            <label class="inline-flex items-center gap-1"><input v-model="opt.deny" type="checkbox" value="copy" class="rounded" />{{ t('pdft.deny_copy', 'نسخ النص') }}</label>
                            <label class="inline-flex items-center gap-1"><input v-model="opt.deny" type="checkbox" value="modify" class="rounded" />{{ t('pdft.deny_modify', 'التعديل') }}</label>
                        </div>
                        <p class="text-xs text-amber-600">{{ t('pdft.password_warn', 'احفظ كلمة المرور؛ لا يمكن استرجاعها إن نسيتها.') }}</p>
                    </div>

                    <div v-else-if="active === 'unlock'" class="grid max-w-md gap-2">
                        <input v-model="opt.password" type="password" autocomplete="off" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900" :placeholder="t('pdft.current_password', 'كلمة مرور الملف (إن وُجدت)')" />
                        <p class="text-xs text-gray-500">{{ t('pdft.unlock_hint', 'إن كان الملف يُفتح بلا كلمة مرور لكنه يمنع الطباعة أو النسخ، اترك الحقل فارغاً.') }}</p>
                    </div>

                    <template v-else-if="active === 'watermark'">
                        <WatermarkDesigner ref="designer" :key="first.id" :src="previewSrc" />
                        <div class="mt-4 flex flex-wrap gap-4 border-t border-gray-100 pt-3 dark:border-gray-700">
                            <label class="inline-flex items-center gap-1"><input v-model="opt.under" type="checkbox" class="rounded" />{{ t('pdft.under', 'خلف المحتوى') }}</label>
                            <select v-model="opt.pages" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900">
                                <option value="all">{{ t('pdft.all_pages', 'كل الصفحات') }}</option>
                                <option value="first">{{ t('pdft.first_page', 'الصفحة الأولى فقط') }}</option>
                                <option value="odd">{{ t('pdft.odd', 'الفردية') }}</option>
                                <option value="even">{{ t('pdft.even', 'الزوجية') }}</option>
                            </select>
                        </div>
                    </template>

                    <div v-else-if="active === 'numbers'" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="mb-1">{{ t('pdft.position', 'المكان') }}</p>
                            <div class="grid w-48 grid-cols-3 gap-1 rounded-xl bg-gray-100 p-2 dark:bg-gray-900" dir="ltr">
                                <button
                                    v-for="p in ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right']"
                                    :key="p"
                                    type="button"
                                    class="h-9 rounded-lg border-2 text-xs"
                                    :class="[opt.position === p ? 'border-[var(--accent-600)] bg-white dark:bg-gray-800' : 'border-transparent', p.startsWith('bottom') ? 'mt-6' : '']"
                                    @click="opt.position = p"
                                >
                                    1
                                </button>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="block">{{ t('pdft.format', 'الشكل') }}
                                <select v-model="opt.format" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900">
                                    <option value="n">1</option>
                                    <option value="n/N">1 / 20</option>
                                    <option value="-n-">- 1 -</option>
                                    <option value="page">Page 1 of 20</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-2">{{ t('pdft.start_at', 'يبدأ العدّ من') }}<input v-model.number="opt.start" type="number" min="1" class="w-20 rounded-lg border-gray-300 py-1 dark:border-gray-600 dark:bg-gray-900" /></label>
                            <label class="flex items-center gap-2">{{ t('pdft.from_page', 'ابتداءً من الصفحة') }}<input v-model.number="opt.from" type="number" min="1" class="w-20 rounded-lg border-gray-300 py-1 dark:border-gray-600 dark:bg-gray-900" /></label>
                            <label class="flex items-center gap-2">{{ t('pdft.font_size', 'حجم الخط') }}<input v-model.number="opt.size" type="number" min="6" max="36" class="w-20 rounded-lg border-gray-300 py-1 dark:border-gray-600 dark:bg-gray-900" /></label>
                        </div>
                    </div>

                    <div v-else-if="active === 'to_images'" class="flex flex-wrap items-center gap-3">
                        <label v-for="f in [['png', 'PNG'], ['jpg', 'JPG']]" :key="f[0]" class="pdft-radio" :class="{ 'pdft-radio-on': opt.format === f[0] }"><input v-model="opt.format" type="radio" :value="f[0]" class="sr-only" />{{ f[1] }}</label>
                        <select v-model.number="opt.dpi" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900">
                            <option :value="96">{{ t('pdft.q_screen', 'للشاشة') }} (96 dpi)</option>
                            <option :value="150">{{ t('pdft.q_good', 'جيدة') }} (150 dpi)</option>
                            <option :value="300">{{ t('pdft.q_print', 'للطباعة') }} (300 dpi)</option>
                        </select>
                    </div>

                    <p v-else class="text-gray-500">{{ t('pdft.ready', 'جاهز. اضغط «تنفيذ».') }}</p>
                </div>

                <!-- Run -->
                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-[var(--accent-600)] px-6 py-2.5 text-sm font-semibold text-white shadow hover:bg-[var(--accent-700)] disabled:opacity-40"
                        :disabled="!!problem || running"
                        @click="run"
                    >
                        <span v-if="running" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                        <component :is="current.icon" v-else class="h-5 w-5" />
                        {{ running ? t('pdft.working', 'جارٍ التنفيذ…') : active === 'redact' ? t('pdft.open_reader', 'فتح في القارئ') : t('pdft.run', 'تنفيذ') }}
                    </button>
                    <span v-if="problem && files.length" class="text-xs text-gray-500">{{ problem }}</span>
                    <span v-if="running" class="text-xs text-gray-500">{{ t('pdft.wait', 'قد تستغرق الملفات الكبيرة دقيقة أو أكثر؛ أبقِ الصفحة مفتوحة.') }}</span>
                </div>
                <p v-if="error" class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>

                <!-- Result -->
                <div v-if="result" class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                    <p class="mb-2 font-semibold text-emerald-800 dark:text-emerald-300">{{ t('pdft.done_msg', 'تم! حُفظت النتيجة في ملفاتك:') }}</p>
                    <p v-if="result.message" class="mb-2 whitespace-pre-line text-sm text-emerald-800 dark:text-emerald-300">{{ result.message }}</p>
                    <ul class="space-y-1.5">
                        <li v-for="f in result.files" :key="f.id" class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-2 text-sm shadow-sm dark:bg-gray-800">
                            <span class="min-w-0 flex-1 truncate"><bdi>{{ f.name }}</bdi> <span class="text-xs text-gray-400">· {{ f.human_size }}</span></span>
                            <Link v-if="isPdf(f)" :href="route('reader.show', f.id)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-gray-700"><BookOpenIcon class="h-4 w-4" />{{ t('reader.read', 'قراءة') }}</Link>
                            <a :href="route('files.download', f.id)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-gray-700"><ArrowDownTrayIcon class="h-4 w-4" />{{ t('reader.download', 'تنزيل') }}</a>
                            <Link :href="folderHref(f)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-gray-700"><FolderOpenIcon class="h-4 w-4" />{{ t('pdft.open_folder', 'المجلد') }}</Link>
                        </li>
                    </ul>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <button type="button" class="pdft-chip bg-white dark:bg-gray-800" @click="(files = result.files.filter((f) => isPdf(f)).slice(0, 1).map((f) => ({ ...f, kind: 'pdf' }))), back()">
                            {{ t('pdft.continue', 'استخدام النتيجة في أداة أخرى') }}
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <FilePicker v-if="picking && current" :kinds="current.kinds" :multiple="!!current.multiple" :chosen="current.multiple ? files : []" @pick="picked" @close="picking = false" />
    </AppLayout>
</template>

<style>
.pdft-radio {
    cursor: pointer;
    border-radius: 9999px;
    border: 1.5px solid rgb(209 213 219);
    padding: 0.375rem 0.875rem;
}
.dark .pdft-radio {
    border-color: rgb(75 85 99);
}
.pdft-radio-on {
    border-color: var(--accent-600) !important;
    background: color-mix(in srgb, var(--accent-600) 10%, transparent);
    color: var(--accent-600);
    font-weight: 600;
}
</style>
