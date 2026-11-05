<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import TagInput from '@/Components/TagInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const props = defineProps({ settings: Object, skins: Array, locales: Object, roles: Array });

const page = usePage();
const canAppearance = computed(() => page.props.permissions?.admin?.appearance ?? false);
const canSettings = computed(() => page.props.permissions?.admin?.settings ?? false);

const SKIN_COLORS = {
    indigo: '#4f46e5', slate: '#475569', emerald: '#059669', rose: '#e11d48',
    amber: '#d97706', sky: '#0284c7', violet: '#7c3aed', teal: '#0d9488',
    orange: '#ea580c', zinc: '#52525b', cyan: '#0891b2', fuchsia: '#c026d3',
};

const ROLE_LABELS = {
    user: t('role.user', 'مستخدم'),
    editor: t('role.editor', 'محرّر'),
    contributor: t('role.contributor', 'مساهم'),
};

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
    content_indexing_enabled: props.settings.content_indexing_enabled === '1',
    content_indexing_max_mb: props.settings.content_indexing_max_mb,
    thumbnails_enabled: props.settings.thumbnails_enabled === '1',
    thumbnail_max_px: props.settings.thumbnail_max_px,
    video_transcoding_enabled: props.settings.video_transcoding_enabled === '1',
    video_quality_targets: props.settings.video_quality_targets,
    interactive_video_enabled: props.settings.interactive_video_enabled === '1',
    institution_university: props.settings.institution_university ?? '',
    institution_college: props.settings.institution_college ?? '',
    thesis_departments: props.settings.thesis_departments ?? '',
    thesis_subjects: props.settings.thesis_subjects ?? '',
    oai_enabled: props.settings.oai_enabled === '1',
    logo: null,
    header_image: null,
});

function submit() {
    form.post(route('admin.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('settings.title', 'الإعدادات العامة')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.title', 'الإعدادات العامة') }}</h2>
        </template>

        <form @submit.prevent="submit" class="max-w-3xl space-y-8 p-6">
            <section v-if="canAppearance" class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.branding_title', 'الهوية والعلامة التجارية') }}</h3>
                <div class="space-y-4">
                    <div>
                        <InputLabel :value="t('settings.site_name', 'اسم الموقع')" />
                        <TextInput v-model="form.site_name" class="mt-1 block w-full" />
                        <InputError :message="form.errors.site_name" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
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

            <section v-if="canAppearance" class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
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
                    <textarea v-model="form.custom_css" rows="4" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                </div>
            </section>

            <template v-if="canSettings">
                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.storage_title', 'التخزين والرفع') }}</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel :value="t('settings.default_quota', 'الحصة الافتراضية للمستخدم الجديد (ميغابايت)')" />
                            <TextInput type="number" min="1" v-model="form.default_quota_mb" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.max_upload', 'الحد الأقصى لحجم الملف الواحد (ميغابايت)')" />
                            <TextInput type="number" min="1" v-model="form.max_upload_mb" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.max_zip_files', 'الحد الأقصى لعدد الملفات في أرشيف ZIP واحد')" />
                            <TextInput type="number" min="1" v-model="form.max_zip_files" class="mt-1 block w-full" />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.extensions_title', 'امتدادات الملفات المسموح بها') }}</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('settings.filter_mode', 'نمط التصفية')" />
                            <select v-model="form.extension_filter_mode" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option value="reject">{{ t('settings.filter_reject', 'حظر الامتدادات التالية فقط') }}</option>
                                <option value="allow">{{ t('settings.filter_allow', 'السماح بالامتدادات التالية فقط') }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="t('settings.extension_list_tags', 'قائمة الامتدادات')" />
                            <TagInput
                                v-model="form.extension_list"
                                class="mt-1"
                                :placeholder="t('settings.extension_add', 'اكتب امتداداً واضغط Enter')"
                                :normalize="(v) => v.trim().replace(/^\.+/, '').toLowerCase()"
                                :suggestions="form.extension_filter_mode === 'allow'
                                    ? ['pdf', 'docx', 'xlsx', 'pptx', 'jpg', 'png', 'mp4', 'mp3', 'zip']
                                    : ['exe', 'bat', 'cmd', 'sh', 'msi', 'js', 'vbs', 'apk']"
                            />
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('settings.extension_note', 'هذه القائمة إضافية إلى القائمة الأمنية الثابتة (php, phtml, exe...) التي لا يمكن تعطيلها من هنا.') }}
                    </p>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-1 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.institution_title', 'المؤسسة والرسائل الجامعية') }}</h3>
                    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">{{ t('settings.institution_note', 'تُملأ الجامعة والكلية تلقائياً في كل رسالة جديدة، والأقسام والمواضيع تظهر كخيارات جاهزة، وفق حقول المستودع الرقمي العراقي.') }}</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('settings.institution_university', 'الجامعة')" />
                            <TextInput v-model="form.institution_university" class="mt-1 block w-full" :placeholder="t('settings.institution_university_ph', 'مثال: جامعة تكريت')" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.institution_college', 'الكلية')" />
                            <TextInput v-model="form.institution_college" class="mt-1 block w-full" :placeholder="t('settings.institution_college_ph', 'مثال: كلية طب الأسنان')" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.thesis_departments', 'الأقسام العلمية')" />
                            <TagInput v-model="form.thesis_departments" class="mt-1" multiword :placeholder="t('settings.tag_add', 'اكتب واضغط Enter')" />
                        </div>
                        <div>
                            <InputLabel :value="t('settings.thesis_subjects', 'المواضيع العامة')" />
                            <TagInput v-model="form.thesis_subjects" class="mt-1" multiword :placeholder="t('settings.tag_add', 'اكتب واضغط Enter')" />
                        </div>
                    </div>
                    <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" v-model="form.oai_enabled" class="rounded border-gray-300 text-[var(--accent-600)]" />
                        {{ t('settings.oai_enabled', 'إتاحة بيانات الرسائل العامة عبر OAI-PMH (Dublin Core) لحصادها من المستودعات') }}
                        <code class="text-xs text-gray-400" dir="ltr">/oai</code>
                    </label>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.thumbnails_title', 'الصور المصغّرة (Thumbnails)') }}</h3>
                    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('settings.thumbnails_description', 'عرض صورة مصغّرة حقيقية للصور مباشرة، ولمقاطع الفيديو (يتطلب توفّر أداة ffmpeg على الخادم)، ولأول صفحة من ملفات PDF (يتطلب امتداد Imagick). عند عدم توفّر الأداة المطلوبة تُعرض الأيقونة العامة بدلاً من ذلك تلقائياً دون أي خطأ.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="col-span-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.thumbnails_enabled" class="rounded border-gray-300" />
                            {{ t('settings.thumbnails_enable', 'تفعيل توليد الصور المصغّرة تلقائياً عند الرفع') }}
                        </label>
                        <div>
                            <InputLabel :value="t('settings.thumbnail_max_px', 'أقصى أبعاد للصورة المصغّرة (بكسل)')" />
                            <TextInput type="number" min="50" v-model="form.thumbnail_max_px" class="mt-1 block w-full" />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.video_quality_title', 'جودات تشغيل الفيديو') }}</h3>
                    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('settings.video_quality_description', 'عند التفعيل، يُنشئ الخادم نسخاً بدقات أقل من كل فيديو يُرفع تلقائياً (يتطلب توفّر أداة ffmpeg على الخادم)، فيظهر لمستخدم المعاينة قائمة اختيار الجودة تماماً كما في يوتيوب. عند عدم توفّر الأداة يبقى تشغيل الفيديو الأصلي يعمل بلا أي تأثير.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="col-span-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.video_transcoding_enabled" class="rounded border-gray-300" />
                            {{ t('settings.video_transcoding_enable', 'تفعيل إنشاء نسخ بجودات مختلفة تلقائياً عند رفع الفيديو') }}
                        </label>
                        <div class="col-span-2">
                            <InputLabel :value="t('settings.video_quality_targets', 'الدقات المستهدفة (بالبكسل، مفصولة بفواصل)')" />
                            <TextInput type="text" v-model="form.video_quality_targets" placeholder="360,480,720" class="mt-1 block w-full" />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.interactive_video_title', 'الفيديو التفاعلي') }}</h3>
                    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('settings.interactive_video_description', 'عند التفعيل، يمكن لمن يملك صلاحية "إدارة الأسئلة" إضافة أسئلة تظهر وتوقف الفيديو عند لحظة محدَّدة منه، وتُسجَّل درجة كل مشاهد. عند التعطيل يختفي زر الأسئلة تماماً وتُشغَّل الفيديوهات بلا توقّف، دون حذف أي أسئلة أو درجات محفوظة مسبقاً.') }}
                    </p>
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <input type="checkbox" v-model="form.interactive_video_enabled" class="rounded border-gray-300" />
                        {{ t('settings.interactive_video_enable', 'تفعيل الأسئلة التفاعلية داخل الفيديو') }}
                    </label>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.content_indexing_title', 'فهرسة محتوى المستندات للبحث') }}</h3>
                    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('settings.content_indexing_description', 'عند التفعيل، يُستخرَج النص من داخل ملفات Word وPDF وPowerPoint والنصوص عند رفعها، ليصبح البحث قادراً على إيجاد الملف من محتواه الداخلي وليس فقط من اسمه — تماماً كفهرسة مكتبة أو دار نشر.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="col-span-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.content_indexing_enabled" class="rounded border-gray-300" />
                            {{ t('settings.content_indexing_enable', 'تفعيل استخراج النص وفهرسته تلقائياً عند الرفع (Word, PDF, PowerPoint, نصوص)') }}
                        </label>
                        <div>
                            <InputLabel :value="t('settings.content_indexing_max_mb', 'لا تُفهرس الملفات الأكبر من (ميغابايت)')" />
                            <TextInput type="number" min="1" v-model="form.content_indexing_max_mb" class="mt-1 block w-full" />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('settings.locale_registration_title', 'اللغة والتسجيل') }}</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel :value="t('settings.default_locale', 'اللغة الافتراضية')" />
                            <select v-model="form.default_locale" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="(meta, code) in locales" :key="code" :value="code">{{ meta.label }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="t('settings.default_role', 'الدور الافتراضي للمستخدم الجديد')" />
                            <select v-model="form.registration_role" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="role in roles.filter((r) => r !== 'admin')" :key="role" :value="role">{{ ROLE_LABELS[role] }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.registration_enabled" class="rounded border-gray-300" />
                            {{ t('settings.allow_registration', 'السماح بإنشاء حسابات جديدة ذاتياً') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.enable_pretty_links" class="rounded border-gray-300" />
                            {{ t('settings.pretty_links', 'استخدام روابط مشاركة مختصرة (Pretty Links)') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.global_search" class="rounded border-gray-300" />
                            {{ t('settings.global_search', 'تفعيل البحث الشامل في كل المجلدات (وليس المجلد الحالي فقط)') }}
                        </label>
                    </div>
                </section>
            </template>

            <PrimaryButton :disabled="form.processing">{{ t('settings.save', 'حفظ الإعدادات') }}</PrimaryButton>
        </form>
    </AdminLayout>
</template>
