<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import PreferencesForm from './Partials/PreferencesForm.vue';
import TwoFactorAuthenticationForm from './Partials/TwoFactorAuthenticationForm.vue';
import { useTrans } from '@/Composables/useTrans';
import { Head } from '@inertiajs/vue3';

const { t } = useTrans();

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head :title="t('nav.profile', 'الملف الشخصي والإعدادات')" />

    <AppLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('nav.profile', 'الملف الشخصي والإعدادات') }}</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8 dark:border-gray-700 dark:bg-gray-800">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8 dark:border-gray-700 dark:bg-gray-800">
                    <PreferencesForm class="max-w-xl" />
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8 dark:border-gray-700 dark:bg-gray-800">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8 dark:border-gray-700 dark:bg-gray-800">
                    <TwoFactorAuthenticationForm class="max-w-xl" />
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8 dark:border-gray-700 dark:bg-gray-800">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
