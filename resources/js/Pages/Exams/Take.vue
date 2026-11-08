<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { useTrans } from '@/Composables/useTrans';
import { ClockIcon, FlagIcon, ChevronLeftIcon, ChevronRightIcon, ArrowUpIcon, ArrowDownIcon, CheckIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ attempt: Object, exam: Object, questions: Array, answers: Object });
const { t } = useTrans();

// ---- Answers (kept here, saved as they change) ----
const state = reactive({});
const touched = reactive({});
const flags = reactive({});
for (const q of props.questions) {
    const saved = props.answers?.[q.id] ?? {};
    flags[q.id] = !!saved.flagged;
    const r = saved.response ?? {};
    if (q.type === 'multiple') state[q.id] = { choices: r.choices ?? [] };
    else if (q.type === 'matching') state[q.id] = { pairs: r.pairs ?? {} };
    else if (q.type === 'ordering') {
        state[q.id] = { order: r.order?.length ? r.order : q.choices.map((c) => c.id) };
        touched[q.id] = !!r.order?.length;
    }
    else if (q.type === 'numeric') state[q.id] = { value: r.value ?? '' };
    else if (q.type === 'cloze') state[q.id] = { blanks: { ...(r.blanks ?? {}) } };
    else if (q.type === 'short' || q.type === 'essay') state[q.id] = { text: r.text ?? '' };
    else state[q.id] = { choice: r.choice ?? null };
}

const graded = computed(() => props.questions.filter((q) => q.type !== 'description'));
const number = (q) => graded.value.indexOf(q) + 1;
const answered = (q) => {
    const s = state[q.id];
    if (!s) return false;
    if (q.type === 'multiple') return s.choices.length > 0;
    if (q.type === 'matching') return Object.keys(s.pairs).length > 0;
    if (q.type === 'ordering') return touched[q.id];
    if (q.type === 'numeric') return String(s.value).trim() !== '';
    if (q.type === 'cloze') return Object.values(s.blanks).some((v) => String(v ?? '').trim() !== '');
    if (q.type === 'short' || q.type === 'essay') return s.text.trim() !== '';
    return s.choice !== null;
};
const answeredCount = computed(() => graded.value.filter(answered).length);

const saving = ref(0);
const failed = ref(false);
const timers = {};
async function save(q, delay = 0) {
    clearTimeout(timers[q.id]);
    timers[q.id] = setTimeout(async () => {
        saving.value++;
        try {
            const res = await window.axios.post(route('exams.attempts.save', props.attempt.id), { question_id: q.id, response: state[q.id], flagged: flags[q.id] });
            failed.value = false;
            if (res.data.seconds_left !== null && res.data.seconds_left !== undefined) secondsLeft.value = res.data.seconds_left;
        } catch (e) {
            if (e.response?.status === 409) return finish();
            failed.value = true;
        } finally {
            saving.value--;
        }
    }, delay);
}
function pair(q, itemId, value) {
    const target = Number(value);
    if (target) state[q.id].pairs[itemId] = target;
    else delete state[q.id].pairs[itemId];
    save(q);
}
function toggleMultiple(q, id) {
    const list = state[q.id].choices;
    const i = list.indexOf(id);
    i >= 0 ? list.splice(i, 1) : list.push(id);
    save(q);
}
function move(q, i, step) {
    const order = state[q.id].order;
    const j = i + step;
    if (j < 0 || j >= order.length) return;
    [order[i], order[j]] = [order[j], order[i]];
    touched[q.id] = true;
    save(q, 300);
}
const choiceText = (q, id) => q.choices.find((c) => c.id === id)?.body ?? '';
const words = (text) => (text.trim() ? text.trim().split(/\s+/).length : 0);

// ---- Navigation: one question at a time ----
const index = ref(0);
const current = computed(() => props.questions[index.value]);
function go(i) {
    index.value = Math.max(0, Math.min(props.questions.length - 1, i));
    window.scrollTo({ top: 0 });
}
function toggleFlag(q) {
    flags[q.id] = !flags[q.id];
    save(q);
}

// ---- Timer (the server's deadline is what counts) ----
const secondsLeft = ref(props.attempt.seconds_left);
let tick;
const clock = computed(() => {
    if (secondsLeft.value === null) return null;
    const s = Math.max(0, secondsLeft.value);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
});

// ---- Submitting ----
const confirmOpen = ref(false);
const submitting = ref(false);
async function finish() {
    if (submitting.value) return;
    submitting.value = true;
    // Let pending saves land first.
    for (const id of Object.keys(timers)) clearTimeout(timers[id]);
    await Promise.allSettled(props.questions.map((q) => window.axios.post(route('exams.attempts.save', props.attempt.id), { question_id: q.id, response: state[q.id], flagged: flags[q.id] }).catch(() => null)));
    router.post(route('exams.attempts.submit', props.attempt.id));
}

// ---- What the browser reports (shown to the examiner) ----
function report(type, meta = null) {
    window.axios.post(route('exams.attempts.event', props.attempt.id), { type, meta }).catch(() => {});
}
const onVisibility = () => document.visibilityState === 'hidden' && report('hidden');
const onBlur = () => report('blur');
const onPaste = () => report('paste');
const onLeave = (e) => { if (saving.value) { e.preventDefault(); e.returnValue = ''; } };

onMounted(() => {
    tick = setInterval(() => {
        if (secondsLeft.value === null) return;
        secondsLeft.value--;
        if (secondsLeft.value <= 0) {
            clearInterval(tick);
            finish();
        }
    }, 1000);
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('blur', onBlur);
    document.addEventListener('paste', onPaste);
    window.addEventListener('beforeunload', onLeave);
});
onBeforeUnmount(() => {
    clearInterval(tick);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('blur', onBlur);
    document.removeEventListener('paste', onPaste);
    window.removeEventListener('beforeunload', onLeave);
});
</script>

<template>
    <Head :title="exam.title" />
    <div class="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100" :dir="exam.dir" :lang="exam.lang">
        <header class="sticky top-0 z-10 flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="min-w-0 flex-1 truncate font-semibold">{{ exam.title }}</h1>
            <span class="hidden text-xs text-gray-500 sm:inline">{{ answeredCount }} / {{ graded.length }} {{ t('exam.answered', 'مُجاب') }}</span>
            <span class="text-xs" :class="failed ? 'text-red-600' : 'text-gray-400'">{{ failed ? t('exam.save_failed', 'لم يُحفظ — تحقّق من الاتصال') : saving ? t('exam.saving', 'يحفظ…') : t('exam.saved', 'محفوظ') }}</span>
            <span v-if="clock" class="flex items-center gap-1 rounded-lg px-2.5 py-1 font-mono text-sm font-semibold" :class="secondsLeft < 300 ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-gray-100 dark:bg-gray-700'" role="timer" aria-live="off">
                <ClockIcon class="h-4 w-4" /> {{ clock }}
            </span>
            <button @click="confirmOpen = true" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)]">{{ t('exam.submit', 'تسليم') }}</button>
        </header>
        <FlashMessages />

        <div class="mx-auto grid max-w-6xl gap-4 p-4 lg:grid-cols-4">
            <!-- Question map -->
            <nav class="order-2 rounded-xl border border-gray-200 bg-white p-3 lg:order-1 dark:border-gray-700 dark:bg-gray-800" :aria-label="t('exam.questions', 'الأسئلة')">
                <div class="grid grid-cols-6 gap-1.5 lg:grid-cols-5">
                    <button v-for="(q, i) in questions" :key="q.id" @click="go(i)"
                        class="relative flex h-9 items-center justify-center rounded-md border text-sm"
                        :class="[i === index ? 'border-[var(--accent-600)] ring-2 ring-[var(--accent-500)]' : 'border-gray-200 dark:border-gray-600',
                                 q.type === 'description' ? 'text-gray-400' : answered(q) ? 'bg-[var(--accent-50)] font-semibold text-[var(--accent-700)] dark:bg-gray-700 dark:text-white' : '']">
                        {{ q.type === 'description' ? 'i' : number(q) }}
                        <FlagIcon v-if="flags[q.id]" class="absolute -top-1 h-3.5 w-3.5 text-amber-500 ltr:-right-1 rtl:-left-1" />
                    </button>
                </div>
            </nav>

            <!-- The question -->
            <main class="order-1 space-y-4 lg:order-2 lg:col-span-3">
                <article v-if="current" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 flex items-center justify-between gap-2 text-xs text-gray-500">
                        <span v-if="current.type !== 'description'">{{ t('exam.question', 'السؤال') }} {{ number(current) }} · {{ current.points }} {{ t('exam.points', 'درجة') }}</span>
                        <span v-else />
                        <button v-if="current.type !== 'description'" @click="toggleFlag(current)" class="flex items-center gap-1" :class="flags[current.id] ? 'text-amber-600' : 'hover:text-gray-700'">
                            <FlagIcon class="h-4 w-4" /> {{ t('exam.flag', 'راجع لاحقاً') }}
                        </button>
                    </div>
                    <p v-if="current.type !== 'cloze'" class="whitespace-pre-line text-base leading-relaxed" dir="auto">{{ current.body }}</p>
                    <!-- Fill in the blanks: the text with its boxes and lists in place -->
                    <p v-else class="whitespace-pre-line text-base leading-loose" dir="auto">
                        <template v-for="(part, k) in current.parts" :key="k">
                            <span v-if="part.blank === undefined">{{ part.text }}</span>
                            <select v-else-if="part.kind === 'select'" v-model="state[current.id].blanks[part.blank]" @change="save(current)"
                                :aria-label="t('exam.blank', 'فراغ') + ' ' + (part.blank + 1)"
                                class="mx-1 inline-block rounded-md border-gray-300 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-700">
                                <option :value="undefined">—</option>
                                <option v-for="o in part.options" :key="o" :value="o">{{ o }}</option>
                            </select>
                            <input v-else v-model="state[current.id].blanks[part.blank]" @input="save(current, 800)" type="text" dir="auto"
                                :aria-label="t('exam.blank', 'فراغ') + ' ' + (part.blank + 1)"
                                class="mx-1 inline-block w-36 rounded-md border-gray-300 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </template>
                    </p>

                    <div class="mt-4">
                        <!-- One answer / true-false -->
                        <fieldset v-if="['single', 'true_false'].includes(current.type)" class="space-y-2">
                            <label v-for="c in current.choices" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                                :class="state[current.id].choice === c.id ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700'">
                                <input type="radio" :name="'q' + current.id" :value="c.id" v-model="state[current.id].choice" @change="save(current)" class="text-[var(--accent-600)]" />
                                <span dir="auto">{{ c.body }}</span>
                            </label>
                        </fieldset>

                        <!-- Several answers -->
                        <fieldset v-else-if="current.type === 'multiple'" class="space-y-2">
                            <p class="text-xs text-gray-500">{{ t('exam.pick_all', 'اختر كل الإجابات الصحيحة.') }}</p>
                            <label v-for="c in current.choices" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                                :class="state[current.id].choices.includes(c.id) ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700'">
                                <input type="checkbox" :checked="state[current.id].choices.includes(c.id)" @change="toggleMultiple(current, c.id)" class="rounded text-[var(--accent-600)]" />
                                <span dir="auto">{{ c.body }}</span>
                            </label>
                        </fieldset>

                        <input v-else-if="current.type === 'short'" v-model="state[current.id].text" @input="save(current, 800)" type="text" dir="auto"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" :placeholder="t('exam.your_answer', 'إجابتك')" />

                        <input v-else-if="current.type === 'numeric'" v-model="state[current.id].value" @input="save(current, 800)" type="text" inputmode="decimal" dir="ltr"
                            class="w-48 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" :placeholder="t('exam.number', 'رقم')" />

                        <div v-else-if="current.type === 'essay'">
                            <textarea v-model="state[current.id].text" @input="save(current, 1500)" rows="10" dir="auto" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" />
                            <p class="mt-1 text-xs text-gray-500">
                                {{ words(state[current.id].text) }} {{ t('exam.words', 'كلمة') }}
                                <span v-if="current.min_words"> · {{ t('exam.min', 'الأدنى') }} {{ current.min_words }}</span>
                                <span v-if="current.max_words"> · {{ t('exam.max', 'الأقصى') }} {{ current.max_words }}</span>
                            </p>
                        </div>

                        <!-- Matching: a list per item -->
                        <div v-else-if="current.type === 'matching'" class="space-y-2">
                            <div v-for="item in current.items" :key="item.id" class="grid items-center gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-2 dark:border-gray-600">
                                <span class="text-sm" dir="auto">{{ item.body }}</span>
                                <select :value="state[current.id].pairs[item.id] ?? ''" @change="pair(current, item.id, $event.target.value)"
                                    class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                    <option value="">{{ t('exam.choose', '— اختر —') }}</option>
                                    <option v-for="target in current.targets" :key="target.id" :value="target.id">{{ target.body }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Ordering: move items up and down (keyboard and screen readers too) -->
                        <ol v-else-if="current.type === 'ordering'" class="space-y-2">
                            <li v-for="(id, i) in state[current.id].order" :key="id" class="flex items-center gap-2 rounded-lg border border-gray-200 p-2.5 text-sm dark:border-gray-600">
                                <span class="w-6 text-center text-xs text-gray-400">{{ i + 1 }}</span>
                                <span class="flex-1" dir="auto">{{ choiceText(current, id) }}</span>
                                <button @click="move(current, i, -1)" :disabled="i === 0" class="rounded p-1 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :aria-label="t('exam.move_up', 'إلى الأعلى')"><ArrowUpIcon class="h-4 w-4" /></button>
                                <button @click="move(current, i, 1)" :disabled="i === state[current.id].order.length - 1" class="rounded p-1 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :aria-label="t('exam.move_down', 'إلى الأسفل')"><ArrowDownIcon class="h-4 w-4" /></button>
                            </li>
                        </ol>
                    </div>
                </article>

                <div class="flex justify-between">
                    <button @click="go(index - 1)" :disabled="index === 0" class="flex items-center gap-1 rounded-lg border border-gray-300 px-4 py-2 text-sm disabled:opacity-40 dark:border-gray-600">
                        <ChevronRightIcon class="h-4 w-4 ltr:rotate-180" /> {{ t('exam.previous', 'السابق') }}
                    </button>
                    <button v-if="index < questions.length - 1" @click="go(index + 1)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">
                        {{ t('exam.next', 'التالي') }} <ChevronLeftIcon class="h-4 w-4 ltr:rotate-180" />
                    </button>
                    <button v-else @click="confirmOpen = true" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white">
                        <CheckIcon class="h-4 w-4" /> {{ t('exam.submit', 'تسليم') }}
                    </button>
                </div>
            </main>
        </div>

        <Modal :show="confirmOpen" @close="confirmOpen = false" max-width="md">
            <div class="space-y-4 p-6" :dir="exam.dir">
                <h3 class="text-lg font-semibold">{{ t('exam.confirm_submit', 'تسليم الامتحان؟') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ t('exam.answered_of', 'أجبت عن :a من :n.', { a: answeredCount, n: graded.length }) }}
                    <span v-if="answeredCount < graded.length" class="font-medium text-amber-600">{{ t('exam.unanswered_warn', 'بقيت أسئلة بلا إجابة.') }}</span>
                    {{ t('exam.no_changes_after', 'لا يمكن تعديل الإجابات بعد التسليم.') }}
                </p>
                <div class="flex justify-end gap-2">
                    <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="finish" :disabled="submitting" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('exam.submit', 'تسليم') }}</button>
                </div>
            </div>
        </Modal>
    </div>
</template>
