<script setup>
// «إرسال إلى نظام الطلبة»: this exam's marks, scaled to a component of an SIS course section.
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import { AcademicCapIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ examId: Number, maxScore: Number, sis: Object });
const open = ref(false);
const loading = ref(false);
const error = ref('');
const offerings = ref([]);
const form = useForm({ offering_id: null, component: '', absent: false });

const show = async () => {
    open.value = true; loading.value = true; error.value = '';
    try {
        offerings.value = (await window.axios.get(route('exams.manage.sis.offerings'))).data.data;
        const fromGroup = offerings.value.find((o) => props.sis.group_offerings.includes(o.id));
        form.offering_id = fromGroup?.id ?? null;
        pickComponent();
    } catch (e) { error.value = e.response?.data?.message ?? t('exam.sis_unreachable', 'تعذّر الوصول إلى نظام الطلبة.'); }
    finally { loading.value = false; }
};
const offering = computed(() => offerings.value.find((o) => o.id === form.offering_id));
const components = computed(() => (offering.value?.components ?? []).filter((c) => c.accepts_marks));
const component = computed(() => components.value.find((c) => c.code === form.component));
const pickComponent = () => { form.component = components.value.find((c) => /final/.test(c.code))?.code ?? components.value[0]?.code ?? ''; };
const send = () => form.post(route('exams.manage.sis.marks', props.examId), { preserveScroll: true, onSuccess: () => (open.value = false) });
const refresh = () => router.post(route('exams.manage.sis.marks.status', props.examId), {}, { preserveScroll: true });
const STATUS = { pending: ['بانتظار التدريسي', 'bg-amber-50 text-amber-800'], applied: ['أُدخلت في السجل', 'bg-emerald-50 text-emerald-700'], rejected: ['رفضها التدريسي', 'bg-red-50 text-red-700'], superseded: ['استُبدلت', 'bg-gray-100 text-gray-600'] };
</script>

<template>
    <button v-if="sis?.connected && sis.marks" type="button" @click="show" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><AcademicCapIcon class="h-4 w-4" /> {{ t('exam.sis_send', 'إرسال إلى نظام الطلبة') }}</button>
    <Modal :show="open" max-width="lg" @close="open = false">
        <form class="space-y-4 p-5 text-sm" @submit.prevent="send">
            <h3 class="text-lg font-semibold">{{ t('exam.sis_send', 'إرسال إلى نظام الطلبة') }}</h3>
            <p class="text-xs text-gray-500">{{ t('exam.sis_send_hint', 'تُرسل الدرجة النهائية لكل طالب له رقم جامعي، محوّلة إلى درجة المكوّن. تظهر للتدريسي في صفحة المادة ولا تدخل السجل إلا بعد اعتماده. الإرسال من جديد يستبدل ما لم يُعتمد بعد.') }}</p>
            <p v-if="loading" class="py-4 text-center text-gray-500">{{ t('exam.loading', 'جارٍ التحميل…') }}</p>
            <p v-else-if="error" class="rounded-lg bg-red-50 p-3 text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ error }}</p>
            <template v-else>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.sis_offering', 'الشعبة') }}</span>
                    <select v-model="form.offering_id" @change="pickComponent" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" required>
                        <option :value="null" disabled>—</option>
                        <option v-for="o in offerings" :key="o.id" :value="o.id">{{ o.course.code }} — {{ o.course.name_ar }} / {{ o.section }}{{ o.shift === 'evening' ? ' (مسائي)' : '' }}</option>
                    </select></label>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.sis_component', 'المكوّن') }}</span>
                    <select v-model="form.component" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" :disabled="!components.length" required>
                        <option v-for="c in components" :key="c.code" :value="c.code">{{ c.name_ar }} ({{ t('exam.sis_out_of', 'من :n', { n: c.max_mark }) }})</option>
                    </select>
                    <span v-if="offering && !components.length" class="mt-1 block text-xs text-amber-700">{{ t('exam.sis_no_components', 'لا يستقبل أي مكوّن درجات الآن في هذه الشعبة (النتائج مرفوعة أو مقفلة).') }}</span></label>
                <p v-if="component" class="rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-700/50">{{ t('exam.sis_scale', 'الدرجة من :a تُحوّل إلى درجة من :b. مثال: :x من :a ← :y.', { a: maxScore, b: component.max_mark, x: Math.round(maxScore * 0.75 * 100) / 100, y: Math.round(component.max_mark * 0.75 * 100) / 100 }) }}</p>
                <label class="flex items-center gap-2"><input v-model="form.absent" type="checkbox" class="rounded border-gray-300" /> {{ t('exam.sis_absent', 'من لم يؤدِّ الامتحان يُرسل «غائب»') }}</label>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-1.5 dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="form.processing || !component" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white disabled:opacity-40">{{ t('exam.sis_send_now', 'إرسال') }}</button>
                </div>
            </template>
            <div v-if="sis.pushes.length" class="border-t border-gray-100 pt-3 dark:border-gray-700">
                <div class="mb-1 flex items-center justify-between"><p class="text-xs font-semibold text-gray-500">{{ t('exam.sis_sent_before', 'أُرسل من قبل') }}</p>
                    <button type="button" @click="refresh" class="flex items-center gap-1 text-xs text-[var(--accent-700)]"><ArrowPathIcon class="h-3.5 w-3.5" /> {{ t('exam.sis_refresh_status', 'تحديث الحالة') }}</button></div>
                <ul class="space-y-1 text-xs">
                    <li v-for="p in sis.pushes" :key="p.id" class="flex items-center gap-2"><span class="min-w-0 flex-1 truncate">{{ p.offering }} · {{ p.component }} · {{ p.sent }}</span>
                        <span class="rounded-full px-2 py-0.5" :class="STATUS[p.status]?.[1]">{{ t('exam.sis_status_' + p.status, STATUS[p.status]?.[0] ?? p.status) }}</span></li>
                </ul>
            </div>
        </form>
    </Modal>
</template>
