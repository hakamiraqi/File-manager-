<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import SettingToggle from '@/Components/SettingToggle.vue';
import StickySaveBar from '@/Components/StickySaveBar.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const props = defineProps({ hosted: Boolean, settings: Object, skins: Array, locales: Object });

const page = usePage();
const canAppearance = computed(() => page.props.permissions?.admin?.appearance ?? false);
const canSettings = computed(() => page.props.permissions?.admin?.settings ?? false);

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
    institution_university: props.settings.institution_university ?? '',
    institution_college: props.settings.institution_college ?? '',
    work_day_start: props.settings.work_day_start ?? '08:00',
    logo: null,
    header_image: null,
    ai_api_key: '',
    ai_model: props.settings.ai_model || 'claude-sonnet-5-5',
    ai_clear: false,
});

function submit() {
    form.post(route('admin.settings.update'), { preserveScroll: true, onSuccess: () => { form.logo = null; form.header_image = null; form.defaults(); } });
}
</script>

<template>
    <Head :title="t('settings.title', 'الإعدادات العامة')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.title', 'الإعدادات العامة') }}</h2>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6 px-4 pt-4 sm:px-6 sm:pt-6">
            <section v-if="canAppearance" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.branding_title', 'الهوية والعلامة التجارية') }}</h3>
                <div class="space-y-4">
                    <div>
                        <InputLabel :value="t('settings.site_name', 'اسم الموقع')" />
                        <TextInput v-model="form.site_name" class="mt-1 block w-full" />
                        <InputError :message="form.errors.site_name" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('settings.logo', 'الشعار (Logo)')" />
                            <input type="file" accept="image/*" class="mt-1 block w-full text-sm dark:text-gray-300" @change="form.logo = $event.target.files[0]" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.header_image', 'صورة الترويسة (اختياري)')" />
                            <input type="file" accept="image/*" class="mt-1 block w-full text-sm dark:text-gray-300" @change="form.header_image = $event.target.files[0]" />
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="canAppearance" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.skin_title', 'المظهر (السكن)') }}</h3>
                <div class="flex flex-wrap gap-3">
                    <button
                        v-for="skin in skins"
                        :key="skin"
                        type="button"
                        @click="form.active_skin = skin"
                        class="h-10 w-10 rounded-full border-2 transition"
                        :class="form.active_skin === skin ? 'border-gray-800 ring-2 ring-offset-2 dark:border-gray-100' : 'border-transparent'"
                        :style="{ backgroundColor: SKIN_COLORS[skin] }"
                        :title="skin"
                    />
                </div>
                <div class="mt-4">
                    <InputLabel :value="t('settings.custom_css', 'CSS مخصص (اختياري، يطبّق فوق التصميم الحالي)')" />
                    <textarea v-model="form.custom_css" rows="4" class="mt-1 block w-full rounded-xl border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                </div>
            </section>

            <template v-if="canSettings">
                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.institution_title', 'المؤسسة') }}</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel :value="t('settings.institution_university', 'الجامعة / المدرسة')" />
                            <TextInput v-model="form.institution_university" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.institution_college', 'الكلية / الفرع')" />
                            <TextInput v-model="form.institution_college" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.work_day_start', 'بداية الدوام (لسجل النشاطات)')" />
                            <TextInput type="time" v-model="form.work_day_start" class="mt-1 block w-full" />
                        </div>
                    </div>
                </section>
                <section v-if="hosted" class="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="font-semibold">{{ t('settings.ai', 'اقتراح الدرجات بالذكاء الاصطناعي (على الاستضافة فقط)') }}</h3>
                    <p class="text-xs text-gray-500">{{ t('settings.ai_hint', 'يقترح درجة وسبباً للإجابات المقالية حسب معايير التصحيح، والأستاذ يقرّر. يحتاج مفتاح Anthropic API، ويُرسل نص السؤال والإجابة دون اسم الطالب. لا يعمل في نسخة السيرفر بدون إنترنت.') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('settings.ai_key', 'مفتاح API') + (settings.ai_api_key_set ? ' ✓' : '')" />
                            <TextInput type="password" v-model="form.ai_api_key" class="mt-1 block w-full" dir="ltr" autocomplete="off" :placeholder="settings.ai_api_key_set ? t('settings.ai_key_kept', 'محفوظ؛ اتركه فارغاً لإبقائه') : 'sk-ant-…'" />
                            <label v-if="settings.ai_api_key_set" class="mt-1 flex items-center gap-2 text-xs"><input type="checkbox" v-model="form.ai_clear" class="rounded" /> {{ t('settings.ai_clear', 'حذف المفتاح') }}</label>
                        </div>
                        <div>
                            <InputLabel :value="t('settings.ai_model', 'النموذج')" />
                            <TextInput v-model="form.ai_model" class="mt-1 block w-full" dir="ltr" />
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.locale_registration_title', 'اللغة والتسجيل') }}</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('settings.default_locale', 'اللغة الافتراضية')" />
                            <select v-model="form.default_locale" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="(meta, code) in locales" :key="code" :value="code">{{ meta.label }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 divide-y divide-gray-100 border-t border-gray-100 pt-3 dark:divide-gray-700 dark:border-gray-700">
                        <SettingToggle v-model="form.registration_enabled">{{ t('settings.allow_registration', 'السماح بإنشاء حسابات جديدة ذاتياً') }}</SettingToggle>
                    </div>
                </section>
            </template>

            <StickySaveBar :form="form" :label="t('settings.save', 'حفظ الإعدادات')" @save="submit" @reset="form.reset()" />
        </form>
    </AdminLayout>
</template>
