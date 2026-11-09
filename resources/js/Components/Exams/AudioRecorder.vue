<script setup>
import { useTrans } from '@/Composables/useTrans';
import { MicrophoneIcon, StopIcon, CheckIcon } from '@heroicons/vue/24/outline';
import { ref, onBeforeUnmount } from 'vue';
const { t } = useTrans();

// A spoken answer: record from the microphone (up to the question's limit), listen, send.
const props = defineProps({ maxSeconds: { type: Number, default: 120 }, saved: String, disabled: Boolean });
const emit = defineEmits(['recorded']);
const state = ref('idle');   // idle | recording | ready
const seconds = ref(0);
const url = ref(null);
const error = ref('');
let recorder, chunks = [], timer, stream;

async function start() {
    error.value = '';
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        error.value = t('exam.mic_denied', 'لا وصول إلى المايكروفون. اسمح للمتصفح باستعماله أو اطلب المساعدة.');
        return;
    }
    chunks = [];
    recorder = new MediaRecorder(stream);
    recorder.ondataavailable = (e) => chunks.push(e.data);
    recorder.onstop = () => {
        const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
        url.value = URL.createObjectURL(blob);
        state.value = 'ready';
        stream.getTracks().forEach((t) => t.stop());
        const ext = (recorder.mimeType || '').includes('ogg') ? 'ogg' : (recorder.mimeType || '').includes('mp4') ? 'm4a' : 'webm';
        emit('recorded', new File([blob], `answer.${ext}`, { type: blob.type }));
    };
    recorder.start();
    state.value = 'recording';
    seconds.value = 0;
    timer = setInterval(() => { if (++seconds.value >= props.maxSeconds) stop(); }, 1000);
}
function stop() {
    clearInterval(timer);
    if (recorder?.state === 'recording') recorder.stop();
}
onBeforeUnmount(() => { stop(); stream?.getTracks().forEach((t) => t.stop()); });
const clock = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
</script>

<template>
    <div class="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-600">
        <div class="flex flex-wrap items-center gap-3">
            <button v-if="state !== 'recording'" type="button" @click="start" :disabled="disabled" class="flex items-center gap-2 rounded-full bg-red-600 px-4 py-2 text-sm text-white disabled:opacity-40">
                <MicrophoneIcon class="h-4 w-4" /> {{ state === 'ready' || saved ? t('exam.rec_again', 'تسجيل من جديد') : t('exam.rec_start', 'ابدأ التسجيل') }}
            </button>
            <button v-else type="button" @click="stop" class="rounded-full bg-gray-800 px-4 py-2 text-sm flex items-center gap-2 text-white"><StopIcon class="h-4 w-4" /> {{ t('exam.rec_stop', 'إيقاف') }}</button>
            <span class="font-mono text-sm" :class="state === 'recording' ? 'text-red-600' : 'text-gray-500'">{{ clock(seconds) }} / {{ clock(maxSeconds) }}</span>
        </div>
        <audio v-if="url" :src="url" controls class="w-full" />
        <p v-else-if="saved" class="text-sm text-emerald-700 dark:text-emerald-400"><CheckIcon class="inline h-4 w-4 align-text-bottom" /> {{ saved }}</p>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>
