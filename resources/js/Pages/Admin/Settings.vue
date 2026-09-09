<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({ settings: Object, skins: Array, locales: Object, roles: Array });

const page = usePage();
const canAppearance = computed(() => page.props.permissions?.admin?.appearance ?? false);
const canSettings = computed(() => page.props.permissions?.admin?.settings ?? false);

const SKIN_COLORS = {
    indigo: '#4f46e5', slate: '#475569', emerald: '#059669', rose: '#e11d48',
    amber: '#d97706', sky: '#0284c7', violet: '#7c3aed', teal: '#0d9488',
    orange: '#ea580c', zinc: '#52525b', cyan: '#0891b2', fuchsia: '#c026d3',
};

const ROLE_LABELS = { user: 'مستخدم', editor: 'محرّر', contributor: 'مساهم' };

const form = useForm({
    site_name: props.settings.site_name,
    registration_enabled: props.settings.registration_enabled === '1',
    registration_role: props.settings.registration_role,
    default_locale: props.settings.default_locale,
    active_skin: props.settings.active_skin,
    custom_css: props.settings.custom_css,
    default_quota_mb: props.settings.default_quota_mb,
    max_upload_mb: props.settings.max_upload_mb,
    extension_filter_mode: props.settings.extension_filter_mode,
    extension_list: props.settings.extension_list,
    max_zip_files: props.settings.max_zip_files,
    enable_pretty_links: props.settings.enable_pretty_links === '1',
    global_search: props.settings.global_search === '1',
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
            <section v-if="canAppearance" class="rounded-xl border border-gray-200 bg-white p-6">
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

            <section v-if="canAppearance" class="rounded-xl border border-gray-200 bg-white p-6">
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

            <template v-if="canSettings">
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
                        <div>
                            <InputLabel value="الحد الأقصى لعدد الملفات في أرشيف ZIP واحد" />
                            <TextInput type="number" min="1" v-model="form.max_zip_files" class="mt-1 block w-full" />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 font-semibold text-gray-800">امتدادات الملفات المسموح بها</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="نمط التصفية" />
                            <select v-model="form.extension_filter_mode" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="reject">حظر الامتدادات التالية فقط</option>
                                <option value="allow">السماح بالامتدادات التالية فقط</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="قائمة الامتدادات (مفصولة بفواصل، بدون نقطة)" />
                            <TextInput v-model="form.extension_list" placeholder="exe, bat, sh" class="mt-1 block w-full" />
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        هذه القائمة إضافية إلى القائمة الأمنية الثابتة (php, phtml, exe...) التي لا يمكن تعطيلها من هنا.
                    </p>
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
                        <div>
                            <InputLabel value="الدور الافتراضي للمستخدم الجديد" />
                            <select v-model="form.registration_role" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="role in roles.filter((r) => r !== 'admin')" :key="role" :value="role">{{ ROLE_LABELS[role] }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.registration_enabled" class="rounded border-gray-300" />
                            السماح بإنشاء حسابات جديدة ذاتياً
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.enable_pretty_links" class="rounded border-gray-300" />
                            استخدام روابط مشاركة مختصرة (Pretty Links)
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.global_search" class="rounded border-gray-300" />
                            تفعيل البحث الشامل في كل المجلدات (وليس المجلد الحالي فقط)
                        </label>
                    </div>
                </section>
            </template>

            <PrimaryButton :disabled="form.processing">حفظ الإعدادات</PrimaryButton>
        </form>
    </AdminLayout>
</template>
