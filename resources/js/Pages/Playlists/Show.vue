<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';
import PreviewModal from '@/Components/PreviewModal.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    QueueListIcon,
    PlayIcon,
    TrashIcon,
    PencilIcon,
    CheckIcon,
    XMarkIcon,
    Bars3Icon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ playlist: Object });

const items = ref([...props.playlist.items]);
const files = computed(() => items.value.map((item) => item.file));

const renaming = ref(false);
const renameForm = useForm({ name: props.playlist.name });

function startRename() {
    renameForm.name = props.playlist.name;
    renaming.value = true;
}

function saveRename() {
    renameForm.put(route('playlists.update', props.playlist.id), {
        preserveScroll: true,
        onSuccess: () => (renaming.value = false),
    });
}

function destroyPlaylist() {
    if (!confirm(t('playlist.confirm_delete', 'سيتم حذف قائمة التشغيل ":name" نهائياً. متابعة؟', { name: props.playlist.name }))) return;
    router.delete(route('playlists.destroy', props.playlist.id));
}

function removeItem(item) {
    items.value = items.value.filter((i) => i.id !== item.id);
    router.delete(route('playlists.items.destroy', [props.playlist.id, item.id]), { preserveScroll: true });
}

const draggedId = ref(null);

function onDragStart(item) {
    draggedId.value = item.id;
}

function onDropOn(targetItem) {
    if (draggedId.value === null || draggedId.value === targetItem.id) return;

    const fromIndex = items.value.findIndex((i) => i.id === draggedId.value);
    const toIndex = items.value.findIndex((i) => i.id === targetItem.id);
    const reordered = [...items.value];
    const [moved] = reordered.splice(fromIndex, 1);
    reordered.splice(toIndex, 0, moved);
    items.value = reordered;
    draggedId.value = null;

    router.put(
        route('playlists.reorder', props.playlist.id),
        { order: reordered.map((i) => i.id) },
        { preserveScroll: true, preserveState: true },
    );
}

const previewFile = ref(null);
const previewIndex = ref(0);

function play(index = 0) {
    previewIndex.value = index;
    previewFile.value = files.value[index];
}
</script>

<template>
    <Head :title="playlist.name" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('playlists.index')" class="text-sm text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">{{ t('nav.playlists', 'قوائم التشغيل') }}</Link>
                <span class="text-gray-300 dark:text-gray-600">/</span>
                <form v-if="renaming" @submit.prevent="saveRename" class="flex items-center gap-2">
                    <input
                        v-model="renameForm.name"
                        autofocus
                        class="rounded-md border-gray-300 py-1 text-sm focus:border-[var(--accent-500)] focus:ring-[var(--accent-500)] dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    />
                    <button type="submit" class="rounded-md p-1 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/40"><CheckIcon class="h-4 w-4" /></button>
                    <button type="button" @click="renaming = false" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><XMarkIcon class="h-4 w-4" /></button>
                </form>
                <h2 v-else class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                    {{ playlist.name }}
                    <button @click="startRename" class="rounded-md p-1 text-gray-300 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700">
                        <PencilIcon class="h-4 w-4" />
                    </button>
                </h2>

                <div class="ms-auto flex items-center gap-2">
                    <button
                        v-if="items.length"
                        @click="play(0)"
                        class="flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                    >
                        <PlayIcon class="h-5 w-5" /> {{ t('playlist.play_all', 'تشغيل الكل') }}
                    </button>
                    <button @click="destroyPlaylist" class="rounded-lg border border-gray-300 p-2 text-gray-500 hover:bg-gray-50 hover:text-red-500 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('playlist.delete', 'حذف القائمة')">
                        <TrashIcon class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </template>

        <div class="p-6">
            <div v-if="!items.length" class="flex flex-col items-center justify-center py-24 text-gray-400">
                <QueueListIcon class="h-16 w-16" />
                <p class="mt-3">{{ t('playlist.item_empty', 'هذه القائمة فارغة. أضف مقاطع فيديو أو صوت إليها من مدير الملفات.') }}</p>
            </div>

            <ul v-else class="divide-y divide-gray-200 rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                <li
                    v-for="(item, index) in items"
                    :key="item.id"
                    draggable="true"
                    @dragstart="onDragStart(item)"
                    @dragover.prevent
                    @drop.prevent="onDropOn(item)"
                    class="flex cursor-pointer items-center gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-700"
                    @dblclick="play(index)"
                >
                    <Bars3Icon class="h-4 w-4 shrink-0 cursor-grab text-gray-300" />
                    <span class="w-5 shrink-0 text-center text-xs text-gray-400">{{ index + 1 }}</span>
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-100 dark:bg-gray-700">
                        <FileThumbnail :file="item.file" :src="route('files.thumbnail', item.file.id)" icon-class="h-8 w-8" />
                    </div>
                    <div class="min-w-0 flex-1" @click="play(index)">
                        <p class="truncate text-sm font-medium text-gray-700 dark:text-gray-200">{{ item.file.name }}</p>
                        <p class="text-xs text-gray-400">{{ item.file.human_size ?? '' }}</p>
                    </div>
                    <button @click="play(index)" class="shrink-0 rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-[var(--accent-600)] dark:hover:bg-gray-700" :title="t('playlist.play', 'تشغيل')">
                        <PlayIcon class="h-4 w-4" />
                    </button>
                    <button @click="removeItem(item)" class="shrink-0 rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-red-500 dark:hover:bg-gray-700" :title="t('playlist.remove_item', 'إزالة من القائمة')">
                        <TrashIcon class="h-4 w-4" />
                    </button>
                </li>
            </ul>
        </div>

        <PreviewModal :show="!!previewFile" :file="previewFile" :files="files" @close="previewFile = null" @change="previewFile = $event" />
    </AppLayout>
</template>
