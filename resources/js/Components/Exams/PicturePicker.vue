<script setup>
// «صورة» for a question, a choice, an item or a label (2026-11-09, by request): upload, see it
// small, replace or remove it. The picture is kept with the exam's other pictures.
import { ref } from 'vue';
import { PhotoIcon, XMarkIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const props = defineProps({
    image: { type: String, default: null },
    url: { type: String, default: null },
    compact: { type: Boolean, default: false }, // a small icon beside a choice
});
const emit = defineEmits(['change']);
const input = ref(null);
const busy = ref(false);
const error = ref('');

async function pick(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    error.value = '';
    if (!file.type.startsWith('image/')) { error.value = t('exam.picture_only', 'اختر صورة (JPG أو PNG أو WEBP أو GIF).'); return; }
    busy.value = true;
    const data = new FormData();
    data.append('image', file);
    try {
        const res = await window.axios.post(route('exams.manage.images.store'), data);
        emit('change', { image: res.data.image, url: res.data.url });
    } catch (e) {
        error.value = e.response?.data?.errors?.image?.[0] ?? t('exam.upload_failed', 'تعذّر رفع الملف.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div :class="compact ? 'flex shrink-0 items-start gap-1' : 'space-y-1'">
        <input ref="input" type="file" accept="image/*" class="hidden" @change="pick" />
        <div v-if="url" class="relative inline-block">
            <img :src="url" alt="" :class="compact ? 'h-10 w-14 rounded-md object-cover ring-1 ring-gray-300 dark:ring-gray-600' : 'block max-h-48 max-w-full rounded-lg ring-1 ring-gray-200 dark:ring-gray-600'" />
            <div class="absolute -end-2 -top-2 flex gap-0.5">
                <button type="button" @click="input.click()" class="rounded-full bg-white p-0.5 text-gray-600 shadow ring-1 ring-gray-200 hover:text-gray-900 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600" :title="t('exam.replace_image', 'تغيير الصورة')"><ArrowPathIcon class="h-3.5 w-3.5" :class="busy ? 'animate-spin' : ''" /></button>
                <button type="button" @click="emit('change', { image: null, url: null })" class="rounded-full bg-white p-0.5 text-red-600 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-600" :title="t('exam.remove_image', 'إزالة الصورة')"><XMarkIcon class="h-3.5 w-3.5" /></button>
            </div>
        </div>
        <button v-else type="button" @click="input.click()" :disabled="busy"
            :class="compact ? 'mt-1 rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-[var(--accent-600)] dark:hover:bg-gray-700' : 'inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:border-[var(--accent-500)] hover:text-[var(--accent-700)] dark:border-gray-600 dark:text-gray-300'"
            :title="t('exam.add_picture', 'إضافة صورة')">
            <ArrowPathIcon v-if="busy" class="h-4 w-4 animate-spin" /><PhotoIcon v-else class="h-4 w-4" />
            <span v-if="!compact">{{ busy ? t('exam.uploading', 'يرفع…') : t('exam.add_picture_q', 'صورة للسؤال') }}</span>
        </button>
        <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
    </div>
</template>
