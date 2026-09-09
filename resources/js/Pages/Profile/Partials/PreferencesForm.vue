<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const user = usePage().props.auth.user;

const form = useForm({
    locale: user.locale ?? 'ar',
});

function submit() {
    form.patch(route('profile.preferences'), { preserveScroll: true });
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">تفضيلات الواجهة</h2>
            <p class="mt-1 text-sm text-gray-600">اختر لغة عرض واجهة المستخدم.</p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <div>
                <InputLabel value="اللغة" />
                <select v-model="form.locale" class="mt-1 block w-full rounded-md border-gray-300">
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">حفظ</PrimaryButton>
                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">تم الحفظ.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
