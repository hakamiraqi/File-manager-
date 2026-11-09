<script setup>
// «بطاقات الدخول» (2026-11-09, by request, like the clinics system's login cards): tick students
// (or «تحديد الكل», a whole group or a whole search), give them new passwords and print A4 cards
// to cut out. The login files stay here to print again; on the offline server a file from the
// hosting saves the same passwords. See App\Http\Controllers\Admin\LoginCardController.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    IdentificationIcon, MagnifyingGlassIcon, PrinterIcon, ArrowDownTrayIcon, TrashIcon, ArrowUpTrayIcon, CheckCircleIcon,
    ChevronRightIcon, ChevronLeftIcon, ExclamationTriangleIcon, KeyIcon, QrCodeIcon, XMarkIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ settings: Object, groups: Array, files: Array, offline: Boolean });
const input = 'rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700';

// What the cards say ------------------------------------------------------------------------
const card = reactive({ login_url: props.settings.login_url, alt_url: props.settings.alt_url, wifi: props.settings.wifi, note: props.settings.note, label: '', new_passwords: true });

// Who: the list, its filters and the ticks ----------------------------------------------------
const filters = reactive({ q: '', group: '', who: 'students', active: '' });
const list = ref([]);
const total = ref(0);
const page = ref(1);
const last = ref(1);
const loading = ref(false);
const selected = ref(new Set());
let timer = null;
async function load(p = 1) {
    loading.value = true;
    try {
        const { data } = await axios.get(route('admin.cards.users'), { params: { ...clean(filters), page: p } });
        list.value = data.data;
        total.value = data.total;
        page.value = data.page;
        last.value = data.last;
    } finally { loading.value = false; }
}
const clean = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== null));
watch(() => ({ ...filters }), () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); }, { deep: true });
onMounted(() => load(1));

const pageIds = computed(() => list.value.map((u) => u.id));
const pageAll = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)));
const pageSome = computed(() => pageIds.value.some((id) => selected.value.has(id)) && !pageAll.value);
function toggle(id) {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
}
function togglePage() {
    const s = new Set(selected.value);
    if (pageAll.value) pageIds.value.forEach((id) => s.delete(id));
    else pageIds.value.forEach((id) => s.add(id));
    selected.value = s;
}
const pickingAll = ref(false);
async function selectAllResults() {
    pickingAll.value = true;
    try {
        const { data } = await axios.get(route('admin.cards.users'), { params: { ...clean(filters), ids: 1 } });
        const s = new Set(selected.value);
        data.ids.forEach((id) => s.add(id));
        selected.value = s;
    } finally { pickingAll.value = false; }
}
function clearSelection() { selected.value = new Set(); }
async function pickGroup(g) {
    filters.group = String(g.id);
    card.label ||= g.name;
    await load(1);
    await selectAllResults();
}

// Make the cards ---------------------------------------------------------------------------
const busy = ref(false);
const error = ref('');
const made = ref(null);
async function generate() {
    if (!selected.value.size) return;
    const msg = card.new_passwords
        ? t('admin.cards.confirm_new', 'ستتغيّر كلمات مرور :n مستخدماً، وتتوقف بطاقاتهم القديمة. متأكد؟', { n: selected.value.size })
        : t('admin.cards.confirm_qr', 'ستُصنع بطاقات QR جديدة لـ :n مستخدماً (كلمات المرور لا تتغيّر، وتتوقف البطاقات القديمة). متأكد؟', { n: selected.value.size });
    if (!window.confirm(msg)) return;
    // A window opened now, before waiting, is not blocked; it shows the cards when they are ready.
    const win = window.open('', '_blank');
    busy.value = true;
    error.value = '';
    made.value = null;
    try {
        const { data } = await axios.post(route('admin.cards.generate'), { ...card, user_ids: [...selected.value] });
        made.value = data;
        if (win) win.location = data.print;
        selected.value = new Set();
        router.reload({ only: ['files'] });
        load(page.value);
    } catch (e) {
        win?.close();
        const errs = e?.response?.data?.errors;
        error.value = errs ? Object.values(errs).flat().join(' ') : e?.response?.data?.message || t('admin.cards.failed', 'تعذّر صنع البطاقات.');
    } finally { busy.value = false; }
}

// Kept files and the offline upload --------------------------------------------------------------
function removeFile(f) {
    if (!window.confirm(t('admin.cards.delete_confirm', 'حذف ملف البطاقات «:name»؟ (كلمات المرور فيه لا تتغيّر)', { name: f.name }))) return;
    router.delete(route('admin.cards.destroy', f.name), { preserveScroll: true });
}
const applyFile = ref(null);
const applied = ref(null);
async function apply() {
    if (!applyFile.value || !window.confirm(t('admin.cards.apply_confirm', 'حفظ كلمات المرور وبطاقات QR من هذا الملف على هذا السيرفر؟'))) return;
    const fd = new FormData();
    fd.append('file', applyFile.value);
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(route('admin.cards.apply'), fd);
        applied.value = data;
        router.reload({ only: ['files'] });
    } catch (e) {
        error.value = e?.response?.data?.message || t('admin.cards.failed', 'تعذّر صنع البطاقات.');
    } finally { busy.value = false; }
}
const when = (iso) => new Date(iso).toLocaleString('ar-IQ', { dateStyle: 'medium', timeStyle: 'short' });
const ago = (iso) => (iso ? new Date(iso).toLocaleDateString('ar-IQ') : t('admin.users.never', 'لم يدخل بعد'));
</script>

<template>
    <Head :title="t('admin.cards.title', 'بطاقات الدخول')" />
    <AdminLayout>
        <template #header>
            <h1 class="flex items-center gap-2 text-xl font-semibold"><IdentificationIcon class="h-6 w-6 text-[var(--accent-600)]" /> {{ t('admin.cards.title', 'بطاقات الدخول') }}</h1>
        </template>
        <div class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('admin.cards.intro', 'اختر الطلاب، فتُصنع لكل واحد بطاقة على ورق A4 (عشر بطاقات في الصفحة، تُقص على الخطوط المنقطة): الاسم، اسم المستخدم، كلمة مرور جديدة، عنوان النظام وعنوانه بالآيبي، اسم شبكة الواي فاي، ورمز QR يدخل به الطالب مباشرة.') }}</p>

            <div v-if="error" class="flex items-start gap-2 rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"><ExclamationTriangleIcon class="h-5 w-5 shrink-0" /> {{ error }}</div>
            <div v-if="made" class="flex flex-wrap items-center gap-3 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">
                <CheckCircleIcon class="h-5 w-5" /> {{ t('admin.cards.made', 'صُنعت :n بطاقة.', { n: made.count }) }}
                <a :href="made.print" target="_blank" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1 text-white"><PrinterIcon class="h-4 w-4" /> {{ t('admin.cards.print', 'اطبع البطاقات') }}</a>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- What the cards say -->
                <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 text-sm lg:col-span-1 dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="font-semibold">{{ t('admin.cards.on_card', 'ما يُكتب على البطاقة') }}</h2>
                    <label class="block"><span class="text-xs text-gray-500">{{ t('admin.cards.login_url', 'عنوان النظام') }}</span><input v-model="card.login_url" type="url" dir="ltr" :class="input" class="mt-1 w-full font-mono" /></label>
                    <label class="block"><span class="text-xs text-gray-500">{{ t('admin.cards.alt_url', 'أو بالآيبي (اختياري)') }}</span><input v-model="card.alt_url" type="url" dir="ltr" placeholder="http://192.168.1.10" :class="input" class="mt-1 w-full font-mono" /></label>
                    <label class="block"><span class="text-xs text-gray-500">{{ t('admin.cards.wifi', 'اسم شبكة الواي فاي (اختياري)') }}</span><input v-model="card.wifi" maxlength="64" dir="auto" :class="input" class="mt-1 w-full" /></label>
                    <label class="block"><span class="text-xs text-gray-500">{{ t('admin.cards.label', 'الوسم على البطاقة (مثل اسم الصف؛ فارغاً = مجموعة الطالب)') }}</span><input v-model="card.label" maxlength="60" dir="auto" :class="input" class="mt-1 w-full" /></label>
                    <label class="block"><span class="text-xs text-gray-500">{{ t('admin.cards.note', 'ملاحظة أسفل البطاقة') }}</span><textarea v-model="card.note" rows="2" maxlength="300" :class="input" class="mt-1 w-full" /></label>
                    <div class="space-y-1.5 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                        <label class="flex items-start gap-2"><input v-model="card.new_passwords" type="radio" :value="true" class="mt-0.5" /> <span><KeyIcon class="inline h-4 w-4" /> {{ t('admin.cards.new_passwords', 'كلمات مرور جديدة + QR') }}<span class="block text-xs text-gray-500">{{ t('admin.cards.new_passwords_hint', 'تتغيّر كلمة مرور كل طالب مختار.') }}</span></span></label>
                        <label class="flex items-start gap-2"><input v-model="card.new_passwords" type="radio" :value="false" class="mt-0.5" /> <span><QrCodeIcon class="inline h-4 w-4" /> {{ t('admin.cards.qr_only', 'QR فقط (بلا تغيير كلمة المرور)') }}<span class="block text-xs text-gray-500">{{ t('admin.cards.qr_only_hint', 'البطاقة تحمل اسم المستخدم ورمز الدخول السريع فقط.') }}</span></span></label>
                    </div>
                    <button @click="generate" :disabled="!selected.size || busy" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--accent-600)] px-4 py-2.5 font-semibold text-white disabled:opacity-40">
                        <PrinterIcon class="h-5 w-5" /> {{ busy ? t('admin.cards.working', 'يصنع…') : t('admin.cards.make', 'اصنع بطاقات :n طالباً', { n: selected.size }) }}
                    </button>
                </section>

                <!-- Who -->
                <section class="rounded-2xl border border-gray-200 bg-white text-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 p-3 dark:border-gray-700">
                        <div class="relative min-w-[12rem] flex-1">
                            <MagnifyingGlassIcon class="pointer-events-none absolute top-2 h-4 w-4 text-gray-400 ltr:left-2 rtl:right-2" />
                            <input v-model="filters.q" type="search" :placeholder="t('admin.cards.search', 'بحث بالاسم أو الرقم الجامعي أو البريد')" :class="input" class="w-full ltr:pl-8 rtl:pr-8" />
                        </div>
                        <select v-model="filters.group" :class="input">
                            <option value="">{{ t('admin.cards.all_groups', 'كل المجموعات') }}</option>
                            <option v-for="g in groups" :key="g.id" :value="String(g.id)">{{ g.name }} ({{ g.count }})</option>
                        </select>
                        <select v-model="filters.who" :class="input">
                            <option value="students">{{ t('admin.cards.students', 'الطلاب') }}</option>
                            <option value="staff">{{ t('admin.cards.staff', 'الأساتذة والمراقبون') }}</option>
                            <option value="all">{{ t('admin.cards.everyone', 'الكل (عدا المدراء)') }}</option>
                        </select>
                    </div>
                    <div v-if="groups.length" class="flex flex-wrap items-center gap-1.5 border-b border-gray-100 px-3 py-2 text-xs dark:border-gray-700">
                        <span class="text-gray-500">{{ t('admin.cards.whole_group', 'مجموعة كاملة:') }}</span>
                        <button v-for="g in groups.slice(0, 12)" :key="g.id" @click="pickGroup(g)" class="rounded-full border border-gray-300 px-2.5 py-0.5 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">{{ g.name }}</button>
                    </div>
                    <!-- Selection bar -->
                    <div class="flex flex-wrap items-center gap-2 bg-[var(--accent-50)] px-3 py-2 text-xs dark:bg-gray-900/40">
                        <span class="font-semibold">{{ t('admin.cards.selected', 'المحدد: :n', { n: selected.size }) }}</span>
                        <span class="text-gray-500">· {{ t('admin.cards.results', 'النتائج: :n', { n: total }) }}</span>
                        <button @click="selectAllResults" :disabled="!total || pickingAll" class="rounded-lg bg-[var(--accent-600)] px-2.5 py-1 font-medium text-white disabled:opacity-40">{{ pickingAll ? '…' : t('admin.cards.select_all_results', 'تحديد كل النتائج (:n)', { n: total }) }}</button>
                        <button v-if="selected.size" @click="clearSelection" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1 dark:border-gray-600"><XMarkIcon class="h-3.5 w-3.5" /> {{ t('admin.cards.clear', 'إلغاء التحديد') }}</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-start">
                            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-900/40">
                                <tr>
                                    <th class="w-10 px-3 py-2"><input type="checkbox" :checked="pageAll" :indeterminate.prop="pageSome" @change="togglePage" class="rounded" :aria-label="t('admin.cards.select_page', 'تحديد الكل في هذه الصفحة')" :title="t('admin.cards.select_page', 'تحديد الكل في هذه الصفحة')" /></th>
                                    <th class="px-2 py-2 text-start">{{ t('admin.cards.name', 'الاسم') }}</th>
                                    <th class="px-2 py-2 text-start">{{ t('admin.cards.number', 'الرقم الجامعي') }}</th>
                                    <th class="hidden px-2 py-2 text-start sm:table-cell">{{ t('admin.cards.email', 'البريد') }}</th>
                                    <th class="hidden px-2 py-2 text-start md:table-cell">{{ t('admin.cards.last_login', 'آخر دخول') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in list" :key="u.id" @click="toggle(u.id)" class="cursor-pointer border-t border-gray-100 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/50" :class="selected.has(u.id) ? 'bg-[var(--accent-50)] dark:bg-gray-700/60' : ''">
                                    <td class="px-3 py-2" @click.stop><input type="checkbox" :checked="selected.has(u.id)" @change="toggle(u.id)" class="rounded" :aria-label="u.name" /></td>
                                    <td class="px-2 py-2"><span class="font-medium">{{ u.name }}</span><span v-if="!u.active" class="ms-1 rounded bg-red-100 px-1 text-[10px] text-red-700">{{ t('admin.cards.suspended', 'موقوف') }}</span><QrCodeIcon v-if="u.has_card" class="ms-1 inline h-3.5 w-3.5 text-gray-400" :title="t('admin.cards.has_card', 'له بطاقة سابقة')" /></td>
                                    <td class="px-2 py-2 font-mono text-xs" dir="ltr">{{ u.number || '—' }}</td>
                                    <td class="hidden px-2 py-2 text-xs text-gray-500 sm:table-cell" dir="ltr">{{ u.email || '—' }}</td>
                                    <td class="hidden px-2 py-2 text-xs text-gray-500 md:table-cell">{{ ago(u.last_login) }}</td>
                                </tr>
                                <tr v-if="!list.length"><td colspan="5" class="p-6 text-center text-gray-500">{{ loading ? '…' : t('admin.cards.none', 'لا يوجد أحد بهذا البحث.') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="last > 1" class="flex items-center justify-center gap-3 border-t border-gray-100 p-2 text-xs dark:border-gray-700">
                        <button @click="load(page - 1)" :disabled="page <= 1" class="rounded-lg border border-gray-300 p-1 disabled:opacity-30 dark:border-gray-600"><ChevronRightIcon class="h-4 w-4 ltr:rotate-180" /></button>
                        <span>{{ t('admin.cards.page', 'صفحة :p من :n', { p: page, n: last }) }}</span>
                        <button @click="load(page + 1)" :disabled="page >= last" class="rounded-lg border border-gray-300 p-1 disabled:opacity-30 dark:border-gray-600"><ChevronLeftIcon class="h-4 w-4 ltr:rotate-180" /></button>
                    </div>
                </section>
            </div>

            <!-- Kept login files -->
            <section v-if="files.length" class="rounded-2xl border border-gray-200 bg-white text-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="border-b border-gray-100 px-4 py-2.5 font-semibold dark:border-gray-700">{{ t('admin.cards.files', 'ملفات البطاقات') }}</h2>
                <p class="px-4 pt-2 text-xs text-amber-700 dark:text-amber-300">{{ t('admin.cards.files_hint', 'فيها كلمات المرور كما هي: احذف الملف بعد الطباعة إن لم تحتجه للسيرفر بدون إنترنت.') }}</p>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="f in files" :key="f.name" class="flex items-center gap-2 px-4 py-2">
                        <span class="min-w-0 flex-1 truncate" dir="ltr">{{ f.name }}</span>
                        <span class="text-xs text-gray-500">{{ t('admin.cards.n_cards', ':n بطاقة', { n: f.count }) }} · {{ when(f.time) }}</span>
                        <a :href="route('admin.cards.print', f.name)" target="_blank" class="rounded-full bg-[var(--accent-50)] p-2 text-[var(--accent-700)] hover:brightness-95 dark:bg-gray-700 dark:text-white" :title="t('admin.cards.print', 'اطبع البطاقات')"><PrinterIcon class="h-4 w-4" /></a>
                        <a :href="route('admin.cards.download', f.name)" class="rounded-full bg-gray-100 p-2 hover:bg-gray-200 dark:bg-gray-700" :title="t('admin.cards.download', 'تنزيل الملف')"><ArrowDownTrayIcon class="h-4 w-4" /></a>
                        <button @click="removeFile(f)" class="rounded-full bg-red-50 p-2 text-red-600 hover:bg-red-100 dark:bg-red-900/30" :title="t('admin.cards.delete', 'حذف')"><TrashIcon class="h-4 w-4" /></button>
                    </li>
                </ul>
            </section>

            <!-- The offline server -->
            <section class="space-y-2 rounded-2xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="flex items-center gap-2 font-semibold"><ArrowUpTrayIcon class="h-5 w-5 text-gray-500" /> {{ t('admin.cards.apply_title', 'ملف بطاقات من الاستضافة (للسيرفر بدون إنترنت)') }}</h2>
                <p class="text-xs text-gray-500">{{ t('admin.cards.apply_hint', 'نزّل ملف البطاقات من الاستضافة وارفعه هنا على سيرفر القاعة: تُحفظ نفس كلمات المرور ورموز QR للطلاب الموجودين هنا (بالرقم الجامعي أو البريد)، فتعمل البطاقات المطبوعة في الجهتين.') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="file" accept=".csv" @change="applyFile = $event.target.files[0] ?? null" class="text-sm" />
                    <button @click="apply" :disabled="!applyFile || busy" class="rounded-lg bg-gray-800 px-3 py-1.5 text-white disabled:opacity-40">{{ t('admin.cards.apply', 'احفظ من الملف') }}</button>
                </div>
                <p v-if="applied" class="text-emerald-700 dark:text-emerald-300">{{ t('admin.cards.applied', 'حُفظت كلمات مرور :n مستخدماً.', { n: applied.updated }) }}<template v-if="applied.missing.length"> {{ t('admin.cards.applied_missing', 'غير موجودين هنا: :names', { names: applied.missing.slice(0, 15).join('، ') }) }}</template></p>
            </section>
        </div>
    </AdminLayout>
</template>
