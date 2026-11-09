<script setup>
// «الصفوف والمجموعات» (redesigned 2026-11-09, by request): pick the students already in the
// system from a list (search, select, select all), each student in one group only — a student
// added to a group is no longer offered for the others (unless moved on purpose). The members
// can be searched, selected and taken out together. Easy on a phone.
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    PlusIcon, TrashIcon, PencilSquareIcon, UserGroupIcon, ArrowUpTrayIcon, MagnifyingGlassIcon, UserPlusIcon, XMarkIcon,
    ChartBarIcon, IdentificationIcon, EllipsisVerticalIcon, CheckIcon, ArrowsRightLeftIcon, ArrowPathIcon, UsersIcon, AcademicCapIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({ groups: Array, current: Number, members: Array, canImport: Boolean, unassigned: { type: Number, default: 0 } });
const { t } = useTrans();
const group = computed(() => props.groups.find((g) => g.id === props.current) ?? null);
const initial = (name) => (name || '?').trim().charAt(0);
const hue = (id) => `hsl(${(id * 47) % 360} 70% 45%)`;

// ---- New / edit group ----
const editing = ref(null);
const groupForm = useForm({ name: '', stage: '', department: '' });
function openGroup(g = null) {
    menu.value = false;
    editing.value = g ?? {};
    groupForm.name = g?.name ?? '';
    groupForm.stage = g?.stage ?? '';
    groupForm.department = g?.department ?? '';
    groupForm.clearErrors();
}
function saveGroup() {
    const done = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? groupForm.put(route('exams.manage.groups.update', editing.value.id), done) : groupForm.post(route('exams.manage.groups.store'), done);
}
const toDelete = ref(null);
const removeGroup = () => router.delete(route('exams.manage.groups.destroy', toDelete.value.id), { onFinish: () => (toDelete.value = null) });
const menu = ref(false);

// ---- Groups list: search, by stage ----
const groupSearch = ref('');
const shownGroups = computed(() => {
    const q = groupSearch.value.trim().toLowerCase();
    return props.groups.filter((g) => !q || [g.name, g.stage, g.department].filter(Boolean).join(' ').toLowerCase().includes(q));
});
const totalMembers = computed(() => props.groups.reduce((n, g) => n + g.members, 0));

// ---- Members: search, select, take out ----
const memberSearch = ref('');
const shownMembers = computed(() => {
    const q = memberSearch.value.trim().toLowerCase();
    return props.members.filter((m) => !q || [m.name, m.email, m.number].filter(Boolean).join(' ').toLowerCase().includes(q));
});
const picked = ref(new Set());
watch(() => props.current, () => { picked.value = new Set(); memberSearch.value = ''; });
const allPicked = computed(() => shownMembers.value.length > 0 && shownMembers.value.every((m) => picked.value.has(m.id)));
function togglePick(id) { const s = new Set(picked.value); s.has(id) ? s.delete(id) : s.add(id); picked.value = s; }
function toggleAll() { picked.value = allPicked.value ? new Set() : new Set(shownMembers.value.map((m) => m.id)); }
const removing = ref(null); // the members about to be taken out
function removePicked() {
    router.post(route('exams.manage.groups.members.remove', props.current), { ids: removing.value.map((m) => m.id) }, {
        preserveScroll: true, onSuccess: () => { picked.value = new Set(); removing.value = null; },
    });
}
const cardsOpen = ref(false);
const printCards = () => { cardsOpen.value = false; router.post(route('exams.manage.groups.cards', props.current)); };

// ---- Add students: from the system, by e-mail / number, or new accounts from Excel ----
const adding = ref(false);
const addTab = ref('system');
const q = ref('');
const others = ref(false); // students in other groups, to move them here
const found = ref([]);
const total = ref(0);
const loading = ref(false);
const chosen = ref(new Map()); // id → student
let searchTimer = null;
let seq = 0;
async function load(more = false) {
    const mine = ++seq;
    loading.value = true;
    try {
        const r = (await window.axios.get(route('exams.manage.groups.candidates', props.current), { params: { q: q.value, others: others.value ? 1 : 0, offset: more ? found.value.length : 0 } })).data;
        if (mine !== seq) return;
        found.value = more ? [...found.value, ...r.students] : r.students;
        total.value = r.total;
    } finally {
        if (mine === seq) loading.value = false;
    }
}
watch([q, others], () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => load(), 250); });
function openAdd() {
    adding.value = true;
    addTab.value = 'system';
    q.value = '';
    others.value = false;
    chosen.value = new Map();
    found.value = [];
    load();
    nextTick(() => document.getElementById('pick-search')?.focus());
}
function toggleChosen(s) { const m = new Map(chosen.value); m.has(s.id) ? m.delete(s.id) : m.set(s.id, s); chosen.value = m; }
const allFoundChosen = computed(() => found.value.length > 0 && found.value.every((s) => chosen.value.has(s.id)));
function chooseAllFound() {
    const m = new Map(chosen.value);
    if (allFoundChosen.value) found.value.forEach((s) => m.delete(s.id)); else found.value.forEach((s) => m.set(s.id, s));
    chosen.value = m;
}
const moving = computed(() => [...chosen.value.values()].filter((s) => s.group).length);
const addForm = useForm({ ids: [], move: false });
function addChosen() {
    addForm.ids = [...chosen.value.keys()];
    addForm.move = moving.value > 0;
    addForm.post(route('exams.manage.groups.members.store', props.current), { preserveScroll: true, onSuccess: () => (adding.value = false) });
}
const memberForm = useForm({ emails: '', move: false });
const addByText = () => memberForm.post(route('exams.manage.groups.members.store', props.current), { preserveScroll: true, onSuccess: () => { memberForm.reset(); adding.value = false; } });

// New accounts from a sheet, straight into this group; the file of passwords comes back.
const importFile = ref(null);
const importError = ref('');
const importing = ref(false);
async function importStudents() {
    if (!importFile.value) return;
    importing.value = true;
    importError.value = '';
    const data = new FormData();
    data.append('file', importFile.value);
    data.append('group_id', props.current);
    try {
        const res = await window.axios.post(route('exams.manage.students.import'), data, { responseType: 'blob' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(res.data);
        a.download = 'students-passwords.csv';
        a.click();
        adding.value = false;
        router.reload({ preserveScroll: true });
    } catch (e) {
        const text = e.response?.data instanceof Blob ? await e.response.data.text() : '';
        try { importError.value = JSON.parse(text).errors?.file?.[0] ?? JSON.parse(text).message; } catch { importError.value = t('exam.upload_failed', 'تعذّر رفع الملف.'); }
    } finally {
        importing.value = false;
    }
}
const input = 'w-full rounded-xl border-gray-300 text-sm focus:border-[var(--accent-500)] focus:ring-[var(--accent-500)] dark:border-gray-600 dark:bg-gray-700';
</script>

<template>
    <Head :title="t('exam.groups', 'الصفوف والمجموعات')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-lg font-semibold"><UserGroupIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.groups', 'الصفوف والمجموعات') }}</h2>
                <button @click="openGroup()" class="flex items-center gap-1 rounded-xl bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white shadow-sm hover:brightness-110"><PlusIcon class="h-4 w-4" /> {{ t('exam.new_group', 'مجموعة جديدة') }}</button>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 p-3 sm:p-6">
            <p v-if="$page.props.errors.group" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $page.props.errors.group }}</p>

            <!-- Numbers at a glance -->
            <div class="grid grid-cols-3 gap-2 sm:gap-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs text-gray-500">{{ t('exam.groups_count', 'المجموعات') }}</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums">{{ groups.length }}</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs text-gray-500">{{ t('exam.in_groups', 'طلاب في مجموعات') }}</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums">{{ totalMembers }}</p>
                </div>
                <div class="rounded-2xl border p-3" :class="unassigned ? 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'">
                    <p class="text-xs" :class="unassigned ? 'text-amber-800 dark:text-amber-200' : 'text-gray-500'">{{ t('exam.unassigned', 'بلا مجموعة') }}</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums" :class="unassigned ? 'text-amber-700 dark:text-amber-300' : ''">{{ unassigned }}</p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[18rem_1fr]">
                <!-- The groups: a row of cards on a phone, a list beside on a computer -->
                <aside class="space-y-2">
                    <div v-if="groups.length > 6" class="relative">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-2.5 h-4 w-4 text-gray-400" />
                        <input v-model="groupSearch" :placeholder="t('exam.search_groups', 'ابحث عن مجموعة')" :class="input" class="ps-9" />
                    </div>
                    <div v-if="!groups.length" class="rounded-2xl border border-dashed border-gray-300 p-6 text-center dark:border-gray-600">
                        <UsersIcon class="mx-auto h-10 w-10 text-gray-300" />
                        <p class="mt-2 text-sm text-gray-500">{{ t('exam.groups_empty', 'لا توجد مجموعات بعد. أنشئ مجموعة لكل صف أو شعبة، مثل «المرحلة الثانية — شعبة أ».') }}</p>
                        <button @click="openGroup()" class="mt-3 inline-flex items-center gap-1 rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm text-white"><PlusIcon class="h-4 w-4" /> {{ t('exam.new_group', 'مجموعة جديدة') }}</button>
                    </div>
                    <nav class="-mx-3 flex snap-x gap-2 overflow-x-auto px-3 pb-1 lg:mx-0 lg:block lg:space-y-2 lg:overflow-visible lg:px-0">
                        <Link v-for="g in shownGroups" :key="g.id" :href="route('exams.manage.groups', { group: g.id })" preserve-scroll preserve-state
                            class="group flex w-56 shrink-0 snap-start items-center gap-3 rounded-2xl border p-3 transition lg:w-full"
                            :class="g.id === current ? 'border-[var(--accent-500)] bg-[var(--accent-50)] shadow-sm ring-1 ring-[var(--accent-500)] dark:bg-gray-700' : 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800'">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-bold text-white" :style="{ background: hue(g.id) }">{{ initial(g.name) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold">{{ g.name }}</span>
                                <span class="block truncate text-xs text-gray-500">{{ [g.stage, g.department].filter(Boolean).join(' · ') || '—' }}</span>
                            </span>
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-gray-700 dark:bg-gray-700 dark:text-gray-200" :title="t('exam.students', 'طالب')">{{ g.members }}</span>
                        </Link>
                    </nav>
                </aside>

                <!-- The group chosen -->
                <section v-if="group" class="min-w-0 space-y-3">
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-start gap-3">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-lg font-bold text-white" :style="{ background: hue(group.id) }">{{ initial(group.name) }}</span>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-lg font-bold">{{ group.name }}</h3>
                                <p class="truncate text-sm text-gray-500">{{ [group.stage, group.department].filter(Boolean).join(' · ') }}<span v-if="group.owner"> · {{ group.owner }}</span></p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5 text-xs">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-700"><AcademicCapIcon class="h-3.5 w-3.5" /> {{ t('exam.n_students', ':n طالب', { n: group.members }) }}</span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-700"><ChartBarIcon class="h-3.5 w-3.5" /> {{ t('exam.n_exams', ':n امتحان', { n: group.exams }) }}</span>
                                </div>
                            </div>
                            <div v-if="group.editable" class="relative">
                                <button @click="menu = !menu" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :aria-label="t('common.more', 'المزيد')"><EllipsisVerticalIcon class="h-5 w-5" /></button>
                                <div v-if="menu" class="absolute end-0 z-20 mt-1 w-44 overflow-hidden rounded-xl bg-white py-1 text-sm shadow-lg ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                                    <button @click="openGroup(group)" class="flex w-full items-center gap-2 px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700"><PencilSquareIcon class="h-4 w-4" /> {{ t('action.edit', 'تعديل') }}</button>
                                    <button @click="menu = false; toDelete = group" class="flex w-full items-center gap-2 px-3 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30"><TrashIcon class="h-4 w-4" /> {{ t('action.delete', 'حذف') }}</button>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                            <button v-if="group.editable" @click="openAdd" class="col-span-2 inline-flex items-center justify-center gap-1.5 rounded-xl bg-[var(--accent-600)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:brightness-110 sm:col-span-1"><UserPlusIcon class="h-5 w-5" /> {{ t('exam.add_students_btn', 'إضافة طلاب') }}</button>
                            <Link :href="route('exams.manage.groups.gradebook', current)" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700"><ChartBarIcon class="h-4 w-4" /> {{ t('exam.gradebook', 'كشف الدرجات') }}</Link>
                            <button v-if="group.editable && canImport && members.length" @click="cardsOpen = true" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700"><IdentificationIcon class="h-4 w-4" /> {{ t('exam.login_cards', 'بطاقات دخول') }}</button>
                        </div>
                    </div>

                    <!-- Members -->
                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 p-3 dark:border-gray-700">
                            <label v-if="group.editable && members.length" class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                                <input type="checkbox" :checked="allPicked" @change="toggleAll" class="h-5 w-5 rounded border-gray-300 text-[var(--accent-600)]" />
                                <span class="hidden sm:inline">{{ t('exam.select_all', 'تحديد الكل') }}</span>
                            </label>
                            <div class="relative min-w-0 flex-1">
                                <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-2.5 h-4 w-4 text-gray-400" />
                                <input v-model="memberSearch" :placeholder="t('exam.search_members', 'ابحث في طلاب المجموعة')" :class="input" class="ps-9" />
                            </div>
                        </div>
                        <div v-if="picked.size" class="flex items-center gap-2 bg-[var(--accent-50)] px-3 py-2 text-sm dark:bg-gray-700">
                            <span class="flex-1 font-semibold text-[var(--accent-700)] dark:text-gray-100">{{ t('exam.n_selected', ':n محدد', { n: picked.size }) }}</span>
                            <button @click="removing = members.filter((m) => picked.has(m.id))" class="inline-flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white"><TrashIcon class="h-4 w-4" /> {{ t('exam.remove_from_group', 'إزالة من المجموعة') }}</button>
                            <button @click="picked = new Set()" class="rounded-lg p-1.5 text-gray-500 hover:bg-white/60 dark:hover:bg-gray-600"><XMarkIcon class="h-4 w-4" /></button>
                        </div>
                        <div v-if="!members.length" class="p-8 text-center">
                            <AcademicCapIcon class="mx-auto h-10 w-10 text-gray-300" />
                            <p class="mt-2 text-sm text-gray-500">{{ t('exam.group_no_members', 'لا طلاب في هذه المجموعة بعد.') }}</p>
                            <button v-if="group.editable" @click="openAdd" class="mt-3 inline-flex items-center gap-1 rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm text-white"><UserPlusIcon class="h-4 w-4" /> {{ t('exam.add_students_btn', 'إضافة طلاب') }}</button>
                        </div>
                        <p v-else-if="!shownMembers.length" class="p-6 text-center text-sm text-gray-500">{{ t('library.no_results', 'لا توجد نتائج') }}</p>
                        <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                            <li v-for="m in shownMembers" :key="m.id" class="flex items-center gap-3 px-3 py-2.5" :class="picked.has(m.id) ? 'bg-[var(--accent-50)]/60 dark:bg-gray-700/50' : ''">
                                <input v-if="group.editable" type="checkbox" :checked="picked.has(m.id)" @change="togglePick(m.id)" class="h-5 w-5 shrink-0 rounded border-gray-300 text-[var(--accent-600)]" />
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white" :style="{ background: hue(m.id) }">{{ initial(m.name) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ m.name }}</span>
                                    <span class="block truncate text-xs text-gray-500" dir="ltr">{{ [m.number, m.email].filter(Boolean).join(' · ') }}</span>
                                </span>
                                <button v-if="group.editable" @click="removing = [m]" class="shrink-0 rounded-lg p-2 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" :title="t('exam.remove_from_group', 'إزالة من المجموعة')"><XMarkIcon class="h-5 w-5" /></button>
                            </li>
                        </ul>
                    </div>
                    <p class="text-xs text-gray-500">{{ t('exam.groups_hint2', 'كل طالب في مجموعة واحدة: من تضيفه هنا لا يظهر عند إضافة طلاب للمجموعات الأخرى. لإعطاء امتحان لمجموعة اخترها في إعدادات الامتحان؛ امتحان بلا مجموعات يراه كل الطلاب.') }}</p>
                </section>
            </div>
        </div>

        <!-- Add students -->
        <Modal :show="adding" @close="adding = false" max-width="2xl">
            <div class="flex max-h-[90dvh] flex-col">
                <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                    <UserPlusIcon class="h-5 w-5 text-[var(--accent-600)]" />
                    <h3 class="min-w-0 flex-1 truncate font-semibold">{{ t('exam.add_to', 'إضافة طلاب إلى «:g»', { g: group?.name }) }}</h3>
                    <button @click="adding = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                </div>
                <div class="flex gap-1 border-b border-gray-100 px-3 pt-2 text-sm dark:border-gray-700">
                    <button v-for="tab in [{ k: 'system', l: t('exam.from_system', 'من الطلاب المسجّلين') }, { k: 'text', l: t('exam.by_email', 'بالبريد أو الرقم') }, ...(canImport ? [{ k: 'excel', l: t('exam.new_from_excel', 'حسابات جديدة (Excel)') }] : [])]" :key="tab.k"
                        @click="addTab = tab.k" class="-mb-px whitespace-nowrap border-b-2 px-3 py-2" :class="addTab === tab.k ? 'border-[var(--accent-600)] font-semibold text-[var(--accent-700)] dark:text-gray-100' : 'border-transparent text-gray-500'">{{ tab.l }}</button>
                </div>

                <template v-if="addTab === 'system'">
                    <div class="space-y-2 p-3">
                        <div class="relative">
                            <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-2.5 h-4 w-4 text-gray-400" />
                            <input id="pick-search" v-model="q" :placeholder="t('exam.search_student', 'بحث بالاسم أو الرقم')" :class="input" class="ps-9" />
                            <ArrowPathIcon v-if="loading" class="absolute end-3 top-2.5 h-4 w-4 animate-spin text-gray-400" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-1 py-1">
                                <input type="checkbox" :checked="allFoundChosen" @change="chooseAllFound" :disabled="!found.length" class="h-5 w-5 rounded border-gray-300 text-[var(--accent-600)]" />
                                {{ t('exam.select_all_shown', 'تحديد الكل (:n)', { n: found.length }) }}
                            </label>
                            <span class="text-xs text-gray-500">{{ others ? t('exam.in_other_groups_n', ':n في مجموعات أخرى', { n: total }) : t('exam.without_group_n', ':n بلا مجموعة', { n: total }) }}</span>
                            <label class="ms-auto flex cursor-pointer items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <input v-model="others" type="checkbox" class="rounded border-gray-300" /> <ArrowsRightLeftIcon class="h-4 w-4" /> {{ t('exam.show_other_groups', 'طلاب المجموعات الأخرى (للنقل)') }}
                            </label>
                        </div>
                    </div>
                    <ul class="min-h-[12rem] flex-1 divide-y divide-gray-100 overflow-y-auto border-y border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                        <li v-for="s in found" :key="s.id">
                            <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50" :class="chosen.has(s.id) ? 'bg-[var(--accent-50)]/70 dark:bg-gray-700/60' : ''">
                                <input type="checkbox" :checked="chosen.has(s.id)" @change="toggleChosen(s)" class="h-5 w-5 shrink-0 rounded border-gray-300 text-[var(--accent-600)]" />
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white" :style="{ background: hue(s.id) }">{{ initial(s.name) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ s.name }}</span>
                                    <span class="block truncate text-xs text-gray-500" dir="ltr">{{ [s.number, s.email].filter(Boolean).join(' · ') }}</span>
                                </span>
                                <span v-if="s.group" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ s.group }}</span>
                            </label>
                        </li>
                        <li v-if="!found.length && !loading" class="p-8 text-center text-sm text-gray-500">
                            {{ q ? t('library.no_results', 'لا توجد نتائج') : others ? t('exam.no_other_group_students', 'لا طلاب في مجموعات أخرى.') : t('exam.all_have_groups', 'كل الطلاب المسجّلين في مجموعات. فعّل «طلاب المجموعات الأخرى» للنقل، أو أضف حسابات جديدة.') }}
                        </li>
                        <li v-if="found.length < total" class="p-2 text-center">
                            <button @click="load(true)" :disabled="loading" class="rounded-lg px-4 py-2 text-sm text-[var(--accent-600)] hover:bg-gray-50 dark:hover:bg-gray-700">{{ t('exam.load_more', 'عرض المزيد (:n)', { n: total - found.length }) }}</button>
                        </li>
                    </ul>
                    <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                        <p class="min-w-0 flex-1 text-sm">
                            <span class="font-semibold">{{ t('exam.n_selected', ':n محدد', { n: chosen.size }) }}</span>
                            <span v-if="moving" class="block text-xs text-amber-700 dark:text-amber-300">{{ t('exam.will_move', ':n منهم سيُنقل من مجموعته إلى هذه المجموعة.', { n: moving }) }}</span>
                        </p>
                        <button @click="adding = false" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                        <button @click="addChosen" :disabled="!chosen.size || addForm.processing" class="inline-flex items-center gap-1 rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-40"><CheckIcon class="h-4 w-4" /> {{ moving ? t('exam.add_and_move', 'أضف وانقل (:n)', { n: chosen.size }) : t('exam.add_n', 'أضف (:n)', { n: chosen.size }) }}</button>
                    </div>
                </template>

                <form v-else-if="addTab === 'text'" @submit.prevent="addByText" class="space-y-3 p-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.emails_hint', 'الصق بريد أو رقم جامعي لكل طالب في سطر. من هو في مجموعة أخرى لا يُضاف إلا إذا اخترت نقله.') }}</p>
                    <textarea v-model="memberForm.emails" rows="7" dir="ltr" :placeholder="t('exam.emails_ph', 'بريد أو رقم جامعي في كل سطر')" :class="input" />
                    <p v-if="memberForm.errors.emails" class="text-xs text-red-600">{{ memberForm.errors.emails }}</p>
                    <label class="flex items-center gap-2 text-sm"><input v-model="memberForm.move" type="checkbox" class="rounded border-gray-300" /> {{ t('exam.move_from_others', 'انقل من هو في مجموعة أخرى إلى هذه المجموعة') }}</label>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="adding = false" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                        <button :disabled="memberForm.processing || !memberForm.emails.trim()" class="rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-40">{{ t('exam.add', 'إضافة') }}</button>
                    </div>
                </form>

                <div v-else class="space-y-3 p-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.import_into_group_hint', 'أعمدة: الاسم، البريد أو الرقم الجامعي، كلمة المرور (اختياري). من له حساب يُضاف فقط. يُنزَّل ملف بكلمات المرور الجديدة.') }}</p>
                    <label class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-gray-300 p-6 text-center text-sm hover:border-[var(--accent-500)] dark:border-gray-600">
                        <ArrowUpTrayIcon class="h-8 w-8 text-gray-400" />
                        <span>{{ importFile ? importFile.name : t('exam.choose_sheet', 'اختر ملف Excel أو CSV') }}</span>
                        <input type="file" accept=".xlsx,.csv" @input="importFile = $event.target.files[0]" class="sr-only" />
                    </label>
                    <p v-if="importError" class="text-xs text-red-600">{{ importError }}</p>
                    <div class="flex justify-end gap-2">
                        <button @click="adding = false" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                        <button @click="importStudents" :disabled="!importFile || importing" class="inline-flex items-center gap-1 rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-40"><ArrowUpTrayIcon class="h-4 w-4" /> {{ t('exam.import', 'استيراد') }}</button>
                    </div>
                </div>
            </div>
        </Modal>

        <Modal :show="!!editing" @close="editing = null" max-width="md">
            <form @submit.prevent="saveGroup" class="space-y-3 p-6">
                <h3 class="text-lg font-semibold">{{ editing?.id ? t('exam.edit_group', 'تعديل المجموعة') : t('exam.new_group', 'مجموعة جديدة') }}</h3>
                <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.group_name', 'الاسم') }}</span><input v-model="groupForm.name" :placeholder="t('exam.group_name_ph', 'مثل: المرحلة الثانية — شعبة أ')" :class="input" />
                    <span v-if="groupForm.errors.name" class="text-xs text-red-600">{{ groupForm.errors.name }}</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.stage', 'المرحلة / الصف') }}</span><input v-model="groupForm.stage" :class="input" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.department', 'القسم') }}</span><input v-model="groupForm.department" :class="input" /></label>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="editing = null" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="groupForm.processing" class="rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
                </div>
            </form>
        </Modal>
        <Modal :show="!!removing" @close="removing = null" max-width="sm">
            <div class="space-y-4 p-6">
                <p class="text-sm">{{ removing?.length === 1 ? t('exam.remove_one_q', 'إزالة «:n» من المجموعة؟ يبقى حسابه، ويظهر مرة أخرى عند إضافة طلاب لأي مجموعة.', { n: removing[0].name }) : t('exam.remove_many_q', 'إزالة :n طالباً من المجموعة؟ تبقى حساباتهم.', { n: removing?.length }) }}</p>
                <div class="flex justify-end gap-2">
                    <button @click="removing = null" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="removePicked" class="rounded-xl bg-red-600 px-4 py-2 text-sm text-white">{{ t('exam.remove_from_group', 'إزالة من المجموعة') }}</button>
                </div>
            </div>
        </Modal>
        <Modal :show="cardsOpen" @close="cardsOpen = false" max-width="sm">
            <div class="space-y-4 p-6">
                <p class="text-sm">{{ t('exam.cards_q', 'يأخذ كل طالب في المجموعة كلمة مرور جديدة تُطبع على بطاقته، وتتوقف كلمات مرورهم الحالية. تُعرض البطاقات مرة واحدة فقط. متابعة؟') }}</p>
                <div class="flex justify-end gap-2">
                    <button @click="cardsOpen = false" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="printCards" class="rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('exam.make_cards', 'إنشاء البطاقات') }}</button>
                </div>
            </div>
        </Modal>
        <Modal :show="!!toDelete" @close="toDelete = null" max-width="sm">
            <div class="space-y-4 p-6">
                <p class="text-sm">{{ t('exam.delete_group_q', 'حذف المجموعة «:n»؟ يبقى الطلاب وحساباتهم.', { n: toDelete?.name }) }}</p>
                <div class="flex justify-end gap-2">
                    <button @click="toDelete = null" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="removeGroup" class="rounded-xl bg-red-600 px-4 py-2 text-sm text-white">{{ t('action.delete', 'حذف') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
