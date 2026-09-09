<script setup>
import { useForm } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({ settings: Object, skins: Array, locales: Object });

const SKIN_COLORS = {
    indigo: '#4f46e5', slate: '#475569', emerald: '#059669', rose: '#e11d48',
    amber: '#d97706', sky: '#0284c7', violet: '#7c3aed', teal: '#0d9488',
    orange: '#ea580c', zinc: '#52525b', cyan: '#0891b2', fuchsia: '#c026d3',
};

const form = useForm({
    site_name: props.settings.site_name,
    registration_enabled: props.settings.registration_enabled === '1',
    default_locale: props.settings.default_locale,
    active_skin: props.settings.active_skin,
    custom_css: props.settings.custom_css,
    default_quota_mb: props.settings.default_quota_mb,
    max_upload_mb: props.settings.max_upload_mb,
    logo: null,
    header_image: null,
});

function submit() {
    form.post(route('admin.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="الإعدادات العامة" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800">الإعدادات العامة</h2>
        </template>

        <form @submit.prevent="submit" class="max-w-3xl space-y-8 p-6">
            <section class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 font-semibold text-gray-800">الهوية والعلامة التجارية</h3>
                <div class="space-y-4">
                    <div>
                        <InputLabel value="اسم الموقع" />
                        <TextInput v-model="form.site_name" class="mt-1 block w-full" />
                        <InputError :message="form.errors.site_name" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="الشعار (Logo)" />
                            <input type="file" accept="image/*" class="mt-1 block w-full text-sm" @change="form.logo = $event.target.files[0]" />
                        </div>
                        <div>
                            <InputLabel value="صورة الترويسة (اختياري)" />
                            <input type="file" accept="image/*" class="mt-1 block w-full text-sm" @change="form.header_image = $event.target.files[0]" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 font-semibold text-gray-800">المظهر (السكن)</h3>
                <div class="flex flex-wrap gap-3">
                    <button
                        v-for="skin in skins"
                        :key="skin"
                        type="button"
                        @click="form.active_skin = skin"
                        class="h-10 w-10 rounded-full border-2 transition"
                        :class="form.active_skin === skin ? 'border-gray-800 ring-2 ring-offset-2' : 'border-transparent'"
                        :style="{ backgroundColor: SKIN_COLORS[skin] }"
                        :title="skin"
                    />
                </div>
                <div class="mt-4">
                    <InputLabel value="CSS مخصص (اختياري، يطبّق فوق التصميم الحالي)" />
                    <textarea v-model="form.custom_css" rows="4" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs" />
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 font-semibold text-gray-800">التخزين والرفع</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="الحصة الافتراضية للمستخدم الجديد (ميغابايت)" />
                        <TextInput type="number" min="1" v-model="form.default_quota_mb" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel value="الحد الأقصى لحجم الملف الواحد (ميغابايت)" />
                        <TextInput type="number" min="1" v-model="form.max_upload_mb" class="mt-1 block w-full" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 font-semibold text-gray-800">اللغة والتسجيل</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="اللغة الافتراضية" />
                        <select v-model="form.default_locale" class="mt-1 block w-full rounded-md border-gray-300">
                            <option v-for="(meta, code) in locales" :key="code" :value="code">{{ meta.label }}</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.registration_enabled" class="rounded border-gray-300" />
                            السماح بإنشاء حسابات جديدة ذاتياً
                        </label>
                    </div>
                </div>
            </section>

            <PrimaryButton :disabled="form.processing">حفظ الإعدادات</PrimaryButton>
        </form>
    </AdminLayout>
</template>
