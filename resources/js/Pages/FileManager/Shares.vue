<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FileIcon from '@/Components/FileIcon.vue';
import { useTrans } from '@/Composables/useTrans';
import { FolderIcon, ClipboardIcon, CheckIcon, TrashIcon, LockClosedIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ shares: Array });
const copiedId = ref(null);

function shareUrl(token) {
    return route('share.public.show', token);
}

function copy(share) {
    navigator.clipboard.writeText(shareUrl(share.token));
    copiedId.value = share.id;
    setTimeout(() => (copiedId.value = null), 2000);
}

function revoke(share) {
    if (!confirm(t('share.confirm_revoke', 'سيتم إلغاء رابط المشاركة هذا نهائياً. متابعة؟'))) return;
    router.delete(route('shares.destroy', share.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('nav.shares', 'الروابط المشتركة')" />

    <AppLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('nav.shares', 'الروابط المشتركة') }}</h2>
        </template>

        <div class="p-6">
            <div v-if="!shares.length" class="py-24 text-center text-gray-400">
                {{ t('empty.no_shares', 'لم تقم بإنشاء أي روابط مشاركة بعد.') }}
            </div>

            <ul v-else class="divide-y divide-gray-200 rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                <li v-for="share in shares" :key="share.id" class="flex flex-wrap items-center gap-3 p-4">
                    <FolderIcon v-if="share.shareable_type === 'folder'" class="h-6 w-6 shrink-0 text-amber-400" />
                    <FileIcon v-else :extension="share.shareable?.extension" class="h-6 w-6 shrink-0" />

                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-gray-700 dark:text-gray-200">{{ share.shareable?.name ?? t('share.deleted_item', 'عنصر محذوف') }}</p>
                        <p class="flex items-center gap-2 text-xs text-gray-400">
                            <LockClosedIcon v-if="share.password" class="h-3 w-3" />
                            <span v-if="share.expires_at">{{ t('share.expires_at', 'ينتهي: :date', { date: new Date(share.expires_at).toLocaleString('ar') }) }}</span>
                            <span v-else>{{ t('share.no_expiry', 'بدون تاريخ انتهاء') }}</span>
                            · {{ t('share.downloads', 'تنزيلات: :count', { count: share.download_count }) }}<span v-if="share.max_downloads">/{{ share.max_downloads }}</span>
                        </p>
                    </div>

                    <button @click="copy(share)" class="rounded-md p-2 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('share.copy_link', 'نسخ الرابط')">
                        <CheckIcon v-if="copiedId === share.id" class="h-5 w-5 text-emerald-600" />
                        <ClipboardIcon v-else class="h-5 w-5" />
                    </button>
                    <button @click="revoke(share)" class="rounded-md p-2 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('share.revoke', 'إلغاء المشاركة')">
                        <TrashIcon class="h-5 w-5 text-red-500" />
                    </button>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
