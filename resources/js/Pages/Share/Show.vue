<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { FolderIcon, LockClosedIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    token: String,
    name: String,
    isFolder: Boolean,
    size: String,
    requiresPassword: Boolean,
    expired: Boolean,
    limitReached: Boolean,
    allowDownload: Boolean,
    unlocked: Boolean,
});

const form = useForm({ password: '' });

function unlock() {
    form.post(route('share.public.unlock', props.token), { preserveScroll: true });
}
</script>

<template>
    <Head :title="name ?? 'ملف مشترك'" />

    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-sm">
            <ApplicationLogo class="mx-auto h-10 w-10 fill-current text-[var(--accent-600)]" />

            <template v-if="expired">
                <p class="mt-6 text-gray-600">انتهت صلاحية رابط المشاركة هذا.</p>
            </template>
            <template v-else-if="limitReached">
                <p class="mt-6 text-gray-600">تم الوصول إلى الحد الأقصى لعدد مرات تنزيل هذا الملف.</p>
            </template>
            <template v-else-if="requiresPassword">
                <LockClosedIcon class="mx-auto mt-6 h-8 w-8 text-gray-400" />
                <p class="mt-2 text-gray-600">هذه المشاركة محمية بكلمة مرور.</p>
                <form @submit.prevent="unlock" class="mt-4 space-y-3 text-start">
                    <InputLabel value="كلمة المرور" />
                    <TextInput type="password" v-model="form.password" class="block w-full" autofocus />
                    <InputError :message="form.errors.password" />
                    <PrimaryButton class="w-full justify-center" :disabled="form.processing">دخول</PrimaryButton>
                </form>
            </template>
            <template v-else>
                <FolderIcon v-if="isFolder" class="mx-auto mt-6 h-14 w-14 text-amber-400" />
                <h1 class="mt-4 truncate text-lg font-semibold text-gray-800">{{ name }}</h1>
                <p v-if="size" class="mt-1 text-sm text-gray-400">{{ size }}</p>

                <a
                    v-if="allowDownload"
                    :href="route('share.public.download', token)"
                    class="mt-6 inline-flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-5 py-2.5 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                >
                    <ArrowDownTrayIcon class="h-5 w-5" /> تنزيل
                </a>
                <p v-else class="mt-6 text-sm text-gray-400">التنزيل غير مفعّل لهذه المشاركة.</p>
            </template>
        </div>
    </div>
</template>
