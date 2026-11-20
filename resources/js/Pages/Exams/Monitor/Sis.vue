<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { AcademicCapIcon, ArrowPathIcon, ArrowRightIcon, CheckIcon, ExclamationTriangleIcon, LinkIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ sis: Object, groups: Array, pushes: Array });
const form = useForm({ url: props.sis.url ?? '', key: '' });
const connect = () => form.post(route('exams.monitor.sis.connect'), { preserveScroll: true, onSuccess: () => form.reset('key') });
const refresh = () => router.post(route('exams.monitor.sis.refresh'), {}, { preserveScroll: true });
const disconnect = () => confirm(t('exam.sis_disconnect_q', 'إلغاء ربط نظام الطلبة؟')) && router.delete(route('exams.monitor.sis.disconnect'), { preserveScroll: true });
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar') : '—');
const input = 'rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700';
const STATUS = { pending: ['بانتظار التدريسي', 'bg-amber-50 text-amber-800'], applied: ['أُدخلت في السجل', 'bg-emerald-50 text-emerald-700'], rejected: ['رفضها التدريسي', 'bg-red-50 text-red-700'], superseded: ['استُبدلت', 'bg-gray-100 text-gray-600'] };
</script>

<template>
    <Head :title="t('exam.sis', 'ربط نظام الطلبة')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.monitor.index')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="flex items-center gap-2 text-lg font-semibold"><AcademicCapIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.sis', 'ربط نظام الطلبة') }}</h2>
            </div>
        </template>
        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
            <form @submit.prevent="connect" class="space-y-3 rounded-xl border bg-white p-4 text-sm dark:bg-gray-800" :class="sis.connected ? 'border-[var(--accent-500)]' : 'border-gray-200 dark:border-gray-700'">
                <p class="flex items-center gap-2 font-semibold">
                    {{ t('exam.sis_title', 'نظام الطلبة (SIS)') }}
                    <span v-if="sis.connected" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ t('exam.sis_on', 'مربوط') }}</span>
                    <span v-if="sis.university" class="text-xs font-normal text-gray-500">— {{ sis.university }}</span>
                </p>
                <p class="text-xs leading-5 text-gray-500">{{ t('exam.sis_hint', 'في نظام الطلبة: الموصلات ← نظام الامتحانات ← ربط، ثم انسخ المفتاح وعنوان الواجهة هنا. بعدها يأخذ الأساتذة الشعب وطلبتها كمجموعات، ويرسلون درجات الامتحان إلى مكوّن في المادة، ولا تدخل السجل هناك إلا بعد اعتماد تدريسي المادة.') }}</p>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.sis_url', 'عنوان الواجهة') }}</span>
                    <input v-model="form.url" dir="ltr" placeholder="https://sis.lan/api/connectors/v1/exams" :class="input" class="w-full font-mono" required /></label>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.sis_key', 'المفتاح') }}<template v-if="sis.connected"> ({{ t('settings.saved', 'محفوظ') }})</template></span>
                    <input v-model="form.key" type="password" dir="ltr" autocomplete="off" placeholder="sisc_…" :class="input" class="w-full font-mono" :required="!sis.connected" /></label>
                <p v-if="form.errors.url || form.errors.key" class="text-xs text-red-600">{{ form.errors.url || form.errors.key }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <button :disabled="form.processing" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white disabled:opacity-50"><LinkIcon class="h-4 w-4" /> {{ form.processing ? t('exam.sis_testing', 'جارٍ الاختبار…') : t('exam.sis_connect', 'حفظ واختبار') }}</button>
                    <template v-if="sis.connected">
                        <button type="button" @click="refresh" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" /> {{ t('exam.sis_test', 'اختبار الاتصال') }}</button>
                        <button type="button" @click="disconnect" class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-red-600 dark:border-gray-600">{{ t('exam.sis_disconnect', 'إلغاء الربط') }}</button>
                    </template>
                </div>
                <div v-if="sis.connected" class="space-y-1 border-t border-gray-100 pt-3 text-xs dark:border-gray-700">
                    <p class="flex items-center gap-1"><component :is="sis.rosters ? CheckIcon : ExclamationTriangleIcon" class="h-4 w-4" :class="sis.rosters ? 'text-emerald-600' : 'text-amber-600'" /> {{ t('exam.sis_tool_rosters', 'المواد وقوائم الطلبة') }}: {{ sis.rosters ? t('exam.sis_tool_on', 'مفعّلة') : t('exam.sis_tool_off', 'متوقفة في نظام الطلبة') }}</p>
                    <p class="flex items-center gap-1"><component :is="sis.marks ? CheckIcon : ExclamationTriangleIcon" class="h-4 w-4" :class="sis.marks ? 'text-emerald-600' : 'text-amber-600'" /> {{ t('exam.sis_tool_marks', 'إرسال الدرجات') }}: {{ sis.marks ? t('exam.sis_tool_on', 'مفعّلة') : t('exam.sis_tool_off', 'متوقفة في نظام الطلبة') }}</p>
                    <p class="text-gray-500">{{ t('exam.sis_last_ok', 'آخر اتصال ناجح: :at', { at: fmt(sis.last_ok) }) }}</p>
                    <p v-if="sis.error" class="text-red-600">{{ sis.error }}</p>
                </div>
            </form>

            <section v-if="groups.length" class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="mb-2 font-semibold">{{ t('exam.sis_groups', 'مجموعات من نظام الطلبة') }}</p>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="g in groups" :key="g.id" class="flex items-center justify-between py-1.5"><Link :href="route('exams.manage.groups', { group: g.id })" class="hover:underline">{{ g.name }}</Link><span class="text-xs text-gray-500">{{ g.members }}</span></li>
                </ul>
            </section>

            <section v-if="pushes.length" class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="mb-2 font-semibold">{{ t('exam.sis_pushes', 'آخر ما أُرسل من درجات') }}</p>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="p in pushes" :key="p.id" class="flex flex-wrap items-center gap-2 py-1.5">
                        <Link :href="route('exams.manage.results', p.exam_id)" class="font-medium hover:underline">{{ p.exam }}</Link>
                        <span class="text-xs text-gray-500">→ {{ p.offering }} · {{ p.component }} · {{ p.sent }}</span>
                        <span class="ms-auto rounded-full px-2 py-0.5 text-xs" :class="STATUS[p.status]?.[1]">{{ t('exam.sis_status_' + p.status, STATUS[p.status]?.[0] ?? p.status) }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
