<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useTrans } from '@/Composables/useTrans';
import { QueueListIcon, CheckCircleIcon, PlusIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();

const props = defineProps({
    show: Boolean,
    item: { type: Object, default: null }, // { id, name, type: 'file' }
});
const emit = defineEmits(['close']);

const playlists = ref([]);
const loading = ref(false);
const addingId = ref(null);
const creatingNew = ref(false);

const form = useForm({ name: '' });

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get(route('playlists.picker'), { params: { file_id: props.item?.id } });
        playlists.value = data.playlists;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.show,
    (show) => {
        if (show) {
            creatingNew.value = false;
            form.reset();
            load();
        }
    },
);

function addTo(playlist) {
    addingId.value = playlist.id;
    axios
        .post(route('playlists.items.store', playlist.id), { file_id: props.item.id })
        .then(() => {
            playlist.contains_file = true;
            playlist.items_count += 1;
        })
        .finally(() => (addingId.value = null));
}

function createAndAdd() {
    form.transform((data) => ({ ...data, file_id: props.item.id })).post(route('playlists.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            creatingNew.value = false;
            emit('close');
        },
    });
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')" max-width="md">
        <div class="p-6" v-if="item">
            <h2 class="flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-gray-100">
                <QueueListIcon class="h-5 w-5 text-[var(--accent-600)]" />
                {{ t('playlist.add_to_title', 'إضافة ":name" إلى قائمة تشغيل', { name: item.name }) }}
            </h2>

            <div v-if="loading" class="mt-6 py-6 text-center text-sm text-gray-400">{{ t('common.loading', 'جارِ التحميل...') }}</div>

            <div v-else class="mt-4 max-h-72 space-y-1 overflow-y-auto">
                <div v-if="!playlists.length" class="py-6 text-center text-sm text-gray-400">
                    {{ t('playlist.none_yet', 'لا توجد قوائم تشغيل بعد.') }}
                </div>
                <button
                    v-for="playlist in playlists"
                    :key="playlist.id"
                    type="button"
                    :disabled="playlist.contains_file || addingId === playlist.id"
                    @click="addTo(playlist)"
                    class="flex w-full items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-start text-sm hover:bg-gray-50 disabled:cursor-default disabled:opacity-70 dark:border-gray-600 dark:hover:bg-gray-700"
                >
                    <span class="min-w-0 flex-1 truncate">
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ playlist.name }}</span>
                        <span class="ms-1 text-xs text-gray-400">({{ t('playlist.items_count', ':count عنصر', { count: playlist.items_count }) }})</span>
                    </span>
                    <CheckCircleIcon v-if="playlist.contains_file" class="h-5 w-5 shrink-0 text-emerald-600" />
                    <PlusIcon v-else class="h-5 w-5 shrink-0 text-gray-400" />
                </button>
            </div>

            <form v-if="creatingNew" @submit.prevent="createAndAdd" class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                <InputLabel for="new_playlist_name" :value="t('playlist.new_name_label', 'اسم قائمة التشغيل الجديدة')" />
                <TextInput id="new_playlist_name" v-model="form.name" class="mt-1 block w-full" autofocus />
                <InputError :message="form.errors.name" class="mt-2" />
                <div class="mt-3 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="creatingNew = false">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('playlist.create_and_add', 'إنشاء وإضافة') }}</PrimaryButton>
                </div>
            </form>
            <button
                v-else
                type="button"
                @click="creatingNew = true"
                class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
            >
                <PlusIcon class="h-4 w-4" /> {{ t('playlist.new', 'قائمة تشغيل جديدة') }}
            </button>

            <div class="mt-6 flex justify-end">
                <SecondaryButton @click="$emit('close')">{{ t('action.close', 'إغلاق') }}</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
