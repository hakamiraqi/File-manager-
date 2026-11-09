<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ComputerDesktopIcon, TrashIcon, ArrowRightIcon, LockClosedIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ computers: Array, here: Object, rooms: Array });
const { t } = useTrans();
const origin = window.location.origin;

// This computer: its address is already known; give it a name.
const hereForm = useForm({ label: props.here.computer?.label ?? '', room: props.here.computer?.room ?? props.rooms[0] ?? '', ip: props.here.ip });
const saveHere = () => hereForm.post(route('exams.monitor.computers.store'), { preserveScroll: true });

const form = useForm({ label: '', room: '', ip: '' });
const add = () => form.post(route('exams.monitor.computers.store'), { preserveScroll: true, onSuccess: () => form.reset('label', 'ip') });
const bulkForm = useForm({ bulk: '', room: '' });
const addBulk = () => bulkForm.post(route('exams.monitor.computers.store'), { preserveScroll: true, onSuccess: () => bulkForm.reset('bulk') });

const editing = ref(null);
const editForm = useForm({ label: '', room: '', ip: '', seat_row: null, seat_col: null });
function edit(c) {
    editing.value = c.id;
    Object.assign(editForm, { label: c.label, room: c.room ?? '', ip: c.ip, seat_row: c.seat_row, seat_col: c.seat_col });
}
// The hall map: lay a room out in rows of N computers, by name.
const arrangeForm = useForm({ room: props.rooms[0] ?? '', columns: 6 });
const arrange = () => arrangeForm.post(route('exams.monitor.computers.arrange'), { preserveScroll: true });
const saveEdit = (c) => editForm.put(route('exams.monitor.computers.update', c.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
const remove = (c) => router.delete(route('exams.monitor.computers.destroy', c.id), { preserveScroll: true });
const input = 'rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700';
</script>

<template>
    <Head :title="t('exam.computers', 'حواسيب القاعات')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.monitor.index')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="flex items-center gap-2 text-lg font-semibold"><ComputerDesktopIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.computers', 'حواسيب القاعات') }}</h2>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <!-- This computer -->
            <form @submit.prevent="saveHere" class="flex flex-wrap items-end gap-3 rounded-xl border-2 border-[var(--accent-500)] bg-white p-4 dark:bg-gray-800">
                <div>
                    <p class="text-xs text-gray-500">{{ t('exam.this_computer', 'هذه الحاسبة') }}</p>
                    <p class="font-mono text-2xl font-bold" dir="ltr">{{ here.ip }}</p>
                    <p class="text-xs" :class="here.computer ? 'text-emerald-600' : 'text-amber-600'">{{ here.computer ? t('exam.registered_as', 'مسجّلة باسم') + ' ' + here.computer.label : t('exam.not_registered', 'غير مسجّلة') }}</p>
                </div>
                <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.pc_label', 'اسمها (مثل PC-07)') }}</span><input v-model="hereForm.label" :class="input" class="w-32" /></label>
                <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.room', 'القاعة') }}</span><input v-model="hereForm.room" list="rooms" :class="input" class="w-40" /></label>
                <button :disabled="hereForm.processing || !hereForm.label" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-40">{{ here.computer ? t('action.save', 'حفظ') : t('exam.register_pc', 'تسجيل هذه الحاسبة') }}</button>
                <p class="w-full text-xs text-gray-500">{{ t('exam.ip_hint', 'لمعرفة عنوان أي حاسبة دون تسجيل دخول: افتح منها العنوان /ip — مثل https://exams.lan/ip — فيظهر عنوانها واسمها بخط كبير. ثبّت عناوين الحواسيب في الراوتر (DHCP reservation) حتى لا تتغيّر.') }}</p>
                <datalist id="rooms"><option v-for="r in rooms" :key="r" :value="r" /></datalist>
            </form>

            <div class="grid gap-4 md:grid-cols-2">
                <form @submit.prevent="add" class="flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="w-full text-sm font-medium">{{ t('exam.add_pc', 'إضافة حاسبة') }}</p>
                    <input v-model="form.label" :placeholder="t('exam.pc_label', 'اسمها (مثل PC-07)')" :class="input" class="w-28" />
                    <input v-model="form.ip" placeholder="192.168.1.17" dir="ltr" :class="input" class="w-36 font-mono" />
                    <input v-model="form.room" list="rooms" :placeholder="t('exam.room', 'القاعة')" :class="input" class="w-32" />
                    <button :disabled="form.processing" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600">{{ t('exam.add', 'إضافة') }}</button>
                    <p v-if="form.errors.ip || form.errors.label" class="w-full text-xs text-red-600">{{ form.errors.ip || form.errors.label }}</p>
                </form>
                <form @submit.prevent="addBulk" class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-sm font-medium">{{ t('exam.add_pcs_bulk', 'عدة حواسيب: «الاسم العنوان» في كل سطر') }}</p>
                    <textarea v-model="bulkForm.bulk" rows="3" dir="ltr" placeholder="PC-01 192.168.1.11&#10;PC-02 192.168.1.12" :class="input" class="w-full font-mono" />
                    <div class="flex gap-2"><input v-model="bulkForm.room" list="rooms" :placeholder="t('exam.room', 'القاعة')" :class="input" class="flex-1" />
                        <button :disabled="bulkForm.processing || !bulkForm.bulk" class="rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:opacity-40 dark:border-gray-600">{{ t('exam.add', 'إضافة') }}</button></div>
                </form>
            </div>

            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <LockClosedIcon class="h-5 w-5 text-gray-500" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ t('exam.kiosk', 'وضع الكشك لحواسيب القاعة') }}</p>
                    <p class="text-xs text-gray-500">{{ t('exam.kiosk_hint', 'ملف لويندوز يفتح موقع الامتحانات بملء الشاشة في Edge أو Chrome بلا شريط عنوان ولا تبويبات. ضعه في مجلد بدء التشغيل (Win+R ثم shell:startup) فتفتح الحاسبة على الامتحان مباشرة. للخروج: Alt+F4.') }}</p>
                </div>
                <a :href="route('exams.monitor.computers.kiosk', { url: origin })" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600"><ArrowDownTrayIcon class="h-4 w-4" /> exam-kiosk.bat</a>
            </div>

            <form @submit.prevent="arrange" class="flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="w-full font-medium">{{ t('exam.arrange', 'ترتيب القاعة على الخريطة') }}<span class="block text-xs font-normal text-gray-500">{{ t('exam.arrange_hint', 'تُصفّ حواسيب القاعة بأسمائها صفوفاً من مقدمة القاعة؛ وتعدّل مقعد أي حاسبة بالضغط عليها في الجدول. نسخ الامتحان تتبادل حسب المقاعد المتجاورة.') }}</span></p>
                <label><span class="mb-1 block text-xs text-gray-500">{{ t('exam.room', 'القاعة') }}</span><input v-model="arrangeForm.room" list="rooms" :class="input" class="w-40" /></label>
                <label><span class="mb-1 block text-xs text-gray-500">{{ t('exam.per_row', 'حواسيب في كل صف') }}</span><input v-model.number="arrangeForm.columns" type="number" min="1" max="40" :class="input" class="w-24" /></label>
                <button :disabled="arrangeForm.processing" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600">{{ t('exam.arrange_btn', 'ترتيب') }}</button>
            </form>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50"><tr><th class="px-4 py-2 text-start">{{ t('exam.room', 'القاعة') }}</th><th class="px-2 py-2 text-start">{{ t('exam.pc_name', 'الحاسبة') }}</th><th class="px-2 py-2 text-start">IP</th><th class="px-2 py-2 text-start">{{ t('exam.seat', 'المقعد') }}</th><th class="px-4 py-2" /></tr></thead>
                    <tbody>
                        <tr v-for="c in computers" :key="c.id" class="border-t border-gray-100 dark:border-gray-700" :class="c.ip === here.ip ? 'bg-[var(--accent-50)] dark:bg-gray-700' : ''">
                            <template v-if="editing === c.id">
                                <td class="px-4 py-1.5"><input v-model="editForm.room" list="rooms" :class="input" class="w-32" /></td>
                                <td class="px-2 py-1.5"><input v-model="editForm.label" :class="input" class="w-28" /></td>
                                <td class="px-2 py-1.5"><input v-model="editForm.ip" dir="ltr" :class="input" class="w-36 font-mono" /><span v-if="editForm.errors.ip" class="block text-xs text-red-600">{{ editForm.errors.ip }}</span></td>
                                <td class="px-2 py-1.5 whitespace-nowrap"><input v-model.number="editForm.seat_row" type="number" min="1" max="60" :placeholder="t('exam.seat_row', 'صف')" :class="input" class="w-16" />
                                    <input v-model.number="editForm.seat_col" type="number" min="1" max="60" :placeholder="t('exam.seat_col', 'مقعد')" :class="input" class="ms-1 w-16" /></td>
                                <td class="px-4 py-1.5 text-end"><button @click="saveEdit(c)" class="rounded-lg bg-[var(--accent-600)] px-3 py-1 text-xs text-white">{{ t('action.save', 'حفظ') }}</button></td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-2 text-gray-500">{{ c.room || '—' }}</td>
                                <td class="cursor-pointer px-2 py-2 font-medium" @click="edit(c)">{{ c.label }}</td>
                                <td class="cursor-pointer px-2 py-2 font-mono" dir="ltr" @click="edit(c)">{{ c.ip }}</td>
                                <td class="cursor-pointer px-2 py-2 text-xs text-gray-500" @click="edit(c)">{{ c.seat_row && c.seat_col ? t('exam.seat_at', 'صف :r، مقعد :c', { r: c.seat_row, c: c.seat_col }) : '—' }}</td>
                                <td class="px-4 py-2 text-end"><button @click="remove(c)" class="rounded p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button></td>
                            </template>
                        </tr>
                        <tr v-if="!computers.length"><td colspan="5" class="p-6 text-center text-gray-500">{{ t('exam.no_pcs', 'لا حواسيب مسجّلة بعد.') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
