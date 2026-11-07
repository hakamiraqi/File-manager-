<script setup>
// Vue wrapper round the three.js room. The scene itself lives outside Vue's reactivity.
import { onBeforeUnmount, onMounted, ref, watch, markRaw } from 'vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();

const props = defineProps({
    floor: { type: Object, required: true },
    shelves: { type: Array, required: true },
    highlight: { type: Object, default: null }, // { id, level }
    route: { type: Object, default: null },
    avatar: { type: Object, default: null }, // { x, y, heading }
    follow: { type: Boolean, default: false },
    beacons: { type: Object, default: null }, // { ids: [], nearest } stickers to make pulse
    // Map editor: drag furniture on the floor (canDrag(id) says if it may move) and right click.
    editable: { type: Boolean, default: false },
    canDrag: { type: Function, default: null },
});
const emit = defineEmits(['pick', 'ready', 'failed', 'move', 'context', 'dragging']);

const el = ref(null);
const failed = ref(false);
let scene = null;

const isDark = () => document.documentElement.classList.contains('dark');
let themeObserver = null;
const plain = (v) => JSON.parse(JSON.stringify(v));

onMounted(async () => {
    try {
        const { LibraryScene } = await import('./LibraryScene.js');
        try { await Promise.race([document.fonts?.ready, new Promise((r) => setTimeout(r, 1500))]); } catch {}
        if (!el.value) return;
        scene = markRaw(new LibraryScene(el.value, {
            dark: isDark(),
            onPick: (id) => emit('pick', id),
            ...(props.editable ? {
                onMove: (id, x, y) => emit('move', id, x, y),
                onContext: (id, cx, cy) => emit('context', id, cx, cy),
                canDrag: (id) => (props.canDrag ? props.canDrag(id) : true),
            } : {}),
        }));
        if (props.editable) scene.onDragTip = (id, x, y, cx, cy) => emit('dragging', id === null ? null : { id, x, y, cx, cy });
        scene.setLayout(plain(props.floor), plain(props.shelves));
        apply();
        themeObserver = new MutationObserver(() => scene?.dark !== isDark() && scene?.applyTheme(isDark()));
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        emit('ready', api);
    } catch (e) {
        console.error(e);
        failed.value = true;
        emit('failed');
    }
});

function apply() {
    if (!scene) return;
    scene.highlight(props.highlight?.id ?? null, props.highlight?.level ?? null);
    scene.setRoute(props.route ? plain(props.route) : null);
    scene.setAvatar(props.avatar);
    props.follow ? scene.follow() : scene.mode === 'follow' && scene.overview();
    scene.setBeacons(props.beacons?.ids ?? null, props.beacons?.nearest ?? null);
}

watch(() => [props.floor, props.shelves], () => scene?.setLayout(plain(props.floor), plain(props.shelves)), { deep: true });
watch(() => [props.highlight, props.route], () => {
    if (!scene) return;
    scene.highlight(props.highlight?.id ?? null, props.highlight?.level ?? null);
    scene.setRoute(props.route ? plain(props.route) : null);
}, { deep: true });
watch(() => props.avatar, (a) => scene?.setAvatar(a));
watch(() => props.beacons, (b) => scene?.setBeacons(b?.ids ?? null, b?.nearest ?? null), { deep: true });
watch(() => props.follow, (f) => (f ? scene?.follow() : scene?.overview()));

const api = {
    overview: () => scene?.overview(),
    focusShelf: (id) => scene?.focusShelf(id),
};
defineExpose(api);

onBeforeUnmount(() => {
    themeObserver?.disconnect();
    scene?.dispose();
    scene = null;
});
</script>

<template>
    <div class="relative h-full w-full overflow-hidden">
        <div ref="el" class="absolute inset-0" />
        <div v-if="failed" class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-gray-500">
            {{ t('library.map.no_webgl', 'جهازك أو متصفحك لا يدعم العرض ثلاثي الأبعاد (WebGL).') }}
        </div>
    </div>
</template>
