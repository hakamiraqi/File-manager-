<script setup>
// «من نظام الطلبة»: take one SIS course section with its students as a group.
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import { AcademicCapIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const open = ref(false);
const loading = ref(false);
const error = ref('');
const rows = ref([]);
const q = ref('');
const busy = ref(null);

const show = async () => {
    open.value = true; loading.value = true; error.value = '';
    try { rows.value = (await window.axios.get(route('exams.manage.sis.offerings'))).data.data; }
    catch (e) { error.value = e.response?.data?.message ?? t('exam.sis_unreachable', 'تعذّر الوصول إلى نظام الطلبة.'); }
    finally { loading.value = false; }
};
const shown = computed(() => {
    const s = q.value.trim().toLowerCase();
    return s ? rows.value.filter((o) => `${o.course.code} ${o.course.name_ar} ${o.department} ${o.teacher ?? ''}`.toLowerCase().includes(s)) : rows.value;
});
const take = (o) => { busy.value = o.id; router.post(route('exams.manage.groups.from-sis'), { offering_id: o.id }, { onFinish: () => { busy.value = null; open.value = false; } }); };
</script>

<template>
    <button type="button" @click="show" class="flex items-center gap-1 rounded-xl border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700"><AcademicCapIcon class="h-4 w-4" /> {{ t('exam.sis_from', 'من نظام الطلبة') }}</button>
    <Modal :show="open" max-width="2xl" @close="open = false">
        <div class="p-5">
            <h3 class="text-lg font-semibold">{{ t('exam.sis_from_title', 'شعبة من نظام الطلبة') }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ t('exam.sis_from_hint', 'تصبح الشعبة مجموعة بطلبتها المشمولين بالدور الحالي (الرقم الجامعي والاسم). الطالب الجديد يُنشأ له حساب، فاطبع له بطاقة دخول. أخذها مرة ثانية يحدّث الطلبة.') }}</p>
            <div class="relative mt-3"><MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-2.5 h-4 w-4 text-gray-400" /><input v-model="q" class="w-full rounded-lg border-gray-300 ps-9 text-sm dark:border-gray-600 dark:bg-gray-700" :placeholder="t('exam.search', 'بحث')" /></div>
            <p v-if="loading" class="py-8 text-center text-sm text-gray-500">{{ t('exam.loading', 'جارٍ التحميل…') }}</p>
            <p v-else-if="error" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ error }}</p>
            <ul v-else class="mt-3 max-h-[55vh] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                <li v-for="o in shown" :key="o.id" class="flex items-center gap-3 py-2 text-sm">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium"><span class="font-mono text-xs text-gray-500" dir="ltr">{{ o.course.code }}</span> {{ o.course.name_ar }} <span class="text-gray-500">/ {{ o.section }}<template v-if="o.shift === 'evening'"> · {{ t('exam.evening', 'مسائي') }}</template></span></p>
                        <p class="text-xs text-gray-500">{{ o.department }} · {{ t('exam.stage_n', 'المرحلة :n', { n: o.stage ?? '—' }) }} · {{ o.teacher ?? '—' }} · {{ t('exam.sis_students_n', ':n طالب', { n: o.students }) }}<template v-if="o.current_round > 1"> · {{ t('exam.sis_round', 'الدور :n', { n: o.current_round }) }}</template></p>
                    </div>
                    <button type="button" :disabled="busy !== null" @click="take(o)" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium disabled:opacity-40" :class="o.group_id ? 'border border-gray-300 dark:border-gray-600' : 'bg-[var(--accent-600)] text-white'">
                        {{ busy === o.id ? '…' : o.group_id ? t('exam.sis_update', 'تحديث الطلبة') : t('exam.sis_take', 'أخذ كمجموعة') }}</button>
                </li>
                <li v-if="!shown.length" class="py-6 text-center text-sm text-gray-500">{{ t('exam.sis_none', 'لا توجد شعب.') }}</li>
            </ul>
        </div>
    </Modal>
</template>
