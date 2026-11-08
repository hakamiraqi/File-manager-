<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import { MagnifyingGlassMinusIcon, MagnifyingGlassPlusIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';

// Square crop of a chosen picture: drag to move, slider or wheel to zoom.
// Emits a 512px JPEG blob.
const props = defineProps({
    file: { type: File, default: null },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'cropped']);
const { t } = useTrans();

const VIEW = 280;
const OUT = 512;
const src = ref(null);
const img = ref(null);
const natural = ref({ w: 1, h: 1 });
const zoom = ref(1);
const offset = ref({ x: 0, y: 0 });
let drag = null;

const baseScale = computed(() => VIEW / Math.min(natural.value.w, natural.value.h));
const scale = computed(() => baseScale.value * zoom.value);
const shown = computed(() => ({ w: natural.value.w * scale.value, h: natural.value.h * scale.value }));

watch(() => props.file, (f) => {
    if (src.value) URL.revokeObjectURL(src.value);
    src.value = f ? URL.createObjectURL(f) : null;
    zoom.value = 1;
    offset.value = { x: 0, y: 0 };
}, { immediate: true });

onBeforeUnmount(() => { if (src.value) URL.revokeObjectURL(src.value); });

function loaded(e) {
    natural.value = { w: e.target.naturalWidth, h: e.target.naturalHeight };
    clamp();
}

// Keep the picture covering the whole circle.
function clamp() {
    const maxX = Math.max(0, (shown.value.w - VIEW) / 2);
    const maxY = Math.max(0, (shown.value.h - VIEW) / 2);
    offset.value = {
        x: Math.min(maxX, Math.max(-maxX, offset.value.x)),
        y: Math.min(maxY, Math.max(-maxY, offset.value.y)),
    };
}
watch(zoom, clamp);

function down(e) {
    drag = { x: e.clientX, y: e.clientY, ox: offset.value.x, oy: offset.value.y };
    e.target.setPointerCapture?.(e.pointerId);
}
function move(e) {
    if (!drag) return;
    offset.value = { x: drag.ox + e.clientX - drag.x, y: drag.oy + e.clientY - drag.y };
    clamp();
}
function up() { drag = null; }
function wheel(e) {
    zoom.value = Math.min(4, Math.max(1, zoom.value - e.deltaY * 0.002));
}
function reset() {
    zoom.value = 1;
    offset.value = { x: 0, y: 0 };
}

function save() {
    const canvas = document.createElement('canvas');
    canvas.width = OUT;
    canvas.height = OUT;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, OUT, OUT);
    // Part of the original picture that is inside the circle's square.
    const side = VIEW / scale.value;
    const sx = natural.value.w / 2 - (VIEW / 2 + offset.value.x) / scale.value;
    const sy = natural.value.h / 2 - (VIEW / 2 + offset.value.y) / scale.value;
    ctx.drawImage(img.value, sx, sy, side, side, 0, 0, OUT, OUT);
    canvas.toBlob((blob) => blob && emit('cropped', blob), 'image/jpeg', 0.9);
}
</script>

<template>
    <Modal :show="!!file" max-width="md" @close="emit('close')">
        <div class="p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ t('profile.avatar_crop', 'ضبط الصورة الشخصية') }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ t('profile.avatar_crop_hint', 'اسحب الصورة لتحريكها، واستخدم الشريط للتكبير.') }}</p>

            <div class="mt-5 flex justify-center">
                <div
                    class="relative touch-none select-none overflow-hidden rounded-2xl bg-gray-900"
                    :style="{ width: VIEW + 'px', height: VIEW + 'px' }"
                    @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @wheel.prevent="wheel"
                >
                    <img
                        v-if="src" ref="img" :src="src" alt="" draggable="false" @load="loaded"
                        class="pointer-events-none absolute left-1/2 top-1/2 max-w-none cursor-move"
                        :style="{ width: shown.w + 'px', height: shown.h + 'px', transform: `translate(calc(-50% + ${offset.x}px), calc(-50% + ${offset.y}px))` }"
                    />
                    <!-- Circle guide: everything outside it is dimmed. -->
                    <div class="pointer-events-none absolute inset-0 rounded-full ring-[999px] ring-black/55" />
                    <div class="pointer-events-none absolute inset-0 rounded-full border-2 border-white/80" />
                </div>
            </div>

            <div class="mx-auto mt-5 flex max-w-xs items-center gap-3">
                <MagnifyingGlassMinusIcon class="h-5 w-5 text-gray-400" />
                <input v-model.number="zoom" type="range" min="1" max="4" step="0.01" class="w-full accent-[var(--accent-600)]" :aria-label="t('profile.avatar_zoom', 'التكبير')" />
                <MagnifyingGlassPlusIcon class="h-5 w-5 text-gray-400" />
                <button type="button" class="rounded-md p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" :title="t('profile.avatar_reset', 'إعادة الضبط')" @click="reset">
                    <ArrowPathIcon class="h-5 w-5" />
                </button>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" @click="emit('close')">
                    {{ t('action.cancel', 'إلغاء') }}
                </button>
                <button type="button" :disabled="busy || !src" class="rounded-lg bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white hover:bg-[var(--accent-700)] disabled:opacity-50" @click="save">
                    {{ busy ? t('profile.avatar_saving', 'جارٍ الحفظ…') : t('profile.avatar_use', 'استخدام الصورة') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
