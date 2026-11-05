<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import BookCover from '@/Components/Library/BookCover.vue';
import { useTrans } from '@/Composables/useTrans';
import { useLibrary } from '@/Composables/useLibrary';
import { DocumentTextIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const { statusLabel, statusClass } = useLibrary();
const props = defineProps({ book: Object, sectionPath: String, copies: Array, digitalFile: Object, myHold: Object, queueLength: Number });
const errors = computed(() => usePage().props.errors);
const placeHold = () => router.post(route('library.holds.store', props.book.id), {}, { preserveScroll: true });
const cancelHold = () => router.delete(route('library.holds.destroy', props.myHold.id), { preserveScroll: true });
const canManage = computed(() => usePage().props.permissions?.manage_library);

const details = computed(() => [
    [t('library.field.authors', 'المؤلف'), props.book.authors],
    [t('library.field.publisher', 'الناشر'), props.book.publisher],
    [t('library.field.year', 'السنة'), props.book.year],
    [t('library.field.edition', 'الطبعة'), props.book.edition],
    [t('library.field.pages', 'عدد الصفحات'), props.book.pages],
    [t('library.field.language', 'اللغة'), props.book.language],
    ['ISBN', props.book.isbn],
    [t('library.field.section', 'القسم'), props.sectionPath],
].filter(([, v]) => v));
</script>

<template>
    <Head :title="book.title" />

    <AppLayout>
        <template #header>
            <h2 class="truncate text-lg font-semibold text-gray-800 dark:text-gray-100" dir="auto">{{ book.title }}</h2>
        </template>

        <div class="space-y-5 p-4 sm:p-6">
            <LibraryTabs />

            <div class="flex flex-col gap-6 rounded-xl border border-gray-200 bg-white p-5 sm:flex-row dark:border-gray-700 dark:bg-gray-800">
                <BookCover :book="book" class="mx-auto w-40 shrink-0 sm:mx-0 sm:w-48" />
                <div class="min-w-0 flex-1 space-y-3">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100" dir="auto">{{ book.title }}</h1>
                        <p v-if="book.subtitle" class="text-gray-500" dir="auto">{{ book.subtitle }}</p>
                    </div>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                        <div v-for="[label, value] in details" :key="label" class="flex gap-2">
                            <dt class="shrink-0 text-gray-500">{{ label }}:</dt>
                            <dd class="min-w-0 text-gray-800 dark:text-gray-200" dir="auto">{{ value }}</dd>
                        </div>
                    </dl>
                    <p v-if="book.reference_only" class="inline-block rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                        {{ t('library.reference_only', 'للقراءة داخل المكتبة') }}
                    </p>
                    <p v-if="book.description" class="whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-300" dir="auto">{{ book.description }}</p>
                    <p v-if="errors.code" class="rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ errors.code }}</p>
                    <div v-if="!book.reference_only && copies.length" class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-900/40">
                        <template v-if="myHold">
                            <p v-if="myHold.status === 'ready'" class="font-medium text-emerald-700">{{ t('library.hold_ready', 'كتابك المحجوز جاهز للاستلام حتى :date', { date: myHold.expires_at?.slice(0, 16).replace('T', ' ') }) }}</p>
                            <p v-else>{{ t('library.hold_waiting', 'أنت في قائمة الانتظار لهذا الكتاب.') }}</p>
                            <button @click="cancelHold" class="mt-2 rounded-md border border-gray-300 px-3 py-1 text-sm dark:border-gray-600">{{ t('library.cancel_hold', 'إلغاء الحجز') }}</button>
                        </template>
                        <template v-else>
                            <p class="text-gray-600 dark:text-gray-300">
                                {{ copies.some((c) => c.status === 'available') ? t('library.hold_now', 'متوفر الآن: احجزه ليُحفظ لك في المكتبة.') : t('library.hold_queue', 'كل النسخ مُعارة: احجزه لتُبلَّغ عند عودة نسخة.') }}
                                <template v-if="queueLength"> ({{ t('library.queue_n', 'بالانتظار :n', { n: queueLength }) }})</template>
                            </p>
                            <button @click="placeHold" class="mt-2 rounded-md bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white">{{ t('library.place_hold', 'احجز الكتاب') }}</button>
                        </template>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <a v-if="digitalFile" :href="route('files.preview', digitalFile.id)" target="_blank" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                            <DocumentTextIcon class="h-5 w-5" /> {{ t('library.digital_copy', 'النسخة الرقمية') }}
                        </a>
                        <Link v-if="canManage" :href="route('library.manage.books', { q: book.isbn || book.title })" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                            <PencilSquareIcon class="h-5 w-5" /> {{ t('library.edit_book', 'تعديل الكتاب') }}
                        </Link>
                    </div>
                </div>
            </div>

            <section class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <h3 class="border-b border-gray-100 px-4 py-3 font-semibold dark:border-gray-700">{{ t('library.copies', 'النسخ') }} ({{ copies.length }})</h3>
                <div v-if="!copies.length" class="p-4 text-sm text-gray-400">{{ t('library.no_copies', 'لا توجد نسخ') }}</div>
                <div v-for="c in copies" :key="c.id" class="flex items-center gap-3 border-b border-gray-50 px-4 py-2.5 text-sm last:border-0 dark:border-gray-700">
                    <span class="font-mono" dir="ltr">{{ c.barcode }}</span>
                    <span v-if="c.shelf" class="text-gray-500">{{ t('library.shelf', 'الرف') }}: {{ c.shelf }}</span>
                    <span class="ms-auto rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(c.status)">{{ statusLabel(c.status) }}</span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
