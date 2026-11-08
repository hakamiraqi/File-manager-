<script setup>
import { ref, onMounted, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import { useTrans } from '@/Composables/useTrans';
import { QrCodeIcon, UserIcon, XMarkIcon, ArrowUturnLeftIcon, ArrowRightCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();

const scanInput = ref(null);
const code = ref('');
const busy = ref(false);
const member = ref(null);
const mode = ref('auto'); // auto = lend or receive; return = receive only
const damaged = ref(false);
const log = ref([]);
const error = ref('');

const search = ref('');
const results = ref([]);
let searchTimer;

const typeLabel = (type) => ({ student: t('library.type.student', 'طالب'), teacher: t('library.type.teacher', 'تدريسي'), staff: t('library.type.staff', 'موظف') })[type] ?? type;
const money = (n) => `${Number(n || 0).toLocaleString('en-US')} ${t('library.currency', 'د.ع')}`;

function focusScan() { nextTick(() => scanInput.value?.focus()); }
onMounted(focusScan);

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

// Readers type fast (an RFID reader can send several tags in a row), so scans queue up and
// run one after another while the input stays free.
const queue = [];
let running = false;
async function submitScan() {
    queue.push(...code.value.split(/[\s,;]+/).map((c) => c.trim()).filter(Boolean));
    code.value = '';
    if (running) return;
    running = true;
    while (queue.length) await scanOne(queue.shift());
    running = false;
    focusScan();
}

async function scanOne(c) {
    busy.value = true;
    error.value = '';
    try {
        const res = await window.axios.post(route('library.manage.counter.scan'), {
            code: c, member_id: member.value?.id ?? null, condition: damaged.value ? 'damaged' : 'good', mode: mode.value,
        });
        const d = res.data;
        if (d.member) member.value = d.member;
        if (d.kind === 'issued') log.value.unshift({ kind: 'issued', text: `${d.loan.copy.book.title}`, sub: `${d.loan.copy.barcode} → ${d.member.name} · ${t('library.due', 'يُرجع')} ${d.loan.due_at.slice(0, 10)}` });
        if (d.kind === 'returned') {
            log.value.unshift({
                kind: 'returned', text: `${d.loan.copy.book.title}`,
                sub: `${d.loan.copy.barcode} ← ${d.loan.member.name}` + (d.loan.fine_amount ? ` · ${t('library.fine', 'غرامة')} ${money(d.loan.fine_amount)}` : ''),
                warn: d.held_for ? t('library.hold_ready_for', 'محجوز لـ :name، ضعه جانباً', { name: d.held_for.name }) : (d.loan.fine_amount ? t('library.collect_fine', 'استوفِ الغرامة') : ''),
            });
            damaged.value = false;
        }
        beep(true);
    } catch (e) {
        error.value = e.response?.data?.errors?.code?.[0] || e.response?.data?.message || t('library.scan_failed', 'تعذّرت العملية.');
        log.value.unshift({ kind: 'error', text: c, sub: error.value });
        beep(false);
    } finally {
        busy.value = false;
    }
}

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        if (search.value.trim().length < 2) { results.value = []; return; }
        results.value = (await window.axios.get(route('library.manage.counter.members'), { params: { q: search.value } })).data;
    }, 250);
}

async function pick(r) {
    const res = await window.axios.post(route('library.manage.counter.member'), { member_id: r.id ?? null, user_id: r.id ? null : r.user_id });
    member.value = res.data.member;
    search.value = '';
    results.value = [];
    focusScan();
}
</script>

<template>
    <Head :title="t('library.tab.counter', 'الإعارة والاسترجاع')" />

    <AppLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.tab.counter', 'الإعارة والاسترجاع') }}</h2>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs />

            <div class="grid gap-4 lg:grid-cols-5">
                <div class="space-y-4 lg:col-span-3">
                    <!-- Scan -->
                    <form @submit.prevent="submitScan" class="rounded-xl border-2 border-[var(--accent-400)] bg-white p-4 dark:bg-gray-800">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                            <QrCodeIcon class="h-5 w-5" /> {{ t('library.scan_here', 'امسح بطاقة القارئ ثم الكتب (باركود أو RFID)') }}
                        </label>
                        <div class="mt-2 flex gap-2">
                            <input ref="scanInput" v-model="code" dir="ltr" autocomplete="off"
                                class="block w-full rounded-lg border-gray-300 text-lg tracking-wider dark:border-gray-600 dark:bg-gray-700" :placeholder="t('library.scan_placeholder', 'الرمز...')" />
                            <button class="rounded-lg bg-[var(--accent-600)] px-4 text-white">↵</button>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                            <label class="flex items-center gap-1.5"><input type="radio" value="auto" v-model="mode" /> {{ t('library.mode_auto', 'إعارة أو استرجاع تلقائياً') }}</label>
                            <label class="flex items-center gap-1.5"><input type="radio" value="return" v-model="mode" /> {{ t('library.mode_return', 'استرجاع فقط') }}</label>
                            <label class="flex items-center gap-1.5 text-amber-700"><input type="checkbox" v-model="damaged" class="rounded" /> {{ t('library.returned_damaged', 'الكتاب المُرجع تالف') }}</label>
                        </div>
                        <p v-if="error" class="mt-3 flex items-start gap-2 rounded-lg bg-red-50 p-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                            <ExclamationTriangleIcon class="h-5 w-5 shrink-0" /> {{ error }}
                        </p>
                    </form>

                    <!-- Log -->
                    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold dark:border-gray-700">{{ t('library.session_log', 'عمليات هذه الجلسة') }}</h3>
                        <p v-if="!log.length" class="p-4 text-sm text-gray-400">{{ t('library.no_ops', 'لا عمليات بعد.') }}</p>
                        <div v-for="(l, i) in log" :key="i" class="flex items-start gap-3 border-b border-gray-50 px-4 py-2.5 last:border-0 dark:border-gray-700">
                            <ArrowRightCircleIcon v-if="l.kind === 'issued'" class="h-5 w-5 shrink-0 text-sky-600" />
                            <ArrowUturnLeftIcon v-else-if="l.kind === 'returned'" class="h-5 w-5 shrink-0 text-emerald-600" />
                            <ExclamationTriangleIcon v-else class="h-5 w-5 shrink-0 text-red-500" />
                            <div class="min-w-0 text-sm">
                                <p class="font-medium" dir="auto">{{ l.kind === 'issued' ? t('library.issued', 'أُعير') : l.kind === 'returned' ? t('library.returned', 'أُرجع') : t('library.failed', 'لم يتم') }}: {{ l.text }}</p>
                                <p class="text-xs text-gray-500" dir="auto">{{ l.sub }}</p>
                                <p v-if="l.warn" class="text-xs font-medium text-amber-700">{{ l.warn }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reader -->
                <div class="space-y-3 lg:col-span-2">
                    <div class="relative">
                        <input v-model="search" @input="onSearch" :placeholder="t('library.find_member', 'ابحث عن قارئ بالاسم أو البطاقة أو البريد')"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <div v-if="results.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <button v-for="r in results" :key="(r.id ?? 'u') + '-' + (r.user_id ?? '')" @click="pick(r)" class="flex w-full items-center justify-between px-3 py-2 text-start text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                                <span>{{ r.name }}</span>
                                <span class="text-xs text-gray-400" dir="ltr">{{ r.card_number ?? r.email }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="member" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-start gap-3">
                            <UserIcon class="h-10 w-10 shrink-0 rounded-full bg-gray-100 p-2 text-gray-500 dark:bg-gray-700" />
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ member.name }}</p>
                                <p class="text-xs text-gray-500"><span dir="ltr">{{ member.card_number }}</span> · {{ typeLabel(member.type) }}<template v-if="member.stage"> · {{ member.stage }}</template></p>
                            </div>
                            <button @click="member = null; focusScan()" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                        </div>
                        <p v-if="!member.active" class="mt-2 rounded bg-red-50 p-2 text-sm text-red-700">{{ t('library.member_inactive', 'حساب القارئ موقوف') }}</p>
                        <p v-if="member.fines_due" class="mt-2 rounded bg-amber-50 p-2 text-sm text-amber-800">{{ t('library.fines_due', 'غرامات مستحقة') }}: {{ money(member.fines_due) }}</p>
                        <h4 class="mt-3 text-sm font-medium">{{ t('library.current_loans', 'الكتب التي عنده') }} ({{ member.loans.length }})</h4>
                        <ul class="mt-1 space-y-1 text-sm">
                            <li v-for="l in member.loans" :key="l.id" class="flex justify-between gap-2 rounded px-2 py-1" :class="l.is_overdue ? 'bg-red-50 text-red-700 dark:bg-red-900/30' : 'bg-gray-50 dark:bg-gray-700/50'">
                                <span class="truncate" dir="auto">{{ l.title }}</span>
                                <span class="shrink-0 text-xs">{{ l.due_at }}<template v-if="l.is_overdue"> ({{ l.days_overdue }} {{ t('library.days', 'يوم') }})</template></span>
                            </li>
                        </ul>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600">{{ t('library.no_member', 'لم يُختر قارئ. للإعارة امسح بطاقة القارئ أو ابحث عنه. للاسترجاع امسح الكتاب مباشرة.') }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
