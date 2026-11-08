<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useTrans } from '@/Composables/useTrans';
import { FolderIcon } from '@heroicons/vue/24/solid';

const { t } = useTrans();

const props = defineProps({
    show: Boolean,
    item: { type: Object, default: null }, // { id, name, type }
});
const emit = defineEmits(['close', 'moved']);

const folders = ref([]);
const selected = ref(null);

watch(
    () => props.show,
    async (show) => {
        if (show) {
            selected.value = null;
            const { data } = await axios.get(route('folders.tree'));
            folders.value = data;
        }
    },
);

const tree = computed(() => buildTree(null, 0));

function buildTree(parentId, depth) {
    return folders.value
        .filter((f) => f.parent_id === parentId && !(props.item?.type === 'folder' && f.id === props.item.id))
        .map((f) => ({ ...f, depth, children: buildTree(f.id, depth + 1) }))
        .reduce((flat, f) => [...flat, f, ...f.children], []);
}

const form = useForm({});

function submit() {
    const url = props.item.type === 'file'
        ? route('files.update', props.item.id)
        : route('folders.update', props.item.id);

    const payload = props.item.type === 'file' ? { folder_id: selected.value } : { parent_id: selected.value };

    form.transform(() => ({ ...payload, _method: 'put' })).post(url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')">
        <div class="p-6" v-if="item">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ t('move.title', 'نقل ":name" إلى', { name: item.name }) }}</h2>

            <div class="mt-4 max-h-72 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                    :class="{ 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700': selected === null }"
                    @click="selected = null"
                >
                    <FolderIcon class="h-4 w-4 text-amber-400" />
                    {{ t('move.root', 'الجذر الرئيسي (My Files)') }}
                </button>
                <button
                    v-for="f in tree"
                    :key="f.id"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                    :class="{ 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700': selected === f.id }"
                    :style="{ paddingInlineStart: `${8 + f.depth * 16}px` }"
                    @click="selected = f.id"
                >
                    <FolderIcon class="h-4 w-4 text-amber-400" />
                    {{ f.name }}
                </button>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="$emit('close')">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing" @click="submit">{{ t('move.here', 'نقل هنا') }}</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
