<script setup>
// Add or edit an interface language: name, code, direction, flag or icon, on/off.
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import FlagIcon from '@/Components/FlagIcon.vue';
import { FLAG_CODES, LANGUAGE_ICONS, countryName } from '@/flags';
import { useTrans } from '@/Composables/useTrans';
import { XMarkIcon, MagnifyingGlassIcon, TrashIcon, CheckIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ show: Boolean, language: { type: Object, default: null } });
const emit = defineEmits(['close']);

// Common choices first, so most people never need to search.
const SUGGESTED = [
    { code: 'ku', name: 'Kurdî', dir: 'ltr', flag: 'iq' },
    { code: 'ckb', name: 'کوردی', dir: 'rtl', flag: 'iq' },
    { code: 'tr', name: 'Türkçe', dir: 'ltr', flag: 'tr' },
    { code: 'fa', name: 'فارسی', dir: 'rtl', flag: 'ir' },
    { code: 'fr', name: 'Français', dir: 'ltr', flag: 'fr' },
    { code: 'de', name: 'Deutsch', dir: 'ltr', flag: 'de' },
    { code: 'es', name: 'Español', dir: 'ltr', flag: 'es' },
    { code: 'ur', name: 'اردو', dir: 'rtl', flag: 'pk' },
];

const form = useForm({ code: '', name: '', dir: 'ltr', flag: null, is_active: true });
const tab = ref('flag');
const search = ref('');
const editing = computed(() => Boolean(props.language));

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    const l = props.language;
    form.code = l?.code ?? '';
    form.name = l?.name ?? '';
    form.dir = l?.dir ?? 'ltr';
    form.flag = l?.flag ?? null;
    form.is_active = l?.is_active ?? true;
    tab.value = form.flag?.startsWith('icon:') ? 'icon' : 'flag';
    search.value = '';
});

const flags = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = FLAG_CODES.map((c) => ({ code: c, name: countryName(c) }));
    return q ? list.filter((f) => f.code.includes(q) || f.name.toLowerCase().includes(q) || countryName(f.code, 'en').toLowerCase().includes(q)) : list;
});

function pickSuggested(s) {
    form.code = s.code;
    form.name = s.name;
    form.dir = s.dir;
    form.flag = s.flag;
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    if (editing.value) form.patch(route('admin.languages.update', props.language.id), opts);
    else form.post(route('admin.languages.store'), opts);
}

function destroy() {
    if (!confirm(t('languages.confirm_delete', 'حذف هذه اللغة وكل ترجماتها؟ من يستخدمها يرجع للغة الافتراضية.'))) return;
    router.delete(route('admin.languages.destroy', props.language.id), { onSuccess: () => emit('close') });
}

const inputClass = 'block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-[var(--accent-600)] focus:ring-[var(--accent-600)] dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <form @submit.prevent="submit" class="flex max-h-[90vh] flex-col">
            <header class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <FlagIcon :flag="form.flag" size="h-7 w-10" />
                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ editing ? t('languages.edit', 'تعديل اللغة') : t('languages.add', 'إضافة لغة') }}</h2>
                    <p class="truncate text-xs text-gray-500">{{ form.name || t('languages.new_hint', 'اسم اللغة كما يظهر للمستخدمين') }}</p>
                </div>
                <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" @click="emit('close')"><XMarkIcon class="h-5 w-5" /></button>
            </header>

            <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                <div v-if="!editing">
                    <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('languages.suggested', 'اختيار سريع') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in SUGGESTED" :key="s.code" type="button" @click="pickSuggested(s)"
                            class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition"
                            :class="form.code === s.code ? 'border-[var(--accent-600)] bg-indigo-50 text-[var(--accent-600)] dark:bg-indigo-900/30' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/40'">
                            <FlagIcon :flag="s.flag" size="h-3.5 w-5" /> {{ s.name }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('languages.name', 'اسم اللغة') }}</span>
                        <input v-model="form.name" required maxlength="60" :class="inputClass" :placeholder="t('languages.name_ph', 'مثال: Türkçe')" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('languages.code', 'رمز اللغة') }}</span>
                        <input v-model="form.code" :disabled="editing" required maxlength="5" dir="ltr" placeholder="tr" :class="[inputClass, 'font-mono disabled:bg-gray-100 disabled:text-gray-500 dark:disabled:bg-gray-800']" />
                        <span class="mt-1 block text-xs text-gray-400">{{ t('languages.code_hint', 'حرفان أو ثلاثة بالإنجليزي (ISO)، مثل tr أو ckb') }}</span>
                        <InputError :message="form.errors.code" class="mt-1" />
                    </label>
                </div>

                <div>
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('languages.dir', 'اتجاه الكتابة') }}</span>
                    <div class="grid grid-cols-2 gap-2">
                        <button v-for="d in [['rtl', t('languages.rtl', 'من اليمين لليسار')], ['ltr', t('languages.ltr', 'من اليسار لليمين')]]" :key="d[0]" type="button" @click="form.dir = d[0]"
                            class="rounded-xl border px-3 py-2.5 text-sm transition"
                            :class="form.dir === d[0] ? 'border-[var(--accent-600)] bg-indigo-50 font-semibold text-[var(--accent-600)] ring-1 ring-[var(--accent-600)] dark:bg-indigo-900/30' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/40'">
                            {{ d[1] }}
                        </button>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('languages.icon', 'أيقونة اللغة') }}</span>
                        <div class="grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1 text-xs dark:bg-gray-900">
                            <button type="button" @click="tab = 'flag'" class="rounded-lg px-3 py-1" :class="tab === 'flag' ? 'bg-white font-semibold shadow-sm dark:bg-gray-700' : 'text-gray-500'">{{ t('languages.flag', 'علم البلد') }}</button>
                            <button type="button" @click="tab = 'icon'" class="rounded-lg px-3 py-1" :class="tab === 'icon' ? 'bg-white font-semibold shadow-sm dark:bg-gray-700' : 'text-gray-500'">{{ t('languages.plain_icon', 'أيقونة') }}</button>
                        </div>
                    </div>

                    <template v-if="tab === 'flag'">
                        <div class="relative mb-2">
                            <MagnifyingGlassIcon class="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-gray-400" />
                            <input v-model="search" :placeholder="t('languages.search_country', 'ابحث عن بلد…')" :class="[inputClass, 'ps-9']" />
                        </div>
                        <div class="grid max-h-56 grid-cols-3 gap-1.5 overflow-y-auto rounded-xl border border-gray-200 p-2 sm:grid-cols-4 dark:border-gray-700">
                            <button v-for="f in flags" :key="f.code" type="button" @click="form.flag = f.code" :title="f.name"
                                class="relative flex flex-col items-center gap-1 rounded-lg px-1 py-2 text-center transition"
                                :class="form.flag === f.code ? 'bg-indigo-50 ring-2 ring-[var(--accent-600)] dark:bg-indigo-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700/40'">
                                <FlagIcon :flag="f.code" size="h-6 w-8" />
                                <span class="line-clamp-1 w-full text-[11px] text-gray-600 dark:text-gray-300">{{ f.name }}</span>
                                <CheckIcon v-if="form.flag === f.code" class="absolute end-1 top-1 h-3.5 w-3.5 text-[var(--accent-600)]" />
                            </button>
                            <p v-if="!flags.length" class="col-span-full py-4 text-center text-sm text-gray-400">{{ t('languages.no_country', 'لا يوجد بلد بهذا الاسم') }}</p>
                        </div>
                    </template>
                    <div v-else class="grid grid-cols-5 gap-2">
                        <button v-for="(comp, name) in LANGUAGE_ICONS" :key="name" type="button" @click="form.flag = `icon:${name}`"
                            class="flex items-center justify-center rounded-xl border py-3 transition"
                            :class="form.flag === `icon:${name}` ? 'border-[var(--accent-600)] bg-indigo-50 text-[var(--accent-600)] ring-1 ring-[var(--accent-600)] dark:bg-indigo-900/30' : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300'">
                            <component :is="comp" class="h-6 w-6" />
                        </button>
                    </div>
                    <InputError :message="form.errors.flag" class="mt-1" />
                </div>

                <label class="flex items-center justify-between gap-4 rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-900/40">
                    <span>
                        <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ t('languages.active', 'متاحة للمستخدمين') }}</span>
                        <span class="block text-xs text-gray-500">{{ t('languages.active_hint', 'عند الإيقاف تختفي من اختيار اللغة، ويرجع مستخدموها للغة الافتراضية') }}</span>
                    </span>
                    <ToggleSwitch v-model="form.is_active" tone="green" />
                </label>
                <InputError :message="form.errors.is_active" />
            </div>

            <footer class="flex items-center gap-2 border-t border-gray-100 px-5 py-3 dark:border-gray-700">
                <button v-if="editing && !language.builtin && !language.is_default" type="button" @click="destroy" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">
                    <TrashIcon class="h-4 w-4" /> {{ t('action.delete', 'حذف') }}
                </button>
                <span class="flex-1" />
                <button type="button" @click="emit('close')" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                <button type="submit" :disabled="form.processing" class="rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">
                    {{ editing ? t('action.save', 'حفظ') : t('languages.add_btn', 'إضافة اللغة') }}
                </button>
            </footer>
        </form>
    </Modal>
</template>
