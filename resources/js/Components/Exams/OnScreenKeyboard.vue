<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { BackspaceIcon, XMarkIcon, ArrowUpIcon, ArrowTurnDownLeftIcon } from '@heroicons/vue/24/outline';

// An Arabic / English keyboard on the screen, for hall computers without Arabic key labels.
// It types into the answer field last used, through the browser's own typing, so the answer
// is saved as if typed on the real keyboard.
const emit = defineEmits(['close']);
const { t } = useTrans();
const LAYOUTS = {
    ar: [['ض', 'ص', 'ث', 'ق', 'ف', 'غ', 'ع', 'ه', 'خ', 'ح', 'ج', 'د'], ['ش', 'س', 'ي', 'ب', 'ل', 'ا', 'ت', 'ن', 'م', 'ك', 'ط', 'ذ'],
        ['ئ', 'ء', 'ؤ', 'ر', 'ى', 'ة', 'و', 'ز', 'ظ', 'أ', 'إ', 'آ'], ['١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '٠', '،', '؟']],
    en: [['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'], ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'], ['z', 'x', 'c', 'v', 'b', 'n', 'm', ',', '.', '?'],
        ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '-', '+']],
};
const lang = ref(document.documentElement.lang === 'en' ? 'en' : 'ar');
const shift = ref(false);
let target = null;
const isField = (el) => el && ((el.tagName === 'TEXTAREA') || (el.tagName === 'INPUT' && ['text', 'search', ''].includes(el.type)) || el.isContentEditable);
const onFocus = (e) => { if (isField(e.target) && !e.target.closest('[data-osk]')) target = e.target; };
onMounted(() => {
    document.addEventListener('focusin', onFocus);
    if (isField(document.activeElement)) target = document.activeElement;
});
onBeforeUnmount(() => document.removeEventListener('focusin', onFocus));

function type(text) {
    if (!target || !document.contains(target)) return;
    target.focus();
    // insertText goes through the browser's typing: v-model and autosave see it.
    if (!document.execCommand('insertText', false, text) && 'value' in target) {
        const [s, e] = [target.selectionStart ?? target.value.length, target.selectionEnd ?? target.value.length];
        target.value = target.value.slice(0, s) + text + target.value.slice(e);
        target.selectionStart = target.selectionEnd = s + text.length;
        target.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
function key(k) {
    type(lang.value === 'en' && shift.value ? k.toUpperCase() : k);
    if (shift.value) shift.value = false;
}
function backspace() {
    if (!target) return;
    target.focus();
    if (!document.execCommand('delete') && 'value' in target) {
        const s = target.selectionStart ?? target.value.length;
        const e = target.selectionEnd ?? s;
        const from = s === e ? Math.max(0, s - 1) : s;
        target.value = target.value.slice(0, from) + target.value.slice(e);
        target.selectionStart = target.selectionEnd = from;
        target.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
const K = 'h-10 min-w-[2.4rem] flex-1 rounded-lg border border-gray-300 bg-white text-base shadow-sm hover:bg-gray-50 active:bg-gray-200 dark:border-gray-600 dark:bg-gray-700';
</script>

<template>
    <div data-osk class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-300 bg-gray-100 p-2 shadow-2xl print:hidden dark:border-gray-600 dark:bg-gray-800" @mousedown.prevent>
        <div class="mx-auto max-w-3xl space-y-1.5" :dir="lang === 'ar' ? 'rtl' : 'ltr'">
            <div v-for="(row, r) in LAYOUTS[lang]" :key="lang + r" class="flex gap-1">
                <button v-for="k in row" :key="k" type="button" :class="K" @click="key(k)">{{ lang === 'en' && shift ? k.toUpperCase() : k }}</button>
            </div>
            <div class="flex gap-1">
                <button v-if="lang === 'en'" type="button" :class="[K, shift ? '!bg-[var(--accent-600)] text-white' : '']" class="!flex-none px-3" @click="shift = !shift" :aria-label="t('exam.osk_shift', 'أحرف كبيرة')"><ArrowUpIcon class="mx-auto h-5 w-5" /></button>
                <button type="button" :class="K" class="!flex-none px-3 text-sm" @click="lang = lang === 'ar' ? 'en' : 'ar'">{{ lang === 'ar' ? 'English' : 'عربي' }}</button>
                <button type="button" :class="K" class="!flex-[5]" @click="type(' ')">{{ t('exam.osk_space', 'مسافة') }}</button>
                <button type="button" :class="K" class="!flex-none px-3" @click="type('\n')" :aria-label="t('exam.osk_enter', 'سطر جديد')"><ArrowTurnDownLeftIcon class="mx-auto h-5 w-5" /></button>
                <button type="button" :class="K" class="!flex-none px-3" @click="backspace" :aria-label="t('exam.osk_delete', 'حذف')"><BackspaceIcon class="mx-auto h-5 w-5" /></button>
                <button type="button" :class="K" class="!flex-none px-3" @click="emit('close')" :aria-label="t('action.close', 'إغلاق')"><XMarkIcon class="mx-auto h-5 w-5" /></button>
            </div>
        </div>
    </div>
</template>
