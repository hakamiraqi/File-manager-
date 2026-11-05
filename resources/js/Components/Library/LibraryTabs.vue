<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const page = usePage();
const canManage = computed(() => page.props.permissions?.manage_library);

// Each later library step adds its tab here.
const tabs = computed(() => [
    { label: t('library.tab.catalog', 'الفهرس'), href: route('library.index'), active: route().current('library.index') || route().current('library.books.show'), show: true },
    { label: t('library.tab.my_loans', 'استعاراتي'), name: 'library.my-loans', active: route().current('library.my-loans'), show: true },
    { label: t('library.tab.counter', 'الإعارة والاسترجاع'), name: 'library.manage.counter', active: route().current('library.manage.counter'), show: canManage.value },
    { label: t('library.tab.loans', 'الاستعارات'), name: 'library.manage.loans', active: route().current('library.manage.loans'), show: canManage.value },
    { label: t('library.tab.members', 'القرّاء'), name: 'library.manage.members', active: route().current('library.manage.members'), show: canManage.value },
    { label: t('library.tab.books', 'إدارة الكتب'), name: 'library.manage.books', active: route().current('library.manage.books'), show: canManage.value },
    { label: t('library.tab.sections', 'الأقسام'), name: 'library.manage.sections', active: route().current('library.manage.sections'), show: canManage.value },
    { label: t('library.tab.settings', 'قواعد الإعارة'), name: 'library.manage.settings', active: route().current('library.manage.settings'), show: canManage.value },
].filter((tab) => tab.show).map((tab) => ({ ...tab, href: tab.href ?? route(tab.name) })));
</script>

<template>
    <nav class="-mx-1 flex gap-1 overflow-x-auto whitespace-nowrap">
        <Link
            v-for="tab in tabs"
            :key="tab.href"
            :href="tab.href"
            class="rounded-lg px-3 py-1.5 text-sm font-medium"
            :class="tab.active ? 'bg-[var(--accent-600)] text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
