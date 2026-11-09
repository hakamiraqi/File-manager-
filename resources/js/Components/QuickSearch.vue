<script setup>
// «بحث سريع» (2026-11-09, by request): Ctrl+K (or «/», or the magnifier in the top bar) from any
// page: the pages of the menu, exams, students, groups and hall computers. Arrows and Enter.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { MagnifyingGlassIcon, ClipboardDocumentListIcon, AcademicCapIcon, UserGroupIcon, ComputerDesktopIcon, ArrowTurnDownLeftIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const page = usePage();
const open = ref(false);
const q = ref('');
const results = ref([]);
const loading = ref(false);
const active = ref(0);
const input = ref(null);

// The menu's pages, found by their names without asking the server.
const pages = computed(() => {
    const p = page.props.permissions ?? {};
    const list = [
        { title: t('nav.exams', 'امتحاناتي'), url: route('exams.index') },
        { title: t('nav.profile', 'الملف الشخصي'), url: route('profile.edit') },
    ];
    if (p.manage_exams) {
        list.push(
            { title: t('nav.exams_manage', 'إدارة الامتحانات'), url: route('exams.manage.index') },
            { title: t('exam.bank', 'بنك الأسئلة'), url: route('exams.manage.bank') },
            { title: t('exam.groups', 'الصفوف والمجموعات'), url: route('exams.manage.groups') },
            { title: t('exam.schedule', 'جدول الامتحانات'), url: route('exams.manage.schedule') },
        );
    }
    if (p.invigilate) {
        list.push(
            { title: t('exam.monitor', 'المراقبة'), url: route('exams.monitor.index') },
            { title: t('hallmap.title', 'خريطة القاعات'), url: route('exams.monitor.halls') },
        );
        if (route().has('exams.monitor.today')) list.push({ title: t('today.title', 'اليوم'), url: route('exams.monitor.today') });
    }
    if (p.is_admin) list.push({ title: t('nav.admin', 'لوحة الإدارة'), url: route('admin.dashboard') });
    return list.map((x) => ({ ...x, kind: 'page' }));
});
const norm = (s) => String(s ?? '').toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي');
const shown = computed(() => {
    const n = norm(q.value.trim());
    const pg = n ? pages.value.filter((p) => norm(p.title).includes(n)) : pages.value;
    return [...pg.slice(0, n ? 4 : 8), ...results.value];
});
watch(shown, () => (active.value = 0));

let timer = null;
let seq = 0;
watch(q, (v) => {
    clearTimeout(timer);
    if (v.trim().length < 2) { results.value = []; loading.value = false; return; }
    loading.value = true;
    timer = setTimeout(async () => {
        const mine = ++seq;
        try {
            const r = (await window.axios.get(route('search'), { params: { q: v.trim() } })).data;
            if (mine === seq) results.value = r.results ?? [];
        } catch { if (mine === seq) results.value = []; }
        if (mine === seq) loading.value = false;
    }, 220);
});
function show() {
    open.value = true;
    q.value = '';
    results.value = [];
    nextTick(() => input.value?.focus());
}
function go(item) {
    if (!item) return;
    open.value = false;
    router.visit(item.url);
}
function onKey(e) {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K' || e.key === 'ن')) { e.preventDefault(); open.value ? (open.value = false) : show(); return; }
    if (!open.value && e.key === '/' && !typing) { e.preventDefault(); show(); return; }
    if (!open.value) return;
    if (e.key === 'Escape') open.value = false;
    else if (e.key === 'ArrowDown') { e.preventDefault(); active.value = Math.min(shown.value.length - 1, active.value + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = Math.max(0, active.value - 1); }
    else if (e.key === 'Enter') { e.preventDefault(); go(shown.value[active.value]); }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
const ICONS = { page: DocumentTextIcon, exam: ClipboardDocumentListIcon, student: AcademicCapIcon, group: UserGroupIcon, computer: ComputerDesktopIcon };
const KINDS = { page: () => t('search.page', 'صفحة'), exam: () => t('search.exam', 'امتحان'), student: () => t('search.student', 'طالب'), group: () => t('search.group', 'مجموعة'), computer: () => t('search.computer', 'حاسبة') };
const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
    <button type="button" @click="show" class="flex items-center gap-2 rounded-lg p-2 text-gray-600 hover:bg-gray-100 md:min-w-[11rem] md:border md:border-gray-200 md:bg-gray-50 md:px-3 md:py-1.5 dark:text-gray-300 dark:hover:bg-gray-700 md:dark:border-gray-600 md:dark:bg-gray-700/50" :aria-label="t('search.title', 'بحث سريع')">
        <MagnifyingGlassIcon class="h-5 w-5 md:h-4 md:w-4" />
        <span class="hidden flex-1 text-start text-sm text-gray-400 md:inline">{{ t('search.short', 'بحث…') }}</span>
        <kbd class="hidden rounded border border-gray-300 px-1.5 text-[10px] text-gray-500 md:inline dark:border-gray-500" dir="ltr">{{ mac ? '⌘' : 'Ctrl' }} K</kbd>
    </button>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[70] flex items-start justify-center bg-black/40 p-3 pt-[10vh] backdrop-blur-sm" @click.self="open = false">
            <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="flex items-center gap-2 border-b border-gray-100 px-4 dark:border-gray-700">
                    <MagnifyingGlassIcon class="h-5 w-5 shrink-0 text-gray-400" />
                    <input ref="input" v-model="q" :placeholder="t('search.ph', 'ابحث عن امتحان، طالب، مجموعة، حاسبة أو صفحة')" class="min-w-0 flex-1 border-0 bg-transparent py-4 text-base focus:ring-0 dark:text-gray-100" />
                    <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-gray-300 border-t-[var(--accent-600)]" />
                    <kbd class="rounded border border-gray-300 px-1.5 text-[10px] text-gray-500 dark:border-gray-500">Esc</kbd>
                </div>
                <ul class="max-h-[60vh] overflow-y-auto py-1">
                    <li v-for="(r, i) in shown" :key="r.kind + r.url">
                        <button type="button" @click="go(r)" @mousemove="active = i" class="flex w-full items-center gap-3 px-4 py-2.5 text-start" :class="i === active ? 'bg-[var(--accent-50)] dark:bg-gray-700' : ''">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"><component :is="ICONS[r.kind]" class="h-5 w-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold" dir="auto">{{ r.title }}</span>
                                <span v-if="r.sub" class="block truncate text-xs text-gray-500" dir="auto">{{ r.sub }}</span>
                            </span>
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ KINDS[r.kind]() }}</span>
                            <ArrowTurnDownLeftIcon v-if="i === active" class="hidden h-4 w-4 text-gray-400 sm:block" />
                        </button>
                    </li>
                    <li v-if="!shown.length && !loading" class="px-4 py-8 text-center text-sm text-gray-500">{{ t('library.no_results', 'لا توجد نتائج') }}</li>
                </ul>
                <p class="hidden border-t border-gray-100 px-4 py-2 text-[11px] text-gray-400 sm:block dark:border-gray-700">{{ t('search.keys', '↑↓ للتنقل · Enter للفتح · / أو Ctrl+K من أي صفحة') }}</p>
            </div>
        </div>
    </Teleport>
</template>
