<script setup>
// Camera QR reader for the map's «امسح الملصق» button. Uses the browser's BarcodeDetector where
// it exists (Android Chrome) and jsQR everywhere else (iPhone Safari).
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { XMarkIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const emit = defineEmits(['result', 'close', 'where']);
const video = ref(null);
const error = ref('');
let stream = null;
let timer = null;
let stopped = false;

onMounted(async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        video.value.srcObject = stream;
        await video.value.play();
    } catch {
        error.value = t('library.map.camera_error', 'تعذّر فتح الكاميرا. اسمح للموقع باستخدامها، أو امسح الملصق بكاميرا الهاتف مباشرة.');
        return;
    }
    let detect;
    if ('BarcodeDetector' in window) {
        try {
            const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            detect = async () => (await detector.detect(video.value))[0]?.rawValue ?? null;
        } catch { detect = null; }
    }
    if (!detect) {
        const { default: jsQR } = await import('jsqr');
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        detect = async () => {
            const v = video.value;
            if (!v?.videoWidth) return null;
            const scale = Math.min(1, 640 / v.videoWidth);
            canvas.width = v.videoWidth * scale;
            canvas.height = v.videoHeight * scale;
            ctx.drawImage(v, 0, 0, canvas.width, canvas.height);
            const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            return jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' })?.data ?? null;
        };
    }
    const loop = async () => {
        if (stopped) return;
        try {
            const text = await detect();
            if (text) { emit('result', text); return; }
        } catch {}
        timer = setTimeout(loop, 180);
    };
    loop();
});

onBeforeUnmount(() => {
    stopped = true;
    clearTimeout(timer);
    stream?.getTracks().forEach((tr) => tr.stop());
});
</script>

<template>
    <div class="fixed inset-0 z-50 flex flex-col bg-black/90 text-white">
        <div class="flex items-center gap-3 p-4">
            <p class="flex-1 text-sm font-medium">{{ t('library.map.scan_title_any', 'وجّه الكاميرا إلى ملصق QR على أقرب رف أو جدار') }}</p>
            <button @click="emit('close')" class="rounded-full bg-white/15 p-2"><XMarkIcon class="h-6 w-6" /></button>
        </div>
        <div class="relative flex flex-1 items-center justify-center overflow-hidden">
            <video ref="video" playsinline muted class="h-full w-full object-cover" />
            <div class="pointer-events-none absolute h-60 w-60 rounded-3xl border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" />
            <p v-if="error" class="absolute inset-x-6 rounded-xl bg-black/80 p-4 text-center text-sm">{{ error }}</p>
        </div>
        <!-- «لا أجد ملصقاً»: back to the map, with the nearest stickers lit up. -->
        <div class="p-4 text-center">
            <button @click="emit('where')" class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-gray-900">{{ t('library.map.where_stickers', 'لا أجد ملصقاً؟ أرني أقربها') }}</button>
        </div>
    </div>
</template>
