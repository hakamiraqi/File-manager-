<script setup>
// Draw a signature once (finger, pen or mouse); it is kept as pen strokes on a 600×240 board
// so it stays sharp at any size when placed on a page.
import { ref } from 'vue';
import { useTrans } from '@/Composables/useTrans';

const props = defineProps({ initial: { type: Array, default: null } });
const emit = defineEmits(['save', 'cancel', 'forget']);
const { t } = useTrans();

const strokes = ref(props.initial ? JSON.parse(JSON.stringify(props.initial)) : []);
const live = ref(null);
const board = ref(null);

function point(e) {
    const r = board.value.getBoundingClientRect();
    return [Math.round(((e.clientX - r.left) / r.width) * 600), Math.round(((e.clientY - r.top) / r.height) * 240)];
}
function down(e) {
    board.value.setPointerCapture(e.pointerId);
    live.value = [point(e)];
}
function move(e) {
    if (!live.value) return;
    const p = point(e);
    const last = live.value[live.value.length - 1];
    if (Math.abs(p[0] - last[0]) + Math.abs(p[1] - last[1]) >= 2) live.value.push(p);
}
function up() {
    if (live.value && live.value.length > 1) strokes.value.push(live.value);
    live.value = null;
}
const path = (s) => 'M' + s.map((p) => p.join(' ')).join(' L');
</script>

<template>
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4" @click.self="emit('cancel')">
        <div class="w-full max-w-lg rounded-2xl bg-white p-4 shadow-xl dark:bg-gray-800">
            <h3 class="mb-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ t('reader.sign_title', 'توقيعك') }}</h3>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ t('reader.sign_hint', 'ارسم توقيعك مرة واحدة؛ يُحفظ في حسابك وتضعه على أي صفحة بلمسة.') }}</p>
            <svg
                ref="board"
                viewBox="0 0 600 240"
                class="w-full touch-none rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-white"
                style="aspect-ratio: 600 / 240"
                @pointerdown.prevent="down"
                @pointermove.prevent="move"
                @pointerup="up"
                @pointercancel="up"
            >
                <line x1="40" y1="190" x2="560" y2="190" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="6 6" />
                <path v-for="(s, i) in strokes" :key="i" :d="path(s)" fill="none" stroke="#1e3a8a" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                <path v-if="live && live.length > 1" :d="path(live)" fill="none" stroke="#1e3a8a" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" @click="strokes = []">
                    {{ t('reader.sign_clear', 'مسح') }}
                </button>
                <button v-if="initial" type="button" class="rounded-lg px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" @click="emit('forget')">
                    {{ t('reader.sign_forget', 'حذف التوقيع المحفوظ') }}
                </button>
                <span class="flex-1" />
                <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" @click="emit('cancel')">
                    {{ t('common.cancel', 'إلغاء') }}
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-sm font-semibold text-white disabled:opacity-40"
                    :disabled="!strokes.length"
                    @click="emit('save', strokes)"
                >
                    {{ t('reader.sign_save', 'حفظ التوقيع') }}
                </button>
            </div>
        </div>
    </div>
</template>
