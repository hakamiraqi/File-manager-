<script setup>
import { ref, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { useTrans } from '@/Composables/useTrans';
import { timeAgo } from '@/notifications';
import {
    ChatBubbleLeftRightIcon, MagnifyingGlassIcon, QuestionMarkCircleIcon, BookOpenIcon, AcademicCapIcon,
    LightBulbIcon, ExclamationTriangleIcon, EllipsisHorizontalCircleIcon, EnvelopeIcon, PhoneIcon,
    TrashIcon, ChevronDownIcon, ArrowUturnLeftIcon, InboxIcon, UserIcon,
} from '@heroicons/vue/24/outline';

// «التواصل معنا»: what visitors and users sent, with status and reply.
const { t } = useTrans();
const props = defineProps({ items: Object, filters: Object, counts: Object, kinds: Object, statuses: Object });

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
const tabs = [
    { key: 'open', label: t('contact.tab_open', 'المفتوحة') },
    { key: 'new', label: t('contact.status.new', 'جديد') },
    { key: 'in_progress', label: t('contact.status.in_progress', 'قيد المتابعة') },
    { key: 'closed', label: t('contact.status.closed', 'مغلق') },
    { key: 'all', label: t('contact.tab_all', 'الكل') },
];

const q = ref(props.filters.q ?? '');
function go(changes) {
    const params = { status: props.filters.status, kind: props.filters.kind, q: q.value || undefined, ...changes };
    Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
    router.get(route('contact.inbox'), params, { preserveState: true, preserveScroll: true });
}

const expanded = ref(props.filters.open ?? null);
const drafts = reactive({});
function toggle(m) {
    expanded.value = expanded.value === m.id ? null : m.id;
    if (!drafts[m.id]) drafts[m.id] = { status: m.status === 'new' ? 'in_progress' : m.status, reply: m.reply ?? '' };
}
if (expanded.value) {
    const m = props.items.data.find((x) => x.id === expanded.value);
    if (m) drafts[m.id] = { status: m.status === 'new' ? 'in_progress' : m.status, reply: m.reply ?? '' };
}

const saving = ref(null);
function save(m, status) {
    const d = drafts[m.id];
    saving.value = m.id;
    router.patch(route('contact.update', m.id), { status: status ?? d.status, reply: d.reply || null }, {
        preserveScroll: true,
        onFinish: () => (saving.value = null),
    });
}
function remove(m) {
    if (!confirm(t('contact.delete_confirm', 'حذف هذه الرسالة نهائياً؟'))) return;
    router.delete(route('contact.destroy', m.id), { preserveScroll: true });
}
const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ar-IQ', { dateStyle: 'medium', timeStyle: 'short' }) : '');
const aboutHref = (m) => (m.about_type === 'book' ? route('library.books.show', m.about_id) : route('dashboard', { folder: m.about_id }));
</script>

<template>
    <Head :title="t('nav.contact_inbox', 'التواصل معنا')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <h2 class="hidden items-center gap-2 text-lg font-semibold text-gray-800 sm:flex dark:text-gray-100">
                    <ChatBubbleLeftRightIcon class="h-5 w-5 text-gray-500" /> {{ t('nav.contact_inbox', 'التواصل معنا') }}
                </h2>
                <div class="relative ms-auto w-full max-w-xs">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-2.5 top-2.5 h-4 w-4 text-gray-400" />
                    <input v-model="q" type="search" @keyup.enter="go({ page: undefined })" :placeholder="t('contact.search', 'ابحث بالاسم أو الموضوع أو البريد...')"
                        class="w-full rounded-full border-gray-300 py-1.5 ps-8 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                </div>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <!-- Status tabs + kind -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex gap-1 overflow-x-auto rounded-xl bg-gray-100 p-1 dark:bg-gray-800">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        @click="go({ status: tab.key, page: undefined })"
                        class="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="filters.status === tab.key ? 'bg-white text-[var(--accent-700)] shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                    >
                        {{ tab.label }}
                        <span class="rounded-full px-1.5 text-xs" :class="tab.key === 'new' && counts.new ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-600 dark:text-gray-200'">{{ counts[tab.key] }}</span>
                    </button>
                </div>
                <select :value="filters.kind || ''" @change="go({ kind: $event.target.value || undefined, page: undefined })" class="ms-auto rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <option value="">{{ t('contact.all_kinds', 'كل الأنواع') }}</option>
                    <option v-for="(label, key) in kinds" :key="key" :value="key">{{ t('contact.kind.' + key, label) }}</option>
                </select>
            </div>

            <div v-if="!items.data.length" class="rounded-2xl border border-dashed border-gray-300 py-16 text-center text-gray-400 dark:border-gray-600">
                <InboxIcon class="mx-auto mb-2 h-10 w-10" />
                {{ t('contact.empty', 'لا توجد رسائل هنا.') }}
            </div>

            <article
                v-for="m in items.data"
                :key="m.id"
                class="overflow-hidden rounded-2xl border bg-white dark:bg-gray-800"
                :class="m.status === 'new' ? 'border-sky-300 dark:border-sky-700' : 'border-gray-200 dark:border-gray-700'"
            >
                <button type="button" @click="toggle(m)" class="flex w-full items-start gap-3 p-4 text-start hover:bg-gray-50 dark:hover:bg-gray-700/40">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--accent-50)] text-[var(--accent-600)] dark:bg-gray-700">
                        <component :is="ICONS[m.kind]" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-semibold text-gray-900 dark:text-gray-100" :class="m.status === 'new' ? '' : 'font-medium'" dir="auto">{{ m.subject }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="STATUS_LOOK[m.status]">{{ t('contact.status.' + m.status, statuses[m.status]) }}</span>
                        </span>
                        <span class="mt-0.5 block truncate text-xs text-gray-500">
                            #{{ m.id }} · {{ t('contact.kind.' + m.kind, kinds[m.kind]) }} · {{ m.name }}<template v-if="m.user"> ({{ t('contact.member', 'مستخدم') }})</template> · {{ timeAgo(m.created_at) }}
                        </span>
                        <span v-if="expanded !== m.id" class="mt-1 line-clamp-1 block text-sm text-gray-600 dark:text-gray-300" dir="auto">{{ m.message }}</span>
                    </span>
                    <ChevronDownIcon class="mt-2 h-4 w-4 shrink-0 text-gray-400 transition" :class="expanded === m.id ? 'rotate-180' : ''" />
                </button>

                <div v-if="expanded === m.id" class="space-y-4 border-t border-gray-100 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <span v-if="m.user" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 dark:bg-gray-700"><UserAvatar :user="m.user" size="h-5 w-5 text-[10px]" /> {{ m.user.name }}</span>
                        <span v-else class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 dark:bg-gray-700"><UserIcon class="h-4 w-4" /> {{ m.name }} · {{ t('contact.visitor', 'زائر') }}</span>
                        <a v-if="m.email" :href="'mailto:' + m.email" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 hover:underline dark:bg-gray-700" dir="ltr"><EnvelopeIcon class="h-4 w-4" /> {{ m.email }}</a>
                        <a v-if="m.phone" :href="'tel:' + m.phone" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 hover:underline dark:bg-gray-700" dir="ltr"><PhoneIcon class="h-4 w-4" /> {{ m.phone }}</a>
                        <Link v-if="m.about_type && m.about_title" :href="aboutHref(m)" class="flex items-center gap-1.5 rounded-lg bg-[var(--accent-50)] px-2.5 py-1 text-[var(--accent-700)] hover:underline dark:bg-gray-700 dark:text-gray-100">
                            <component :is="m.about_type === 'book' ? BookOpenIcon : AcademicCapIcon" class="h-4 w-4" /> <span dir="auto">{{ m.about_title }}</span>
                        </Link>
                        <span class="flex items-center px-1 text-xs text-gray-400">{{ fmt(m.created_at) }}</span>
                    </div>

                    <p class="whitespace-pre-line rounded-xl bg-gray-50 p-3 text-sm leading-7 text-gray-800 dark:bg-gray-900/40 dark:text-gray-200" dir="auto">{{ m.message }}</p>

                    <p v-if="m.replied_at" class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400">
                        <ArrowUturnLeftIcon class="h-3.5 w-3.5" /> {{ t('contact.replied_by', 'رُدّ عليه :when', { when: fmt(m.replied_at) }) }}<template v-if="m.handler"> · {{ m.handler }}</template>
                    </p>

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">{{ t('contact.reply', 'الرد') }}</span>
                        <textarea v-model="drafts[m.id].reply" rows="4" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" dir="auto"
                            :placeholder="m.email || m.user ? t('contact.reply_ph', 'يوصل الرد لصاحب الرسالة بالبريد والإشعارات.') : t('contact.reply_ph_none', 'لا يوجد بريد: اتصل على الهاتف، واكتب هنا ملاحظتك.')"></textarea>
                    </label>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 text-sm dark:border-gray-600">
                            <button v-for="(label, key) in statuses" :key="key" type="button" @click="drafts[m.id].status = key"
                                class="px-3 py-1.5" :class="drafts[m.id].status === key ? 'bg-[var(--accent-600)] text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                                {{ t('contact.status.' + key, label) }}
                            </button>
                        </div>
                        <button @click="save(m)" :disabled="saving === m.id" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50">
                            {{ drafts[m.id].reply && drafts[m.id].reply !== (m.reply ?? '') ? t('contact.save_send', 'حفظ وإرسال الرد') : t('action.save', 'حفظ') }}
                        </button>
                        <button @click="remove(m)" class="ms-auto rounded-lg p-2 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" :title="t('action.delete', 'حذف')"><TrashIcon class="h-5 w-5" /></button>
                    </div>
                </div>
            </article>

            <Pagination :links="items.links" />
        </div>
    </AppLayout>
</template>
