<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                حذف الحساب
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                بمجرد حذف حسابك، سيتم حذف جميع ملفاتك وبياناته نهائياً. الرجاء تنزيل أي بيانات ترغب بالاحتفاظ بها قبل الحذف.
            </p>
        </header>

        <DangerButton @click="confirmUserDeletion">حذف الحساب</DangerButton>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6">
                <h2
                    class="text-lg font-medium text-gray-900 dark:text-gray-100"
                >
                    هل أنت متأكد من رغبتك في حذف حسابك؟
                </h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    بمجرد حذف حسابك، سيتم حذف جميع ملفاته وبياناته نهائياً. الرجاء إدخال كلمة المرور لتأكيد رغبتك في حذف حسابك نهائياً.
                </p>

                <div class="mt-6">
                    <InputLabel
                        for="password"
                        value="كلمة المرور"
                        class="sr-only"
                    />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-3/4"
                        placeholder="كلمة المرور"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">
                        إلغاء
                    </SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        حذف الحساب
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
