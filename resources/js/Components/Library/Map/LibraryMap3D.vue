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
});
const emit = defineEmits(['pick', 'ready']);

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
        scene = markRaw(new LibraryScene(el.value, { dark: isDark(), onPick: (id) => emit('pick', id) }));
        scene.setLayout(plain(props.floor), plain(props.shelves));
        apply();
        themeObserver = new MutationObserver(() => scene?.dark !== isDark() && scene?.applyTheme(isDark()));
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        emit('ready', api);
    } catch (e) {
        console.error(e);
        failed.value = true;
    }
});

function apply() {
    if (!scene) return;
    scene.highlight(props.highlight?.id ?? null, props.highlight?.level ?? null);
    scene.setRoute(props.route ? plain(props.route) : null);
    scene.setAvatar(props.avatar);
    props.follow ? scene.follow() : scene.mode === 'follow' && scene.overview();
}

watch(() => [props.floor, props.shelves], () => scene?.setLayout(plain(props.floor), plain(props.shelves)), { deep: true });
watch(() => [props.highlight, props.route], () => {
    if (!scene) return;
    scene.highlight(props.highlight?.id ?? null, props.highlight?.level ?? null);
    scene.setRoute(props.route ? plain(props.route) : null);
}, { deep: true });
watch(() => props.avatar, (a) => scene?.setAvatar(a));
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
