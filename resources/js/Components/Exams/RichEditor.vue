<script setup>
import { useTrans } from '@/Composables/useTrans';
import { ListBulletIcon, NumberedListIcon, TableCellsIcon, Bars3BottomRightIcon, Bars3Icon, Bars3BottomLeftIcon, VariableIcon, PaperClipIcon } from '@heroicons/vue/24/outline';
import { ref, onMounted, watch } from 'vue';
import RichText from '@/Components/Exams/RichText.vue';
const { t } = useTrans();

// A small editor for formatted question text, with no outside service: bold, italic, underline,
// sub/superscript, lists, a table, alignment and direction, pictures, sound and video from this
// server, and equations ($…$ drawn by KaTeX). The server cleans what it receives.
const props = defineProps({ modelValue: { type: String, default: '' }, dir: { type: String, default: 'rtl' }, lang: String, placeholder: String });
const emit = defineEmits(['update:modelValue']);
const box = ref(null);
const uploading = ref(false);
const error = ref('');
const preview = ref(false);

onMounted(() => { box.value.innerHTML = props.modelValue || ''; });
watch(() => props.modelValue, (v) => { if (box.value && v !== box.value.innerHTML) box.value.innerHTML = v || ''; });
const changed = () => emit('update:modelValue', box.value.innerHTML);

function cmd(name, value = null) {
    box.value.focus();
    document.execCommand(name, false, value);
    changed();
}
function insertHtml(html) {
    box.value.focus();
    document.execCommand('insertHTML', false, html);
    changed();
}
function table() {
    const rows = Math.max(1, Math.min(20, Number(prompt(t('exam.rt_rows', 'عدد الصفوف'), '3')) || 0));
    const cols = Math.max(1, Math.min(10, Number(prompt(t('exam.rt_cols', 'عدد الأعمدة'), '3')) || 0));
    if (!rows || !cols) return;
    const head = '<tr>' + '<th>&nbsp;</th>'.repeat(cols) + '</tr>';
    const body = ('<tr>' + '<td>&nbsp;</td>'.repeat(cols) + '</tr>').repeat(rows - 1);
    insertHtml(`<table><thead>${head}</thead><tbody>${body}</tbody></table><p><br></p>`);
}
function equation() {
    const tex = prompt(t('exam.rt_eq_prompt', 'المعادلة بصيغة LaTeX، مثل: \\frac{a}{b} أو x^2 + \\sqrt{y}'));
    if (tex) insertHtml(` $${tex.replace(/[<>&]/g, '')}$ `);
}
const fileInput = ref(null);
async function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    uploading.value = true;
    error.value = '';
    const data = new FormData();
    data.append('image', file);
    try {
        const res = await window.axios.post(route('exams.manage.images.store'), data);
        const src = new URL(res.data.url, location.href).pathname;
        const kind = file.type.split('/')[0];
        insertHtml(kind === 'audio' ? `<audio src="${src}" controls></audio><p><br></p>` : kind === 'video' ? `<video src="${src}" controls></video><p><br></p>` : `<img src="${src}" alt="">`);
    } catch (e) {
        error.value = e.response?.data?.errors?.image?.[0] ?? 'تعذّر رفع الملف.';
    } finally {
        uploading.value = false;
    }
}
const B = 'rounded px-1.5 py-0.5 text-xs hover:bg-gray-200 dark:hover:bg-gray-600';
</script>

<template>
    <div class="rounded-lg border border-gray-300 dark:border-gray-600">
        <div class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-1 py-1 dark:border-gray-600 dark:bg-gray-700/50" dir="ltr">
            <button type="button" :class="B" class="font-bold" @mousedown.prevent="cmd('bold')" :title="t('exam.rt_bold', 'غامق')">B</button>
            <button type="button" :class="B" class="italic" @mousedown.prevent="cmd('italic')" :title="t('exam.rt_italic', 'مائل')">I</button>
            <button type="button" :class="B" class="underline" @mousedown.prevent="cmd('underline')" :title="t('exam.rt_underline', 'تسطير')">U</button>
            <button type="button" :class="B" class="line-through" @mousedown.prevent="cmd('strikeThrough')" :title="t('exam.rt_strike', 'شطب')">S</button>
            <button type="button" :class="B" @mousedown.prevent="cmd('subscript')" :title="t('exam.rt_sub', 'منخفض')">x₂</button>
            <button type="button" :class="B" @mousedown.prevent="cmd('superscript')" :title="t('exam.rt_sup', 'مرتفع')">x²</button>
            <span class="mx-1 h-4 w-px bg-gray-300 dark:bg-gray-600" />
            <button type="button" :class="B" @mousedown.prevent="cmd('insertUnorderedList')" :title="t('exam.rt_ul', 'قائمة نقطية')"><ListBulletIcon class="h-4 w-4" /></button>
            <button type="button" :class="B" @mousedown.prevent="cmd('insertOrderedList')" :title="t('exam.rt_ol', 'قائمة مرقّمة')"><NumberedListIcon class="h-4 w-4" /></button>
            <button type="button" :class="B" @mousedown.prevent="table" :title="t('exam.rt_table', 'جدول')"><TableCellsIcon class="h-4 w-4" /></button>
            <span class="mx-1 h-4 w-px bg-gray-300 dark:bg-gray-600" />
            <button type="button" :class="B" @mousedown.prevent="cmd('justifyRight')" :title="t('exam.rt_right', 'يمين')"><Bars3BottomRightIcon class="h-4 w-4" /></button>
            <button type="button" :class="B" @mousedown.prevent="cmd('justifyCenter')" :title="t('exam.rt_center', 'وسط')"><Bars3Icon class="h-4 w-4" /></button>
            <button type="button" :class="B" @mousedown.prevent="cmd('justifyLeft')" :title="t('exam.rt_left', 'يسار')"><Bars3BottomLeftIcon class="h-4 w-4" /></button>
            <span class="mx-1 h-4 w-px bg-gray-300 dark:bg-gray-600" />
            <button type="button" :class="B" class="font-serif" @mousedown.prevent="equation" :title="t('exam.rt_eq', 'معادلة')"><VariableIcon class="h-4 w-4" /></button>
            <button type="button" :class="B" @mousedown.prevent="fileInput.click()" :disabled="uploading" :title="t('exam.rt_media', 'صورة أو صوت أو فيديو')"><span v-if="uploading">…</span><PaperClipIcon v-else class="h-4 w-4" /></button>
            <input ref="fileInput" type="file" accept="image/*,audio/*,video/mp4,video/webm" class="hidden" @change="upload" />
            <span class="flex-1" />
            <button type="button" :class="B" @click="preview = !preview">{{ preview ? 'تحرير' : 'معاينة' }}</button>
        </div>
        <div v-show="!preview" ref="box" contenteditable="true" :dir="dir" :lang="lang" :data-placeholder="placeholder" @input="changed" @blur="changed"
            class="rich-text min-h-[4.5rem] px-3 py-2 text-sm outline-none empty:before:text-gray-400 empty:before:content-[attr(data-placeholder)] dark:text-gray-100" />
        <RichText v-if="preview" :text="modelValue" html :dir="dir" class="min-h-[4.5rem] px-3 py-2 text-sm" />
        <p v-if="error" class="px-3 pb-2 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
