<script setup>
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { UsersIcon, AcademicCapIcon, ClipboardDocumentListIcon, CheckCircleIcon, PencilSquareIcon, PlayIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ stats: Object, recentActivity: Array, topExams: Array });

const cards = [
    { label: () => t('admin.dashboard.users', 'المستخدمون'), value: (s) => s.users, icon: UsersIcon },
    { label: () => t('admin.dashboard.examiners', 'الأساتذة'), value: (s) => s.examiners, icon: AcademicCapIcon },
    { label: () => t('admin.dashboard.exams', 'الامتحانات (المنشورة)'), value: (s) => `${s.exams} (${s.published})`, icon: ClipboardDocumentListIcon },
    { label: () => t('admin.dashboard.attempts', 'محاولات مُسلّمة'), value: (s) => s.attempts, icon: CheckCircleIcon },
    { label: () => t('admin.dashboard.running', 'محاولات جارية الآن'), value: (s) => s.running, icon: PlayIcon },
    { label: () => t('admin.dashboard.pending', 'بانتظار التصحيح'), value: (s) => s.pending, icon: PencilSquareIcon },
];

const rtf = new Intl.RelativeTimeFormat('ar', { numeric: 'auto' });
function ago(iso) {
    const s = (new Date(iso) - Date.now()) / 1000;
    const steps = [[60, 'second'], [3600, 'minute'], [86400, 'hour'], [604800, 'day'], [Infinity, 'week']];
    let prev = 1;
    for (const [limit, unit] of steps) {
        if (Math.abs(s) < limit) return rtf.format(Math.round(s / prev), unit);
        prev = limit;
    }
    return '';
}
</script>

<template>
    <Head :title="t('nav.admin', 'لوحة الإدارة')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.nav.dashboard', 'نظرة عامة') }}</h2>
        </template>

        <div class="space-y-6 p-6">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                <div v-for="card in cards" :key="card.label()" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <component :is="card.icon" class="h-6 w-6 text-[var(--accent-600)]" />
                    <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ card.value(stats) }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ card.label() }}</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.dashboard.top_exams', 'الامتحانات الأكثر أداءً') }}</h3>
                    <p v-if="!topExams.length" class="text-sm text-gray-400">{{ t('admin.dashboard.no_exams', 'لا توجد امتحانات بعد.') }}</p>
                    <ul class="space-y-2">
                        <li v-for="e in topExams" :key="e.id" class="flex items-center justify-between text-sm">
                            <Link :href="route('exams.manage.results', e.id)" class="truncate text-gray-700 hover:text-[var(--accent-700)] dark:text-gray-300" dir="auto">{{ e.title }}</Link>
                            <span class="text-gray-400">{{ e.attempts }}</span>
                        </li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.dashboard.recent_activity', 'أحدث النشاطات') }}</h3>
                        <a :href="route('admin.activity.index')" class="text-sm text-[var(--accent-600)] hover:underline">{{ t('admin.dashboard.all_activity', 'السجل الكامل') }}</a>
                    </div>
                    <ul class="space-y-3 text-sm">
                        <li v-for="log in recentActivity" :key="log.id" class="flex items-start gap-3">
                            <UserAvatar :user="log.user ?? { name: '؟' }" size="h-8 w-8 text-xs" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-gray-800 dark:text-gray-100"><b>{{ log.user?.name ?? t('admin.dashboard.system', 'النظام') }}</b> · {{ log.label }}</p>
                                <p v-if="log.description" class="truncate text-xs text-gray-500 dark:text-gray-400" dir="auto">{{ log.description }}</p>
                            </div>
                            <span class="shrink-0 text-xs text-gray-400">{{ ago(log.created_at) }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
