<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestShell from '@/Components/GuestShell.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    ChatBubbleLeftRightIcon, QuestionMarkCircleIcon, BookOpenIcon, AcademicCapIcon, LightBulbIcon,
    ExclamationTriangleIcon, EllipsisHorizontalCircleIcon, PaperAirplaneIcon, CheckCircleIcon,
    ArrowUturnLeftIcon, ClockIcon, XMarkIcon,
} from '@heroicons/vue/24/outline';

// «تواصل معنا»: a visitor or a user sends an inquiry or a request; the staff see it in their inbox.
const { t } = useTrans();
const page = usePage();
const props = defineProps({ kinds: Object, about: Object, mine: Array, statuses: Object });
const user = computed(() => page.props.auth?.user);
const Layout = computed(() => (user.value ? AppLayout : GuestShell));

const ICONS = {
    inquiry: QuestionMarkCircleIcon,
    book_request: BookOpenIcon,
    thesis_request: AcademicCapIcon,
    suggestion: LightBulbIcon,
    complaint: ExclamationTriangleIcon,
    other: EllipsisHorizontalCircleIcon,
};
const STATUS_LOOK = {
    new: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    in_progress: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    closed: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
};

const form = useForm({
    kind: props.about?.kind ?? 'inquiry',
    name: '',
    email: '',
    phone: '',
    subject: props.about ? `${props.kinds[props.about.kind]}: ${props.about.title}` : '',
    message: '',
    about_type: props.about?.type ?? null,
    about_id: props.about?.id ?? null,
    website: '',
});

function clearAbout() {
    form.about_type = null;
    form.about_id = null;
}

function submit() {
    form.post(route('contact.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('subject', 'message');
            clearAbout();
        },
    });
}

const sent = computed(() => page.props.flash?.success);
const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ar-IQ', { dateStyle: 'medium', timeStyle: 'short' }) : '');
</script>

<template>
    <Head :title="t('nav.contact', 'تواصل معنا')" />

    <component :is="Layout">
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                <ChatBubbleLeftRightIcon class="h-5 w-5 text-gray-500" /> {{ t('nav.contact', 'تواصل معنا') }}
            </h2>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('contact.intro', 'اكتب لنا استفسارك أو اطلب كتاباً أو رسالة غير متوفرة، وسيتابعه فريق المكتبة ويرد عليك.') }}</p>

            <div v-if="sent" class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                <CheckCircleIcon class="h-6 w-6 shrink-0" />
                <p class="text-sm font-medium">{{ sent }}</p>
            </div>

            <form @submit.prevent="submit" class="space-y-5 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                <!-- What kind -->
                <div>
                    <p class="mb-2 text-sm font-medium">{{ t('contact.kind', 'نوع الرسالة') }}</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <button
                            v-for="(label, key) in kinds"
                            :key="key"
                            type="button"
                            @click="form.kind = key"
                            class="flex items-center gap-2 rounded-xl border px-3 py-2.5 text-start text-sm font-medium transition"
                            :class="form.kind === key ? 'border-[var(--accent-600)] bg-[var(--accent-50)] text-[var(--accent-700)] ring-1 ring-[var(--accent-600)] dark:bg-gray-700 dark:text-white' : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            <component :is="ICONS[key]" class="h-5 w-5 shrink-0" /> {{ t('contact.kind.' + key, label) }}
                        </button>
                    </div>
                </div>

                <div v-if="form.about_id && about" class="flex items-center gap-3 rounded-xl bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/40">
                    <component :is="about.type === 'book' ? BookOpenIcon : AcademicCapIcon" class="h-5 w-5 shrink-0 text-gray-500" />
                    <span class="min-w-0 flex-1 truncate" dir="auto">{{ about.title }}</span>
                    <button type="button" @click="clearAbout" class="rounded p-1 text-gray-400 hover:text-gray-700" :title="t('action.remove', 'إزالة')"><XMarkIcon class="h-4 w-4" /></button>
                </div>

                <!-- Who -->
                <div v-if="!user" class="grid gap-4 sm:grid-cols-3">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">{{ t('contact.name', 'الاسم') }} <span class="text-red-500">*</span></span>
                        <input v-model="form.name" type="text" autocomplete="name" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="form.errors.name" class="mt-1 block text-xs text-red-600">{{ form.errors.name }}</span>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">{{ t('contact.email', 'البريد الإلكتروني') }}</span>
                        <input v-model="form.email" type="email" dir="ltr" autocomplete="email" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="form.errors.email" class="mt-1 block text-xs text-red-600">{{ form.errors.email }}</span>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">{{ t('contact.phone', 'رقم الهاتف') }}</span>
                        <input v-model="form.phone" type="tel" dir="ltr" autocomplete="tel" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="form.errors.phone" class="mt-1 block text-xs text-red-600">{{ form.errors.phone }}</span>
                    </label>
                    <p class="text-xs text-gray-500 sm:col-span-3">{{ t('contact.reach_hint', 'اكتب بريدك أو هاتفك (واحد منهما على الأقل) حتى نرد عليك.') }}</p>
                </div>
                <p v-else class="text-sm text-gray-500">{{ t('contact.as_user', 'ترسل باسم :name، والرد يوصلك بالإشعارات والبريد.', { name: user.name }) }}</p>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">{{ t('contact.subject', 'الموضوع') }} <span class="text-red-500">*</span></span>
                    <input v-model="form.subject" type="text" maxlength="190" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" dir="auto" />
                    <span v-if="form.errors.subject" class="mt-1 block text-xs text-red-600">{{ form.errors.subject }}</span>
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">{{ t('contact.message', 'الرسالة') }} <span class="text-red-500">*</span></span>
                    <textarea v-model="form.message" rows="6" maxlength="5000" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" dir="auto"
                        :placeholder="form.kind === 'book_request' ? t('contact.book_ph', 'اسم الكتاب، المؤلف، الطبعة إن وجدت...') : form.kind === 'thesis_request' ? t('contact.thesis_ph', 'عنوان الرسالة أو الأطروحة، الباحث، السنة...') : ''"></textarea>
                    <span v-if="form.errors.message" class="mt-1 block text-xs text-red-600">{{ form.errors.message }}</span>
                </label>

                <!-- Bots fill this in; people never see it. -->
                <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true" />

                <div class="flex justify-end">
                    <button :disabled="form.processing" class="flex items-center gap-2 rounded-xl bg-[var(--accent-600)] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[var(--accent-700)] disabled:opacity-50">
                        <PaperAirplaneIcon class="h-4 w-4 rtl:-scale-x-100" /> {{ t('contact.send', 'إرسال') }}
                    </button>
                </div>
            </form>

            <!-- My messages -->
            <section v-if="mine.length" class="space-y-3">
                <h3 class="font-semibold">{{ t('contact.mine', 'رسائلي السابقة') }}</h3>
                <article v-for="m in mine" :key="m.id" class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <component :is="ICONS[m.kind]" class="h-5 w-5 text-gray-400" />
                        <span class="min-w-0 flex-1 truncate font-medium" dir="auto">#{{ m.id }} · {{ m.subject }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_LOOK[m.status]">{{ statuses[m.status] }}</span>
                    </div>
                    <p class="mt-1 flex items-center gap-1 text-xs text-gray-400"><ClockIcon class="h-3.5 w-3.5" /> {{ fmt(m.created_at) }}</p>
                    <div v-if="m.reply" class="mt-3 flex gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200">
                        <ArrowUturnLeftIcon class="mt-0.5 h-4 w-4 shrink-0" />
                        <p class="whitespace-pre-line" dir="auto">{{ m.reply }}</p>
                    </div>
                </article>
            </section>
        </div>
    </component>
</template>
