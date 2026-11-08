<script setup>
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();

const props = defineProps({
    show: Boolean,
    parentId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const form = useForm({ name: '', parent_id: null });

function submit() {
    form.parent_id = props.parentId;
    form.post(route('folders.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')">
        <form @submit.prevent="submit" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ t('action.new_folder', 'مجلد جديد') }}</h2>
            <div class="mt-4">
                <InputLabel for="folder_name" :value="t('folder.name_label', 'اسم المجلد')" />
                <TextInput id="folder_name" v-model="form.name" class="mt-1 block w-full" autofocus />
                <InputError :message="form.errors.name" class="mt-2" />
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="$emit('close')">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ t('action.create', 'إنشاء') }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
