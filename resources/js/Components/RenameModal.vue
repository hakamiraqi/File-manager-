<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: Boolean,
    item: { type: Object, default: null }, // { id, name, type: 'file' | 'folder' }
});
const emit = defineEmits(['close']);

const form = useForm({ name: '' });

watch(
    () => props.item,
    (item) => {
        if (item) form.name = item.name;
    },
);

function submit() {
    const url = props.item.type === 'file'
        ? route('files.update', props.item.id)
        : route('folders.update', props.item.id);

    form.transform((data) => ({ ...data, _method: 'put' })).post(url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')">
        <form @submit.prevent="submit" class="p-6" v-if="item">
            <h2 class="text-lg font-medium text-gray-900">إعادة تسمية "{{ item.name }}"</h2>
            <div class="mt-4">
                <InputLabel for="rename" value="الاسم الجديد" />
                <TextInput id="rename" v-model="form.name" class="mt-1 block w-full" autofocus />
                <InputError :message="form.errors.name" class="mt-2" />
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="$emit('close')">إلغاء</SecondaryButton>
                <PrimaryButton :disabled="form.processing">حفظ</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
