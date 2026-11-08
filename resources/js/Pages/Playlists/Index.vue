<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useTrans } from '@/Composables/useTrans';
import { QueueListIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();

defineProps({ playlists: Array });

const showCreate = ref(false);
const form = useForm({ name: '' });

function create() {
    form.post(route('playlists.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showCreate.value = false;
        },
    });
}

function destroy(playlist) {
    if (!confirm(t('playlist.confirm_delete', 'سيتم حذف قائمة التشغيل ":name" نهائياً. متابعة؟', { name: playlist.name }))) return;
    router.delete(route('playlists.destroy', playlist.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('nav.playlists', 'قوائم التشغيل')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('nav.playlists', 'قوائم التشغيل') }}</h2>
                <button
                    @click="showCreate = true"
                    class="flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                >
                    <PlusIcon class="h-5 w-5" /> {{ t('playlist.new', 'قائمة جديدة') }}
                </button>
            </div>
        </template>

        <div class="p-6">
            <div v-if="!playlists.length" class="flex flex-col items-center justify-center py-24 text-gray-400">
                <QueueListIcon class="h-16 w-16" />
                <p class="mt-3">{{ t('playlist.empty', 'لم تنشئ أي قائمة تشغيل بعد. اجمع مقاطع الفيديو والصوت المفضلة لديك في قوائم مرتّبة.') }}</p>
            </div>

            <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                <Link
                    v-for="playlist in playlists"
                    :key="playlist.id"
                    :href="route('playlists.show', playlist.id)"
                    class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="flex aspect-video items-center justify-center bg-gray-100 dark:bg-gray-700">
                        <FileThumbnail
                            v-if="playlist.items?.[0]?.file"
                            :file="playlist.items[0].file"
                            :src="route('files.thumbnail', playlist.items[0].file.id)"
                            icon-class="h-10 w-10 text-gray-400"
                        />
                        <QueueListIcon v-else class="h-10 w-10 text-gray-300" />
                    </div>
                    <div class="flex items-start justify-between gap-2 p-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-700 dark:text-gray-200">{{ playlist.name }}</p>
                            <p class="text-xs text-gray-400">{{ t('playlist.items_count', ':count عنصر', { count: playlist.items_count }) }}</p>
                        </div>
                        <button
                            @click.prevent.stop="destroy(playlist)"
                            class="shrink-0 rounded-md p-1 text-gray-300 opacity-0 hover:bg-gray-100 hover:text-red-500 group-hover:opacity-100 dark:hover:bg-gray-700"
                            :title="t('playlist.delete', 'حذف قائمة التشغيل')"
                        >
                            <TrashIcon class="h-4 w-4" />
                        </button>
                    </div>
                </Link>
            </div>
        </div>

        <Modal :show="showCreate" @close="showCreate = false">
            <form @submit.prevent="create" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ t('playlist.new', 'قائمة تشغيل جديدة') }}</h2>
                <div class="mt-4">
                    <InputLabel for="playlist_name" :value="t('playlist.name_label', 'اسم القائمة')" />
                    <TextInput id="playlist_name" v-model="form.name" class="mt-1 block w-full" autofocus />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showCreate = false">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('action.create', 'إنشاء') }}</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
