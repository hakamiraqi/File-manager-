<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

// One card per student: where to go, the e-mail and a new password. Shown once; print it now.
defineProps({ group: String, cards: Array, address: String, site: String });
const { t } = useTrans();
const printPage = () => window.print();
</script>

<template>
    <Head :title="t('exam.login_cards', 'بطاقات دخول') + ' — ' + group" />
    <div class="min-h-screen bg-gray-100 print:bg-white">
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-white px-4 py-2 text-sm print:hidden">
            <Link :href="route('exams.manage.groups')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
            <span class="flex-1 font-medium">{{ t('exam.login_cards', 'بطاقات دخول') }} — {{ group }} ({{ cards.length }})</span>
            <span class="text-xs text-amber-700">{{ t('exam.cards_once', 'لن تظهر كلمات المرور مرة أخرى: اطبعها الآن.') }}</span>
            <button @click="printPage" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-white"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print', 'طباعة') }}</button>
        </div>
        <div class="mx-auto my-6 grid max-w-[210mm] grid-cols-2 gap-[4mm] bg-white p-[8mm] print:m-0 print:max-w-none print:p-0">
            <div v-for="c in cards" :key="c.email || c.student_number" class="break-inside-avoid rounded border border-dashed border-gray-500 p-3 text-[10pt] text-black">
                <p class="text-xs text-gray-600">{{ site }} · {{ group }}</p>
                <p class="mt-1 text-base font-bold">{{ c.name }}</p>
                <p class="mt-2 text-xs">{{ t('exam.card_address', 'العنوان') }}: <b dir="ltr">{{ address }}</b></p>
                <p v-if="c.student_number" class="text-xs">{{ t('exam.card_number', 'الرقم الجامعي') }}: <b dir="ltr" class="font-mono">{{ c.student_number }}</b></p>
                <p v-if="c.email" class="text-xs">{{ t('exam.card_email', 'البريد') }}: <b dir="ltr">{{ c.email }}</b></p>
                <p class="text-xs">{{ t('exam.card_password', 'كلمة المرور') }}: <b dir="ltr" class="font-mono text-sm tracking-wider">{{ c.password }}</b></p>
            </div>
        </div>
    </div>
</template>
