<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { EyeIcon, SignalIcon, DevicePhoneMobileIcon } from '@heroicons/vue/24/outline';
import ServerHealth from '@/Components/Exams/ServerHealth.vue';

defineProps({ exams: Array });
const { t } = useTrans();
// Install as an app on the invigilator's phone (where the browser offers it).
const installable = ref(!!window.__installPrompt);
const onInstallable = () => (installable.value = true);
onMounted(() => window.addEventListener('app-installable', onInstallable));
onBeforeUnmount(() => window.removeEventListener('app-installable', onInstallable));
async function install() {
    const p = window.__installPrompt;
    if (!p) return;
    p.prompt();
    await p.userChoice;
    window.__installPrompt = null;
    installable.value = false;
}
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'short', timeStyle: 'short' }) : '—');
</script>

<template>
    <Head :title="t('exam.monitor', 'المراقبة')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-lg font-semibold"><EyeIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.monitor', 'المراقبة') }}</h2>
                <Link v-if="$page.props.auth?.user?.role === 'admin'" :href="route('exams.monitor.central')" class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.central', 'السيرفر المركزي والقاعات') }}</Link>
                <Link v-if="$page.props.auth?.user?.role === 'admin'" :href="route('exams.monitor.mirror')" class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.mirror', 'السيرفر الاحتياطي') }}</Link>
                <Link :href="route('exams.monitor.osce')" class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.osce_assess', 'التقييم في المحطات') }}</Link>
                <Link :href="route('exams.monitor.computers')" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.computers', 'حواسيب القاعات') }}</Link>
            </div>
        </template>
        <div class="space-y-2 p-4 sm:p-6">
            <div v-if="installable" class="flex flex-wrap items-center gap-3 rounded-xl border border-[var(--accent-500)] bg-white p-3 text-sm dark:bg-gray-800">
                <DevicePhoneMobileIcon class="h-6 w-6 text-[var(--accent-600)]" />
                <span class="min-w-0 flex-1">{{ t('exam.install_hint', 'ثبّت المراقبة تطبيقاً على هاتفك: تفتح بلمسة، وتصلك تنبيهات طلبات المساعدة والانقطاع.') }}</span>
                <button type="button" @click="install" class="rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-white">{{ t('exam.install', 'تثبيت التطبيق') }}</button>
            </div>
            <ServerHealth />
            <p v-if="!exams.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.monitor_empty', 'لا امتحانات جارية أو قريبة الآن.') }}</p>
            <Link v-for="e in exams" :key="e.id" :href="route('exams.monitor.show', e.id)"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700">
                <div class="min-w-0 flex-1">
                    <p class="font-medium" dir="auto">{{ e.title }}</p>
                    <p class="text-xs text-gray-500">{{ [e.subject, e.groups.join('، ')].filter(Boolean).join(' · ') || t('exam.given_to_all_short', 'كل الطلاب') }} · {{ fmt(e.opens_at) }} → {{ fmt(e.closes_at) }}</p>
                </div>
                <span v-if="e.running" class="flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                    <SignalIcon class="h-3.5 w-3.5" /> {{ e.running }} {{ t('exam.running', 'يؤدّون الآن') }}
                </span>
            </Link>
        </div>
    </AppLayout>
</template>
