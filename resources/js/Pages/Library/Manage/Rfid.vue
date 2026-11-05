<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import { useTrans } from '@/Composables/useTrans';
import { useLibrary } from '@/Composables/useLibrary';
import { SignalIcon, LinkIcon, XMarkIcon, ExclamationTriangleIcon, CheckCircleIcon, PrinterIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    sections: Array,
    untagged: Number,
    tagged: Number,
});

const { t } = useTrans();
const { statusLabel, statusClass } = useLibrary();

const tab = ref('link');
const scanInput = ref(null);
const focusScan = () => nextTick(() => scanInput.value?.focus());
onMounted(focusScan);
watch(tab, focusScan);

function beep(ok) {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator();
        o.frequency.value = ok ? 880 : 220;
        o.connect(ctx.destination);
        o.start();
        setTimeout(() => { o.stop(); ctx.close(); }, ok ? 90 : 300);
    } catch { /* no sound */ }
}
const errText = (e) => e.response?.data?.errors?.code?.[0] || e.response?.data?.errors?.tag?.[0] || e.response?.data?.message || t('library.scan_failed', 'تعذّرت العملية.');

// ---------- Link tags: scan the copy's barcode, then its tag ----------
const linkSection = ref('');
const queueList = ref([]);
const current = ref(null);
const linkLog = ref([]);
const linkError = ref('');
const counts = ref({ tagged: props.tagged, untagged: props.untagged });
const code = ref('');

async function loadUntagged() {
    queueList.value = (await window.axios.get(route('library.manage.rfid.untagged'), { params: { section_id: linkSection.value || null } })).data;
    current.value = queueList.value[0] ?? null;
    focusScan();
}

function pickCopy(c) { current.value = c; linkError.value = ''; focusScan(); }

async function submitLink() {
    const c = code.value.trim();
    code.value = '';
    if (!c) return;
    linkError.value = '';
    try {
        if (!current.value) {
            current.value = (await window.axios.post(route('library.manage.rfid.lookup'), { code: c })).data.copy;
            beep(true);
            return;
        }
        await bind(c, false);
    } catch (e) {
        linkError.value = errText(e);
        beep(false);
    } finally {
        focusScan();
    }
}

async function bind(tag, move) {
    try {
        const res = await window.axios.post(route('library.manage.rfid.link'), { copy_id: current.value.id, tag, move });
        const done = res.data.copy;
        if (!current.value.rfid_tag) counts.value = { tagged: counts.value.tagged + 1, untagged: Math.max(0, counts.value.untagged - 1) };
        linkLog.value.unshift(done);
        queueList.value = queueList.value.filter((q) => q.id !== done.id);
        current.value = queueList.value[0] ?? null;
        beep(true);
    } catch (e) {
        if (e.response?.status === 409 && e.response.data.switch) {
            current.value = e.response.data.switch;
            beep(true);
            return;
        }
        if (e.response?.status === 409 && e.response.data.conflict) {
            beep(false);
            if (window.confirm(`${e.response.data.message}\n${t('library.rfid_move_q', 'نقلها إلى هذه النسخة؟')}`)) {
                await bind(tag, true);
            }
            return;
        }
        throw e;
    }
}

async function unlink(c) {
    await window.axios.delete(route('library.manage.rfid.unlink', c.id));
    linkLog.value = linkLog.value.filter((l) => l.id !== c.id);
    counts.value = { tagged: Math.max(0, counts.value.tagged - 1), untagged: counts.value.untagged + 1 };
}

// ---------- Inventory: scan a section's shelves and compare ----------
const invSection = ref('');
const invShelf = ref('');
const scanned = ref([]);
const result = ref(null);
const invError = ref('');
const comparing = ref(false);
let compareTimer;

const scannedSet = computed(() => new Set(scanned.value));

function submitInventory() {
    const fresh = code.value.split(/[\s,;]+/).map((c) => c.trim()).filter((c) => c && !scannedSet.value.has(c));
    code.value = '';
    if (!fresh.length) return;
    scanned.value = [...scanned.value, ...fresh];
    beep(true);
    clearTimeout(compareTimer);
    compareTimer = setTimeout(compare, 700);
}

async function compare() {
    if (!invSection.value) { invError.value = t('library.inv_pick_section', 'اختر القسم أولاً.'); return; }
    comparing.value = true;
    invError.value = '';
    try {
        result.value = (await window.axios.post(route('library.manage.rfid.inventory'), {
            section_id: invSection.value, shelf: invShelf.value || null, codes: scanned.value,
        })).data;
    } catch (e) {
        invError.value = errText(e);
    } finally {
        comparing.value = false;
        focusScan();
    }
}

function resetInventory() {
    scanned.value = [];
    result.value = null;
    focusScan();
}
watch([invSection, invShelf], () => { if (scanned.value.length) compare(); });

async function markLost() {
    const ids = result.value.missing.map((c) => c.id);
    if (!ids.length || !window.confirm(t('library.inv_mark_lost_q', 'تعليم :n نسخة غير موجودة كمفقودة؟', { n: ids.length }))) return;
    await window.axios.post(route('library.manage.rfid.mark'), { copy_ids: ids, status: 'lost' });
    await compare();
}

const foundLost = computed(() => (result.value?.found ?? []).filter((c) => c.status === 'lost'));
async function restoreFound() {
    await window.axios.post(route('library.manage.rfid.mark'), { copy_ids: foundLost.value.map((c) => c.id), status: 'available' });
    await compare();
}

const groups = computed(() => !result.value ? [] : [
    { key: 'missing', label: t('library.inv_missing', 'غير موجودة على الرف'), items: result.value.missing, cls: 'text-red-700 dark:text-red-300' },
    { key: 'misplaced', label: t('library.inv_misplaced', 'في غير مكانها'), items: result.value.misplaced, cls: 'text-amber-700 dark:text-amber-300' },
    { key: 'away', label: t('library.inv_away', 'مُعارة أو محجوزة (خارج الرف طبيعياً)'), items: result.value.away, cls: 'text-sky-700 dark:text-sky-300' },
    { key: 'found', label: t('library.inv_found', 'موجودة في مكانها'), items: result.value.found, cls: 'text-emerald-700 dark:text-emerald-300' },
]);

const printPage = () => window.print();
const onSubmit = () => (tab.value === 'link' ? submitLink() : submitInventory());
</script>

<template>
    <Head :title="t('library.tab.rfid', 'RFID والجرد')" />

    <AppLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.tab.rfid', 'RFID والجرد') }}</h2>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs class="print:hidden" />

            <div class="flex gap-2 print:hidden">
                <button v-for="k in ['link', 'inventory']" :key="k" @click="tab = k"
                    class="rounded-lg border px-4 py-2 text-sm font-medium"
                    :class="tab === k ? 'border-[var(--accent-600)] bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-white' : 'border-gray-200 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'">
                    {{ k === 'link' ? t('library.rfid_link', 'ربط الشرائح بالنسخ') : t('library.inventory', 'جرد الرفوف') }}
                </button>
            </div>

            <!-- Scan box shared by both tools -->
            <form @submit.prevent="onSubmit" class="rounded-xl border-2 border-[var(--accent-400)] bg-white p-4 print:hidden dark:bg-gray-800">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <SignalIcon class="h-5 w-5" />
                    <template v-if="tab === 'link'">
                        {{ current ? t('library.rfid_scan_tag', 'الآن امسح شريحة RFID الملصقة على هذه النسخة') : t('library.rfid_scan_copy', 'امسح باركود النسخة أو اختر من القائمة') }}
                    </template>
                    <template v-else>{{ t('library.inv_scan', 'مرّر القارئ على الرف؛ كل الشرائح والباركودات تُجمع هنا') }}</template>
                </label>
                <div class="mt-2 flex gap-2">
                    <input ref="scanInput" v-model="code" dir="ltr" autocomplete="off"
                        class="block w-full rounded-lg border-gray-300 text-lg tracking-wider dark:border-gray-600 dark:bg-gray-700" :placeholder="t('library.scan_placeholder', 'الرمز...')" />
                    <button class="rounded-lg bg-[var(--accent-600)] px-4 text-white">↵</button>
                </div>
            </form>

            <!-- ===== Link ===== -->
            <div v-if="tab === 'link'" class="grid gap-4 lg:grid-cols-5">
                <div class="space-y-4 lg:col-span-3">
                    <div class="flex gap-3 text-sm">
                        <span class="rounded-lg bg-emerald-50 px-3 py-1.5 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ t('library.rfid_tagged', 'مربوطة') }}: <b>{{ counts.tagged }}</b></span>
                        <span class="rounded-lg bg-amber-50 px-3 py-1.5 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ t('library.rfid_untagged', 'بلا شريحة') }}: <b>{{ counts.untagged }}</b></span>
                    </div>

                    <div v-if="current" class="flex items-start gap-3 rounded-xl border border-[var(--accent-300)] bg-[var(--accent-50)] p-4 dark:bg-gray-800">
                        <LinkIcon class="mt-0.5 h-6 w-6 shrink-0 text-[var(--accent-600)]" />
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ current.title }}</p>
                            <p class="text-sm text-gray-500" dir="ltr">{{ current.barcode }}<span v-if="current.rfid_tag"> · RFID {{ current.rfid_tag }}</span></p>
                            <p v-if="current.rfid_tag" class="mt-1 text-xs text-amber-700">{{ t('library.rfid_replace_note', 'لهذه النسخة شريحة؛ المسح الجديد يستبدلها.') }}</p>
                        </div>
                        <button @click="current = null; focusScan()" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="h-5 w-5" /></button>
                    </div>

                    <p v-if="linkError" class="flex items-start gap-2 rounded-lg bg-red-50 p-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                        <ExclamationTriangleIcon class="h-5 w-5 shrink-0" /> {{ linkError }}
                    </p>

                    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold dark:border-gray-700">{{ t('library.rfid_done', 'رُبطت في هذه الجلسة') }} ({{ linkLog.length }})</h3>
                        <p v-if="!linkLog.length" class="p-4 text-sm text-gray-400">{{ t('library.no_ops', 'لا عمليات بعد.') }}</p>
                        <div v-for="l in linkLog" :key="l.id" class="flex items-center gap-3 border-b border-gray-50 px-4 py-2 text-sm last:border-0 dark:border-gray-700">
                            <CheckCircleIcon class="h-5 w-5 shrink-0 text-emerald-600" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate">{{ l.title }}</p>
                                <p class="text-xs text-gray-500" dir="ltr">{{ l.barcode }} ↔ {{ l.rfid_tag }}</p>
                            </div>
                            <button @click="unlink(l)" class="text-xs text-red-600 hover:underline">{{ t('library.rfid_unlink', 'فك الربط') }}</button>
                        </div>
                    </div>
                </div>

                <div class="space-y-3 lg:col-span-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-sm font-semibold">{{ t('library.rfid_batch', 'ربط متتالٍ لقسم') }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ t('library.rfid_batch_hint', 'تظهر النسخ التي بلا شريحة بالترتيب، وبعد كل مسح تنتقل للتالية.') }}</p>
                        <div class="mt-3 flex gap-2">
                            <select v-model="linkSection" class="block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                <option value="">{{ t('library.all_sections', 'كل الأقسام') }}</option>
                                <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.path }}</option>
                            </select>
                            <button @click="loadUntagged" class="shrink-0 rounded-lg bg-gray-800 px-3 text-sm text-white dark:bg-gray-600">{{ t('library.load', 'عرض') }}</button>
                        </div>
                    </div>
                    <div v-if="queueList.length" class="max-h-[28rem] overflow-y-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <button v-for="q in queueList" :key="q.id" @click="pickCopy(q)"
                            class="block w-full border-b border-gray-50 px-4 py-2 text-start text-sm last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700"
                            :class="current?.id === q.id ? 'bg-[var(--accent-50)] dark:bg-gray-700' : ''">
                            <span class="block truncate">{{ q.title }}</span>
                            <span class="text-xs text-gray-500" dir="ltr">{{ q.barcode }}<span v-if="q.shelf"> · {{ q.shelf }}</span></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ===== Inventory ===== -->
            <div v-else class="space-y-4">
                <div class="flex flex-wrap items-end gap-3 print:hidden">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs text-gray-500">{{ t('library.section', 'القسم') }}</span>
                        <select v-model="invSection" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="">—</option>
                            <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.path }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs text-gray-500">{{ t('library.shelf_optional', 'الرف (اختياري)') }}</span>
                        <input v-model.lazy="invShelf" class="w-32 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <span class="rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-700">{{ t('library.inv_scanned', 'مُسحت') }}: <b>{{ scanned.length }}</b></span>
                    <button @click="compare" :disabled="comparing" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm text-white disabled:opacity-50">
                        <ArrowPathIcon class="h-4 w-4" :class="comparing ? 'animate-spin' : ''" /> {{ t('library.inv_compare', 'قارن') }}
                    </button>
                    <button @click="resetInventory" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600">{{ t('library.inv_reset', 'جرد جديد') }}</button>
                    <button v-if="result" @click="printPage" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600">
                        <PrinterIcon class="h-4 w-4" /> {{ t('action.print', 'طباعة') }}
                    </button>
                </div>
                <p v-if="invError" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ invError }}</p>

                <template v-if="result">
                    <h3 class="hidden text-lg font-bold print:block">{{ t('library.inventory', 'جرد الرفوف') }} — {{ sections.find((s) => s.id === invSection)?.path }} {{ invShelf }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                            <p class="text-xs text-gray-500">{{ t('library.inv_expected', 'المفترض في القسم') }}</p>
                            <p class="text-2xl font-bold">{{ result.expected }}</p>
                        </div>
                        <div v-for="g in groups" :key="g.key" class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                            <p class="text-xs text-gray-500">{{ g.label }}</p>
                            <p class="text-2xl font-bold" :class="g.cls">{{ g.items.length }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 print:hidden">
                        <button v-if="result.missing.length" @click="markLost" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm text-white">{{ t('library.inv_mark_lost', 'تعليم غير الموجودة كمفقودة') }}</button>
                        <button v-if="foundLost.length" @click="restoreFound" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white">{{ t('library.inv_restore', 'إرجاع :n نسخة وُجدت إلى المتاح', { n: foundLost.length }) }}</button>
                    </div>

                    <div v-for="g in groups.filter((g) => g.items.length && g.key !== 'found')" :key="g.key" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <h4 class="border-b border-gray-100 px-4 py-2 text-sm font-semibold dark:border-gray-700" :class="g.cls">{{ g.label }} ({{ g.items.length }})</h4>
                        <div v-for="c in g.items" :key="c.id" class="flex items-center gap-3 border-b border-gray-50 px-4 py-2 text-sm last:border-0 dark:border-gray-700">
                            <div class="min-w-0 flex-1">
                                <p class="truncate">{{ c.title }}</p>
                                <p class="text-xs text-gray-500"><span dir="ltr">{{ c.barcode }}</span><span v-if="c.shelf"> · {{ t('library.shelf', 'الرف') }} {{ c.shelf }}</span><span v-if="g.key === 'misplaced' && c.section_path"> · {{ t('library.belongs_to', 'مكانها') }}: {{ c.section_path }}</span></p>
                            </div>
                            <span class="rounded px-2 py-0.5 text-xs" :class="statusClass(c.status)">{{ statusLabel(c.status) }}</span>
                        </div>
                    </div>

                    <div v-if="result.unknown.length" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ t('library.inv_unknown', 'رموز غير معروفة (شرائح غير مربوطة)') }} ({{ result.unknown.length }})</h4>
                        <p class="mt-2 flex flex-wrap gap-1.5" dir="ltr">
                            <span v-for="u in result.unknown" :key="u" class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs dark:bg-gray-700">{{ u }}</span>
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
