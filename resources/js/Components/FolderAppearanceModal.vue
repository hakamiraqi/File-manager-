<script setup>
// «مظهر المجلد»: colour, a college theme, a logo and a cover picture, with a live preview.
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import FolderCover from '@/Components/FolderCover.vue';
import FolderThemeIcon from '@/Components/FolderThemeIcon.vue';
import { FOLDER_THEMES, COLOR_SWATCHES, themeByKey } from '@/lib/folderThemes';
import { useTrans } from '@/Composables/useTrans';
import { CheckIcon, PhotoIcon, TrashIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({
    show: Boolean,
    folder: { type: Object, default: null }, // { id, name, color, theme, logo_url, cover_url } (the folder's own)
});
const emit = defineEmits(['close']);

const form = useForm({ color: '', theme: '', logo: null, cover: null, remove_logo: false, remove_cover: false });
const logoPreview = ref(null);
const coverPreview = ref(null);
const tab = ref('tikrit');

watch(() => [props.show, props.folder?.id], () => {
    if (!props.show || !props.folder) return;
    form.reset();
    form.clearErrors();
    form.color = props.folder.color || '';
    form.theme = props.folder.theme || '';
    logoPreview.value = props.folder.logo_url || null;
    coverPreview.value = props.folder.cover_url || null;
    tab.value = !props.folder.theme || themeByKey(props.folder.theme)?.group === 'tikrit' ? 'tikrit' : 'plain';
}, { immediate: true });

const preview = computed(() => ({
    color: form.color || null,
    theme: form.theme || null,
    logo_url: logoPreview.value,
    cover_url: coverPreview.value,
}));
const themes = computed(() => FOLDER_THEMES.filter((th) => th.group === tab.value));

function pickTheme(th) {
    form.theme = form.theme === th.key ? '' : th.key;
    // A theme brings its own colour; a colour picked by hand would hide it.
    if (form.theme) form.color = '';
}

function onFile(kind, event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    form[kind] = file;
    form[`remove_${kind}`] = false;
    const url = URL.createObjectURL(file);
    kind === 'logo' ? (logoPreview.value = url) : (coverPreview.value = url);
}

function removeImage(kind) {
    form[kind] = null;
    form[`remove_${kind}`] = true;
    kind === 'logo' ? (logoPreview.value = null) : (coverPreview.value = null);
}

function submit() {
    form.transform((data) => {
        const out = { color: data.color || '', theme: data.theme || '', remove_logo: data.remove_logo ? 1 : 0, remove_cover: data.remove_cover ? 1 : 0 };
        if (data.logo) out.logo = data.logo;
        if (data.cover) out.cover = data.cover;
        return out;
    }).post(route('folders.appearance', props.folder.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit('close'),
    });
}

function resetAll() {
    form.color = '';
    form.theme = '';
    removeImage('logo');
    removeImage('cover');
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <form v-if="folder" class="space-y-5 p-5 sm:p-6" data-testid="folder-appearance" @submit.prevent="submit">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('folder_look.title', 'مظهر المجلد') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ t('folder_look.hint', 'المجلدات التي بداخله تأخذ نفس المظهر ما لم يكن لها مظهرها الخاص.') }}</p>
            </div>

            <FolderCover :look="preview" :title="folder.name" :subtitle="themeByKey(form.theme)?.name || ''" small />

            <!-- Themes -->
            <section>
                <div class="mb-2 flex items-center gap-2">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ t('folder_look.theme', 'الثيم') }}</h3>
                    <div class="ms-auto flex overflow-hidden rounded-lg border border-gray-200 text-xs dark:border-gray-600">
                        <button type="button" class="px-2.5 py-1" :class="tab === 'tikrit' ? 'bg-[var(--accent-600)] text-white' : 'text-gray-600 dark:text-gray-300'" @click="tab = 'tikrit'">{{ t('folder_look.tikrit', 'كليات جامعة تكريت') }}</button>
                        <button type="button" class="px-2.5 py-1" :class="tab === 'plain' ? 'bg-[var(--accent-600)] text-white' : 'text-gray-600 dark:text-gray-300'" @click="tab = 'plain'">{{ t('folder_look.plain', 'عامة') }}</button>
                    </div>
                </div>
                <div class="grid max-h-56 grid-cols-2 gap-2 overflow-y-auto pe-1 sm:grid-cols-3">
                    <button
                        v-for="th in themes"
                        :key="th.key"
                        type="button"
                        class="relative flex items-center gap-2 rounded-lg border p-2 text-start text-xs transition"
                        :class="form.theme === th.key ? 'border-[var(--accent-600)] ring-2 ring-[var(--accent-400)]' : 'border-gray-200 hover:border-gray-300 dark:border-gray-600'"
                        @click="pickTheme(th)"
                    >
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :style="{ background: th.primary, color: th.secondary }">
                            <FolderThemeIcon :icon="th.icon" class="h-5 w-5" />
                        </span>
                        <span class="line-clamp-2 leading-snug text-gray-700 dark:text-gray-200">{{ th.name }}</span>
                        <CheckIcon v-if="form.theme === th.key" class="absolute end-1 top-1 h-4 w-4 text-[var(--accent-600)]" />
                    </button>
                </div>
            </section>

            <!-- Colour -->
            <section>
                <h3 class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ t('folder_look.color', 'اللون') }}</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="flex h-8 items-center rounded-full border px-3 text-xs"
                        :class="!form.color ? 'border-[var(--accent-600)] text-[var(--accent-700)]' : 'border-gray-300 text-gray-500 dark:border-gray-600'"
                        @click="form.color = ''"
                    >{{ form.theme ? t('folder_look.theme_color', 'لون الثيم') : t('folder_look.default_color', 'الافتراضي') }}</button>
                    <button
                        v-for="c in COLOR_SWATCHES"
                        :key="c"
                        type="button"
                        class="h-8 w-8 rounded-full border-2 transition"
                        :class="form.color === c ? 'scale-110 border-gray-900 dark:border-white' : 'border-white dark:border-gray-800'"
                        :style="{ background: c }"
                        :aria-label="c"
                        @click="form.color = c"
                    />
                    <label class="flex h-8 cursor-pointer items-center gap-1 rounded-full border border-gray-300 px-2 text-xs text-gray-600 dark:border-gray-600 dark:text-gray-300">
                        <input v-model="form.color" type="color" class="h-5 w-5 cursor-pointer rounded border-0 bg-transparent p-0" />
                        {{ t('folder_look.custom', 'لون آخر') }}
                    </label>
                </div>
                <InputError :message="form.errors.color" class="mt-1" />
            </section>

            <!-- Logo and cover -->
            <section class="grid gap-3 sm:grid-cols-2">
                <div v-for="kind in ['logo', 'cover']" :key="kind" class="rounded-lg border border-dashed border-gray-300 p-3 dark:border-gray-600">
                    <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ kind === 'logo' ? t('folder_look.logo', 'الشعار') : t('folder_look.cover', 'صورة الغلاف') }}
                    </p>
                    <div class="flex items-center gap-2">
                        <span class="flex h-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-100 dark:bg-gray-700" :class="kind === 'logo' ? 'w-12' : 'w-20'">
                            <img v-if="kind === 'logo' ? logoPreview : coverPreview" :src="kind === 'logo' ? logoPreview : coverPreview" alt="" class="h-full w-full" :class="kind === 'logo' ? 'object-contain' : 'object-cover'" />
                            <PhotoIcon v-else class="h-6 w-6 text-gray-400" />
                        </span>
                        <label class="cursor-pointer rounded-md border border-gray-300 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            {{ t('folder_look.choose', 'اختيار صورة') }}
                            <input type="file" class="hidden" :accept="kind === 'logo' ? 'image/png,image/jpeg,image/webp,image/svg+xml' : 'image/png,image/jpeg,image/webp'" @change="onFile(kind, $event)" />
                        </label>
                        <button v-if="kind === 'logo' ? logoPreview : coverPreview" type="button" class="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-gray-700" :title="t('action.delete', 'حذف')" @click="removeImage(kind)">
                            <TrashIcon class="h-4 w-4" />
                        </button>
                    </div>
                    <p class="mt-1 text-[11px] text-gray-400">{{ kind === 'logo' ? t('folder_look.logo_hint', 'PNG شفاف أفضل، حتى 3 ميغا') : t('folder_look.cover_hint', 'صورة عريضة (مثل 1600×500)، حتى 8 ميغا') }}</p>
                    <InputError :message="form.errors[kind]" class="mt-1" />
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                <button type="button" class="text-sm text-gray-500 hover:underline" @click="resetAll">{{ t('folder_look.reset', 'إرجاع المظهر الافتراضي') }}</button>
                <button type="button" class="ms-auto rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" @click="emit('close')">{{ t('action.cancel', 'إلغاء') }}</button>
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50">{{ t('action.save', 'حفظ') }}</button>
            </div>
        </form>
    </Modal>
</template>
