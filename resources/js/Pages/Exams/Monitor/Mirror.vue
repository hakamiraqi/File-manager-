<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ServerStackIcon, KeyIcon, ArrowPathIcon, CheckIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

// The standby server: on the main server make a key; on the standby give the main server's
// address and that key. The standby then copies the main one every minute.
const props = defineProps({ main: Object, standby: Object, address: String });
const { t } = useTrans();
const form = useForm({ from: props.standby.from ?? '', key: '' });
const save = () => form.post(route('exams.monitor.mirror.standby'), { preserveScroll: true, onSuccess: () => form.reset('key') });
const newKey = () => router.post(route('exams.monitor.mirror.key'), {}, { preserveScroll: true });
const keyOff = () => router.post(route('exams.monitor.mirror.key'), { off: true }, { preserveScroll: true });
const pullNow = () => router.post(route('exams.monitor.mirror.standby'), { now: true }, { preserveScroll: true });
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar') : '—');
const input = 'rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700';
</script>

<template>
    <Head :title="t('exam.mirror', 'السيرفر الاحتياطي')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.monitor.index')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="flex items-center gap-2 text-lg font-semibold"><ServerStackIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.mirror', 'السيرفر الاحتياطي') }}</h2>
            </div>
        </template>
        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.mirror_intro', 'حاسبة ثانية في الشبكة تحمل نسخة السيرفر نفسها، وتأخذ كل دقيقة ما تغيّر على السيرفر الرئيسي: الحسابات والامتحانات والمحاولات والإجابات. إذا تعطّل الرئيسي، وجّه حواسيب القاعة إلى الاحتياطي فيكمل الطلاب من آخر دقيقة. الملفات المرفوعة (الصور وملفات الإجابات) تنتقل بالنسخ الاحتياطية العادية.') }}</p>

            <section class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="font-semibold">{{ t('exam.mirror_main', 'هذا هو السيرفر الرئيسي') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.mirror_main_hint', 'اصنع مفتاحاً، ثم اكتب في السيرفر الاحتياطي هذا العنوان والمفتاح.') }} <span class="font-mono" dir="ltr">{{ address }}</span></p>
                <p v-if="main.key" class="rounded-lg bg-amber-50 p-3 font-mono text-base dark:bg-amber-900/30" dir="ltr">{{ main.key }}</p>
                <div class="flex gap-2">
                    <button @click="newKey" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600"><KeyIcon class="h-4 w-4" /> {{ main.enabled ? t('exam.mirror_new_key', 'مفتاح جديد') : t('exam.mirror_enable', 'تفعيل وصنع مفتاح') }}</button>
                    <button v-if="main.enabled" @click="keyOff" class="rounded-lg border border-gray-300 px-3 py-1.5 text-red-600 dark:border-gray-600">{{ t('exam.mirror_off', 'إيقاف') }}</button>
                </div>
            </section>

            <form @submit.prevent="save" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="font-semibold">{{ t('exam.mirror_standby', 'هذا سيرفر احتياطي') }}</p>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.mirror_from', 'عنوان السيرفر الرئيسي') }}</span><input v-model="form.from" dir="ltr" placeholder="http://192.168.1.10" :class="input" class="w-full font-mono" /></label>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.mirror_key', 'مفتاحه') }}<template v-if="standby.has_key"> ({{ t('settings.saved', 'محفوظ') }})</template></span><input v-model="form.key" dir="ltr" autocomplete="off" :class="input" class="w-full font-mono" /></label>
                <div class="flex flex-wrap items-center gap-2">
                    <button :disabled="form.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white">{{ t('action.save', 'حفظ') }}</button>
                    <button v-if="standby.from" type="button" @click="pullNow" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" /> {{ t('exam.mirror_now', 'سحب الآن') }}</button>
                </div>
                <p v-if="standby.from" class="flex items-center gap-1 text-xs" :class="standby.last_error ? 'text-red-600' : 'text-emerald-700 dark:text-emerald-400'">
                    <ExclamationTriangleIcon v-if="standby.last_error" class="h-4 w-4" /><CheckIcon v-else class="h-4 w-4" />
                    {{ t('exam.mirror_last', 'آخر سحب ناجح: :at (:n صف)', { at: fmt(standby.last_ok), n: standby.rows ?? 0 }) }}<template v-if="standby.last_error"> — {{ standby.last_error }}</template></p>
            </form>
        </div>
    </AppLayout>
</template>
